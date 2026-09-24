"""Train the 1-6 hour forecast models on ČHMÚ station history.

    uv run train.py

Per horizon: three quantile models (10/50/90 %) for the change of each of
T, H and P after n hours, and a classifier for the chance of measurable rain
within the next n hours. Trained on 2018-2024; scored on 2025 at stations
the models never saw. The median is scored against persistence ("nothing
changes"), the 10-90 % range by how often it holds the truth (aim: 80 %),
rain against the climatological rate. Writes models/forecast.joblib.
"""

import time
from datetime import UTC, datetime
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingClassifier, HistGradientBoostingRegressor
from sklearn.metrics import brier_score_loss, roc_auc_score

from features import HORIZONS, build_features, build_targets
from forecast import QUANTILES, VARIABLES

DATA = Path(__file__).parent / "data"
MODELS = Path(__file__).parent / "models"

# Mountain stations behave unlike a city balcony (inversions, exposed summits).
MAX_ELEVATION_M = 700

# Never trained on: Plzeň-Mikulka (nearest to the balcony) and a spread of others.
HOLDOUT_STATIONS = {
    "0-20000-0-11450",  # Plzeň-Mikulka
    "0-20000-0-11406",  # Cheb
    "0-20000-0-11698",  # Kuchařovice
    "0-20000-0-11652",  # Pardubice
}
TEST_YEAR = 2025

# The balcony's rain source (the microphone) can be missing or down; hiding
# the rain inputs on a share of training rows teaches the models to cope.
RAIN_INPUT_DROPOUT = 0.3


def load_rows() -> tuple[pd.DataFrame, list[str]]:
    stations = pd.read_csv(DATA / "stations.csv")
    stations = stations[stations["elevation"] <= MAX_ELEVATION_M]
    frames = []
    for station in stations.itertuples():
        path = DATA / "stations" / f"{station.wsi}.parquet"
        if not path.exists():
            continue
        frame = pd.read_parquet(path)
        features = build_features(frame, station.lon)
        rows = features.join(build_targets(frame))
        # Hourly samples: neighbouring 10-minute rows add little but time.
        rows = rows[(rows.index.minute == 0) & rows["T"].notna() & rows["H"].notna()]
        rows["station"] = station.wsi
        frames.append(rows)
        print(f"  {station.name}: {len(rows)} rows")
    return pd.concat(frames), list(features.columns)


def main() -> None:
    started = time.time()
    rows, feature_names = load_rows()
    year = rows.index.year
    held_out = rows["station"].isin(HOLDOUT_STATIONS)
    train = rows[(year < TEST_YEAR) & ~held_out].copy()
    hidden = np.random.default_rng(0).random(len(train)) < RAIN_INPUT_DROPOUT
    train.loc[hidden, ["rain_past1h", "rain_past3h"]] = np.nan
    test = rows[(year == TEST_YEAR) & held_out]
    print(f"train {len(train)} rows, test {len(test)} rows at {test['station'].nunique()} unseen stations")

    models = {}
    report = []
    for n in HORIZONS:
        for variable in VARIABLES:
            target = f"{variable}_{n}h"
            fit = train[train[target].notna()]
            scored = test[test[target].notna()]
            predicted = {}
            for name, quantile in QUANTILES.items():
                model = HistGradientBoostingRegressor(
                    loss="quantile", quantile=quantile, max_iter=400, learning_rate=0.1, random_state=0
                )
                model.fit(fit[feature_names], fit[target])
                models[f"{target}_{name}"] = model
                predicted[name] = model.predict(scored[feature_names])
            truth = scored[target].to_numpy()
            report.append({
                "target": target,
                "model_mae": np.abs(predicted["mid"] - truth).mean(),
                "persistence_mae": np.abs(truth).mean(),
                "range_hit": ((truth >= predicted["low"]) & (truth <= predicted["high"])).mean(),
                "range_width": (predicted["high"] - predicted["low"]).mean(),
            })

        target = f"rain_{n}h"
        fit = train[train[target].notna()]
        model = HistGradientBoostingClassifier(max_iter=400, learning_rate=0.1, random_state=0)
        model.fit(fit[feature_names], fit[target].astype(int))
        scored = test[test[target].notna()]
        chance = model.predict_proba(scored[feature_names])[:, 1]
        climatology = np.full(len(scored), fit[target].mean())
        # Onset: rows where it has not rained in the last hour - the hard case.
        dry = (scored["rain_past1h"] == 0).to_numpy()
        models[target] = model
        report.append({
            "target": target,
            "model_brier": brier_score_loss(scored[target], chance),
            "climatology_brier": brier_score_loss(scored[target], climatology),
            "auc": roc_auc_score(scored[target], chance),
            "onset_auc": roc_auc_score(scored[target][dry], chance[dry]),
            "base_rate": fit[target].mean(),
        })
        print(f"  horizon {n} h done ({time.time() - started:.0f} s)")

    report = pd.DataFrame(report).set_index("target")
    pd.set_option("display.width", 120)
    print(report.round(3).to_string())

    MODELS.mkdir(exist_ok=True)
    joblib.dump(
        {
            "features": feature_names,
            "horizons": list(HORIZONS),
            "models": models,
            "report": report.to_dict(orient="index"),
            "trained_at": datetime.now(UTC).isoformat(),
            "training_rows": len(train),
        },
        MODELS / "forecast.joblib",
        compress=3,
    )
    print(f"saved models/forecast.joblib in {time.time() - started:.0f} s")


if __name__ == "__main__":
    main()
