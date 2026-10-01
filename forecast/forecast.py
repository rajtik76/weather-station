"""Model bundle + feature rows -> forecast: T, H, P as 10/50/90 % quantiles of the change; rain as chance within n hours."""

import numpy as np
import pandas as pd

QUANTILES = {"low": 0.1, "mid": 0.5, "high": 0.9}
VARIABLES = ("T", "H", "P")


def predict(bundle: dict, features: pd.DataFrame, current: pd.DataFrame) -> pd.DataFrame:
    """Absolute values per row: T_3h_low, T_3h_mid, ..., rain_3h, rain_3h_raw."""
    features = features[bundle["features"]]
    forecast = pd.DataFrame(index=features.index)
    for n in bundle["horizons"]:
        for variable in VARIABLES:
            changes = np.column_stack([
                bundle["models"][f"{variable}_{n}h_{name}"].predict(features) for name in QUANTILES
            ])
            # Separately trained quantiles can cross.
            changes.sort(axis=1)
            for column, name in enumerate(QUANTILES):
                forecast[f"{variable}_{n}h_{name}"] = current[variable].to_numpy() + changes[:, column]
        forecast[f"rain_{n}h_raw"] = bundle["models"][f"rain_{n}h"].predict_proba(features)[:, 1]
    return nest_rain(forecast, bundle["horizons"])


def nest_rain(forecast: pd.DataFrame, horizons: list[int]) -> pd.DataFrame:
    """Cap rain_1h by rain_2h (the 1 h classifier goes wild on out-of-distribution inputs); rain_{n}h_raw keeps the originals."""
    for n in horizons:
        forecast[f"rain_{n}h"] = forecast[f"rain_{n}h_raw"]
    if 1 in horizons and 2 in horizons:
        forecast["rain_1h"] = forecast[["rain_1h_raw", "rain_2h_raw"]].min(axis=1)
    return forecast
