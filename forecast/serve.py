"""Forecast service (internal network, no auth, stateless): uv run serve.py ($HOST:$PORT, model from $MODEL_PATH).

Readings: {"timestamp": unix s, "temperature": °C, "humidity": %, "pressure": hPa (station level),
"rain": mm per 10 min or null}. Bands: {"low", "mid", "high"}.

POST /correction  {"longitude", "readings", "since"?}
  -> {"model": trained_at, "correction": CORRECTION_VERSION,
      "targets": {"T_1h": {"intercept", "coefficients": {input: weight}, "widen"}, ...}}
  "since" (unix s UTC): only readings from then on teach it. Short history: fewer or no targets.
POST /forecast  {"longitude", "readings", "correction"?}
  -> {"issued_at": unix s of the latest 10-min window, "model": trained_at, "corrected": bool,
      "correction": CORRECTION_VERSION,
      "horizons": [{"hours": n, "temperature": band, "humidity": band, "pressure": band,
                    "rain_probability",
                    "base": {"temperature": band, "humidity": band, "rain_probability": raw, pre nest_rain()}}]}
  readings: 54 h before the latest suffice (48 h of features behind the forecast issued 6 h earlier,
  whose verified error the correction reads). "correction": a /correction answer; another model or version -> 409.
POST /base  {"longitude", "since" (required), "readings", "full"?: bool}
  -> {"model", "forecasts": [{"issued_at", "horizons": [{"hours", "temperature", "humidity",
      "rain_probability"}]}]}  - uncorrected, per reading from since on; readings should start 48 h earlier.
  "full": true gives /forecast's horizon shape without base.
GET /health -> {"status": "ok", "model", "correction"}
"""
import json
import math
import os
from dataclasses import asdict
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import joblib
import pandas as pd

from correction import CORRECTED_VARIABLES, CORRECTION_VERSION, INPUTS, Correction, apply, fit
from features import build_features, to_grid
from forecast import QUANTILES, predict

MODEL_PATH = Path(os.environ.get("MODEL_PATH", Path(__file__).parent / "models" / "forecast.joblib"))
HOST = os.environ.get("HOST", "0.0.0.0")
PORT = int(os.environ.get("PORT", "8000"))
# Caps stray timestamps: the history is snapped onto a 10-minute grid, so one decades off would allocate millions of slots.
MAX_SPAN_DAYS = 366
MAX_SPAN_SECONDS = MAX_SPAN_DAYS * 86_400
MAX_READINGS = MAX_SPAN_DAYS * 24 * 6

FIELDS = {"temperature": "T", "humidity": "H", "pressure": "P"}
NAMES = {column: field for field, column in FIELDS.items()}


class Model:
    """The bundle on disk, reloaded when the file changes."""

    def __init__(self, path: Path) -> None:
        self.path = path
        self.loaded_mtime = 0.0
        self.bundle: dict = {}

    def get(self) -> dict:
        mtime = self.path.stat().st_mtime
        if mtime != self.loaded_mtime:
            self.bundle = joblib.load(self.path)
            self.loaded_mtime = mtime
        return self.bundle


model = Model(MODEL_PATH)


class InvalidRequest(ValueError):
    pass


class StaleCorrection(ValueError):
    pass


def epoch(value: object, name: str) -> int:
    if isinstance(value, bool) or not isinstance(value, int):
        raise InvalidRequest(f"{name} must be an integer")
    return value


def timestamp(value: object, name: str, required: bool) -> pd.Timestamp | None:
    if value is None and not required:
        return None
    value = epoch(value, name)
    try:
        return pd.Timestamp(value, unit="s", tz="UTC")
    except (OverflowError, pd.errors.OutOfBoundsDatetime):
        raise InvalidRequest(f"{name} is out of range") from None


def number(value: object) -> float:
    if value is None:
        return math.nan
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise InvalidRequest("readings values must be numbers or null")
    # Huge ints overflow float; JSON 1e400 parses as inf.
    try:
        result = float(value)
    except OverflowError:
        result = math.inf
    if not math.isfinite(result):
        raise InvalidRequest("readings values must be finite numbers or null")
    return result


def readings_frame(readings: object) -> pd.DataFrame:
    if not isinstance(readings, list) or not readings:
        raise InvalidRequest("readings must be a non-empty list")
    if len(readings) > MAX_READINGS:
        raise InvalidRequest(f"at most {MAX_READINGS} readings")
    rows = []
    for reading in readings:
        row = {"time": epoch(reading.get("timestamp") if isinstance(reading, dict) else None, "a reading's timestamp")}
        row |= {column: number(reading.get(field)) for field, column in FIELDS.items()}
        row["SRA10M"] = number(reading.get("rain"))
        rows.append(row)
    oldest = min(row["time"] for row in rows)
    newest = max(row["time"] for row in rows)
    timestamp(oldest, "a reading's timestamp", required=True)
    timestamp(newest, "a reading's timestamp", required=True)
    if newest - oldest > MAX_SPAN_SECONDS:
        raise InvalidRequest(f"readings must span at most {MAX_SPAN_DAYS} days")
    frame = pd.DataFrame(rows)
    frame.index = pd.to_datetime(frame.pop("time"), unit="s", utc=True)
    return to_grid(frame.sort_index())


def station_history(payload: dict) -> tuple[float, pd.DataFrame]:
    """Longitude and gridded readings; without a rain source the column is dropped so rain inputs are NaN, not zero."""
    longitude = number(payload.get("longitude"))
    if math.isnan(longitude):
        raise InvalidRequest("longitude is required")
    current = readings_frame(payload.get("readings"))
    if current["SRA10M"].isna().all():
        current = current.drop(columns="SRA10M")
    return longitude, current


def make_correction(payload: dict) -> dict:
    longitude, current = station_history(payload)
    since = timestamp(payload.get("since"), "since", required=False)

    bundle = model.get()
    forecast = predict(bundle, build_features(current, longitude), current)
    corrections = fit(forecast, current, bundle["horizons"], longitude, since)

    return {
        "model": bundle["trained_at"],
        "correction": CORRECTION_VERSION,
        "targets": {target: asdict(correction) for target, correction in corrections.items()},
    }


def make_forecast(payload: dict) -> dict:
    longitude, current = station_history(payload)
    if current[["T", "H", "P"]].iloc[-1].isna().any():
        raise InvalidRequest("the latest reading needs temperature, humidity and pressure")

    bundle = model.get()
    horizons = bundle["horizons"]
    corrections = station_correction(payload.get("correction"), bundle)
    features = build_features(current, longitude)
    forecast = predict(bundle, features, current)
    latest = apply(corrections, forecast, current, horizons, longitude).iloc[-1]

    return {
        "issued_at": int(latest.name.timestamp()),
        "model": bundle["trained_at"],
        # True only if every target was corrected (sparse history can skip some).
        "corrected": len(corrections) == len(horizons) * len(CORRECTED_VARIABLES),
        "correction": CORRECTION_VERSION,
        "horizons": [
            {"hours": n, **shown_bands(latest, n), "base": base_bands(forecast.iloc[-1], n)}
            for n in horizons
        ],
    }


def station_correction(value: object, bundle: dict) -> dict[str, Correction]:
    """A /correction answer back as Corrections; none without one."""
    if value is None:
        return {}
    if not isinstance(value, dict) or not isinstance(value.get("targets"), dict):
        raise InvalidRequest("correction must be an answer of /correction")
    if value.get("model") != bundle["trained_at"] or value.get("correction") != CORRECTION_VERSION:
        raise StaleCorrection("correction was fitted for another model or correction version")
    known = {f"{variable}_{n}h" for n in bundle["horizons"] for variable in CORRECTED_VARIABLES}
    return {target: correction_of(target, fitted, known) for target, fitted in value["targets"].items()}


def correction_of(target: str, fitted: object, known: set[str]) -> Correction:
    if target not in known:
        raise InvalidRequest(f"correction target {target} is unknown")
    if not isinstance(fitted, dict) or not isinstance(fitted.get("coefficients"), dict):
        raise InvalidRequest(f"correction target {target} needs intercept, coefficients and widen")
    if not set(fitted["coefficients"]) <= set(INPUTS):
        raise InvalidRequest(f"correction target {target} has unknown inputs")
    return Correction(
        intercept=finite(fitted.get("intercept"), f"{target} intercept"),
        coefficients={name: finite(weight, f"{target} {name}") for name, weight in fitted["coefficients"].items()},
        widen=finite(fitted.get("widen"), f"{target} widen"),
    )


def finite(value: object, name: str) -> float:
    if not isinstance(value, bool) and isinstance(value, (int, float)):
        try:
            if math.isfinite(result := float(value)):
                return result
        except OverflowError:
            pass
    raise InvalidRequest(f"{name} must be a finite number")


def make_base(payload: dict) -> dict:
    longitude, current = station_history(payload)
    since = timestamp(payload.get("since"), "since", required=True)
    full = payload.get("full", False)
    if not isinstance(full, bool):
        raise InvalidRequest("full must be true or false")

    bundle = model.get()
    forecast = predict(bundle, build_features(current, longitude), current)
    # A forecast is issued only from a window with all three readings.
    issued = (forecast.index >= since) & current[["T", "H", "P"]].notna().all(axis=1)

    return {
        "model": bundle["trained_at"],
        "forecasts": [
            {
                "issued_at": int(time.timestamp()),
                "horizons": [{"hours": n, **(shown_bands(row, n) if full else base_bands(row, n))} for n in bundle["horizons"]],
            }
            for time, row in forecast[issued].iterrows()
        ],
    }


def band(row: pd.Series, variable: str, n: int) -> dict:
    return {name: round(float(row[f"{variable}_{n}h_{name}"]), 2) for name in QUANTILES}


def shown_bands(row: pd.Series, n: int) -> dict:
    """All bands and the shown rain chance (corrected row in /forecast, model's own in /base full)."""
    return {
        **{NAMES[variable]: band(row, variable, n) for variable in FIELDS.values()},
        "rain_probability": round(float(row[f"rain_{n}h"]), 3),
    }


def base_bands(row: pd.Series, n: int) -> dict:
    """Uncorrected bands of the corrected variables, rain chance before nest_rain()."""
    return {
        **{NAMES[variable]: band(row, variable, n) for variable in CORRECTED_VARIABLES},
        "rain_probability": round(float(row[f"rain_{n}h_raw"]), 3),
    }


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        if self.path != "/health":
            return self.reply(404, {"error": "not found"})
        self.reply(200, {"status": "ok", "model": model.get()["trained_at"], "correction": CORRECTION_VERSION})

    def do_POST(self) -> None:
        endpoints = {"/correction": make_correction, "/forecast": make_forecast, "/base": make_base}
        if self.path not in endpoints:
            return self.reply(404, {"error": "not found"})
        try:
            length = int(self.headers.get("Content-Length", 0))
            payload = json.loads(self.rfile.read(length))
            if not isinstance(payload, dict):
                raise InvalidRequest("body must be a JSON object")
            self.reply(200, endpoints[self.path](payload))
        except StaleCorrection as error:
            self.reply(409, {"error": str(error)})
        except (json.JSONDecodeError, InvalidRequest) as error:
            self.reply(422, {"error": str(error)})

    def reply(self, status: int, body: dict) -> None:
        encoded = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)


if __name__ == "__main__":
    model.get()
    print(f"forecast service on :{PORT}, model {model.bundle['trained_at']}", flush=True)
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
