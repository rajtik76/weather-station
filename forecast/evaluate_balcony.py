"""Score the trained models on the balcony station's own record.

    uv run evaluate_balcony.py

Reads data/balcony.csv (timestamp, t, h, p exported from production) and
takes rain from the ČHMÚ gauge at Plzeň-Mikulka, ~3.5 km away, since the
balcony has no rain gauge: as labels, and as the stand-in for the
microphone's rain detector in the "rain in the last hours" inputs. Every 10-minute reading is scored, against
the same baselines as in training.
"""

import json
import urllib.request
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from sklearn.metrics import brier_score_loss, roc_auc_score

from features import HORIZONS, build_features, build_targets, to_grid
from fetch_chmi import GOOD_QUALITY
from correction import apply, fit
from forecast import QUANTILES, VARIABLES, predict

DATA = Path(__file__).parent / "data"
BALCONY_LONGITUDE = 13.40
# The correction learns on the days before, and is scored on the days from.
CORRECTION_SPLIT = pd.Timestamp("2026-09-19", tz="UTC")
RECENT = "https://opendata.chmi.cz/meteorology/climate/recent/data/10min"
MIKULKA = "0-20000-0-11450"


def load_balcony() -> pd.DataFrame:
    readings = pd.read_csv(DATA / "balcony.csv")
    readings.index = pd.to_datetime(readings["timestamp"], unit="s", utc=True)
    return to_grid(readings.rename(columns={"t": "T", "h": "H", "p": "P"})[["T", "H", "P"]])


def load_mikulka_rain(days: pd.DatetimeIndex) -> pd.Series:
    values = []
    for day in days:
        url = f"{RECENT}/10m-{MIKULKA}-{day:%Y%m%d}.json"
        try:
            with urllib.request.urlopen(url, timeout=60) as response:
                values += json.load(response)["data"]["data"]["values"]
        except OSError:
            print(f"  no Mikulka file for {day:%Y-%m-%d}")
    frame = pd.DataFrame(values, columns=["STATION", "ELEMENT", "DT", "VAL", "FLAG", "QUALITY"])
    frame = frame[(frame["ELEMENT"] == "SRA10M") & frame["QUALITY"].isin(GOOD_QUALITY)]
    return frame.set_index(pd.to_datetime(frame["DT"], utc=True))["VAL"].astype("float32")


def main() -> None:
    bundle = joblib.load(Path(__file__).parent / "models" / "forecast.joblib")
    balcony = load_balcony()
    days = pd.date_range(balcony.index.min().normalize(), balcony.index.max().normalize(), freq="D")
    balcony["SRA10M"] = load_mikulka_rain(days).reindex(balcony.index)

    features = build_features(balcony, BALCONY_LONGITUDE)
    usable = features["T"].notna() & features["H"].notna()
    features, current = features[usable], balcony[usable]
    forecast = predict(bundle, features, current)
    targets = build_targets(balcony)[usable]
    print(f"{usable.sum()} readings from {balcony.index.min():%Y-%m-%d} to {balcony.index.max():%Y-%m-%d}")

    report = []
    for n in HORIZONS:
        for variable in VARIABLES:
            change = targets[f"{variable}_{n}h"]
            rows = change.notna()
            truth = (current[variable] + change)[rows]
            low, mid, high = (forecast.loc[rows, f"{variable}_{n}h_{name}"] for name in QUANTILES)
            report.append({
                "target": f"{variable}_{n}h",
                "n": rows.sum(),
                "model_mae": (mid - truth).abs().mean(),
                "persistence_mae": change[rows].abs().mean(),
                "range_hit": ((truth >= low) & (truth <= high)).mean(),
                "range_width": (high - low).mean(),
            })
        target = f"rain_{n}h"
        rows = targets[target].notna()
        labels, chance = targets.loc[rows, target], forecast.loc[rows, target]
        base_rate = bundle["report"][target]["base_rate"]
        dry = features.loc[rows, "rain_past1h"] == 0
        report.append({
            "target": target,
            "n": rows.sum(),
            "rainy": int(labels.sum()),
            "model_brier": brier_score_loss(labels, chance),
            "climatology_brier": brier_score_loss(labels, np.full(len(labels), base_rate)),
            "auc": roc_auc_score(labels, chance) if labels.nunique() == 2 else np.nan,
            "onset_auc": roc_auc_score(labels[dry], chance[dry]) if labels[dry].nunique() == 2 else np.nan,
        })

    pd.set_option("display.width", 160)
    print(pd.DataFrame(report).set_index("target").round(3).to_string())

    evaluate_correction(bundle, balcony, build_features(balcony, BALCONY_LONGITUDE))


def evaluate_correction(bundle: dict, balcony: pd.DataFrame, features: pd.DataFrame) -> None:
    """Fit the station correction before CORRECTION_SPLIT, score it after."""
    forecast = predict(bundle, features, balcony)
    before = forecast.index < CORRECTION_SPLIT
    corrections = fit(forecast[before], balcony[before], bundle["horizons"], BALCONY_LONGITUDE)
    corrected = apply(corrections, forecast, balcony, bundle["horizons"], BALCONY_LONGITUDE)

    report = []
    for n in bundle["horizons"]:
        for variable in VARIABLES:
            truth = balcony[variable].shift(-n * 6)
            rows = ~before & truth.notna() & forecast[f"{variable}_{n}h_mid"].notna()
            row = {"target": f"{variable}_{n}h", "n": rows.sum()}
            for label, frame in (("base", forecast), ("fixed", corrected)):
                low, mid, high = (frame.loc[rows, f"{variable}_{n}h_{name}"] for name in QUANTILES)
                y = truth[rows]
                row[f"{label}_mae"] = (mid - y).abs().mean()
                row[f"{label}_hit"] = ((y >= low) & (y <= high)).mean()
                row[f"{label}_width"] = (high - low).mean()
            report.append(row)
    print(f"\ncorrection fitted before {CORRECTION_SPLIT:%Y-%m-%d}, scored from then on")
    print(pd.DataFrame(report).set_index("target").round(3).to_string())


if __name__ == "__main__":
    main()
