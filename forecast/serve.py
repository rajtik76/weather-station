"""The forecast service: readings in, 1-6 hour forecast out.

    uv run serve.py   # listens on $HOST:$PORT (0.0.0.0:8000), model from $MODEL_PATH

Stateless and without a database: Laravel sends the station's recent
readings (up to ~60 days, so the station correction has history to learn
from) and stores what comes back. Only reachable on the internal Docker
network, hence no authentication.

POST /forecast
    {"longitude": 13.40,
     "readings": [{"timestamp": 1790000000, "temperature": 9.7,
                   "humidity": 76.0, "pressure": 976.6, "rain": null}, ...]}
    temperature °C, humidity %, pressure hPa (station level), rain mm in
    the 10 minutes (null or absent when the station has no rain source).

    -> {"issued_at": 1790000000, "model": "<trained_at>", "corrected": true,
        "horizons": [{"hours": 1,
                      "temperature": {"low": .., "mid": .., "high": ..},
                      "humidity": {...}, "pressure": {...},
                      "rain_probability": 0.02,
                      "base": {"temperature": {...}, "humidity": {...}}}, ...]}
    issued_at is the latest reading's 10-minute window (UTC Unix seconds);
    base is the forecast before the station correction, for the variables
    it corrects, so the correction's worth can be scored.

POST /base
    {"longitude": 13.40, "since": 1789400000, "readings": [...]}
    -> {"model": "<trained_at>",
        "forecasts": [{"issued_at": 1789400000,
                       "horizons": [{"hours": 1, "temperature": {...},
                                     "humidity": {...}}, ...]}, ...]}
    The forecast before correction for every reading from since on, to fill
    in base for forecasts stored before it was kept. The base models look
    48 hours back, so the readings should start that much before since.

GET /health -> {"status": "ok", "model": "<trained_at>"}
"""

import json
import math
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import joblib
import pandas as pd

from correction import CORRECTED_VARIABLES, apply, fit
from features import build_features, to_grid
from forecast import QUANTILES, predict

MODEL_PATH = Path(os.environ.get("MODEL_PATH", Path(__file__).parent / "models" / "forecast.joblib"))
HOST = os.environ.get("HOST", "0.0.0.0")
PORT = int(os.environ.get("PORT", "8000"))
MAX_READINGS = 60 * 24 * 6

FIELDS = {"temperature": "T", "humidity": "H", "pressure": "P"}
NAMES = {column: field for field, column in FIELDS.items()}


class Model:
    """The bundle on disk, reloaded when a retrained file replaces it."""

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


def number(value: object) -> float:
    if value is None:
        return math.nan
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise InvalidRequest("readings values must be numbers or null")
    return float(value)


def readings_frame(readings: object) -> pd.DataFrame:
    if not isinstance(readings, list) or not readings:
        raise InvalidRequest("readings must be a non-empty list")
    if len(readings) > MAX_READINGS:
        raise InvalidRequest(f"at most {MAX_READINGS} readings")
    rows = []
    for reading in readings:
        if not isinstance(reading, dict) or not isinstance(reading.get("timestamp"), int):
            raise InvalidRequest("every reading needs an integer timestamp")
        row = {column: number(reading.get(field)) for field, column in FIELDS.items()}
        row["SRA10M"] = number(reading.get("rain"))
        row["time"] = reading["timestamp"]
        rows.append(row)
    frame = pd.DataFrame(rows)
    frame.index = pd.to_datetime(frame.pop("time"), unit="s", utc=True)
    return to_grid(frame.sort_index())


def make_forecast(payload: dict) -> dict:
    longitude = number(payload.get("longitude"))
    if math.isnan(longitude):
        raise InvalidRequest("longitude is required")
    current = readings_frame(payload.get("readings"))
    if current[["T", "H", "P"]].iloc[-1].isna().any():
        raise InvalidRequest("the latest reading needs temperature, humidity and pressure")
    if current["SRA10M"].isna().all():
        current = current.drop(columns="SRA10M")

    bundle = model.get()
    horizons = bundle["horizons"]
    features = build_features(current, longitude)
    forecast = predict(bundle, features, current)
    # The latest row is the one forecast; every earlier one teaches the correction.
    corrections = fit(forecast.iloc[:-1], current.iloc[:-1], horizons, longitude)
    latest = apply(corrections, forecast, current, horizons, longitude).iloc[-1]

    return {
        "issued_at": int(latest.name.timestamp()),
        "model": bundle["trained_at"],
        "corrected": bool(corrections),
        "horizons": [
            {
                "hours": n,
                "temperature": band(latest, "T", n),
                "humidity": band(latest, "H", n),
                "pressure": band(latest, "P", n),
                "rain_probability": round(float(latest[f"rain_{n}h"]), 3),
                "base": base_bands(forecast.iloc[-1], n),
            }
            for n in horizons
        ],
    }


def make_base(payload: dict) -> dict:
    longitude = number(payload.get("longitude"))
    if math.isnan(longitude):
        raise InvalidRequest("longitude is required")
    since = payload.get("since")
    if isinstance(since, bool) or not isinstance(since, int):
        raise InvalidRequest("since must be an integer timestamp")
    current = readings_frame(payload.get("readings"))
    if current["SRA10M"].isna().all():
        current = current.drop(columns="SRA10M")

    bundle = model.get()
    forecast = predict(bundle, build_features(current, longitude), current)
    # A forecast is issued only from a window with all three readings.
    issued = (forecast.index >= pd.Timestamp(since, unit="s", tz="UTC")) & current[["T", "H", "P"]].notna().all(axis=1)

    return {
        "model": bundle["trained_at"],
        "forecasts": [
            {
                "issued_at": int(time.timestamp()),
                "horizons": [{"hours": n, **base_bands(row, n)} for n in bundle["horizons"]],
            }
            for time, row in forecast[issued].iterrows()
        ],
    }


def band(row: pd.Series, variable: str, n: int) -> dict:
    return {name: round(float(row[f"{variable}_{n}h_{name}"]), 2) for name in QUANTILES}


def base_bands(row: pd.Series, n: int) -> dict:
    """The uncorrected forecast of the variables the station correction touches."""
    return {NAMES[variable]: band(row, variable, n) for variable in CORRECTED_VARIABLES}


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        if self.path != "/health":
            return self.reply(404, {"error": "not found"})
        self.reply(200, {"status": "ok", "model": model.get()["trained_at"]})

    def do_POST(self) -> None:
        endpoints = {"/forecast": make_forecast, "/base": make_base}
        if self.path not in endpoints:
            return self.reply(404, {"error": "not found"})
        try:
            length = int(self.headers.get("Content-Length", 0))
            payload = json.loads(self.rfile.read(length))
            if not isinstance(payload, dict):
                raise InvalidRequest("body must be a JSON object")
            self.reply(200, endpoints[self.path](payload))
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
