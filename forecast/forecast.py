"""Turn a trained model bundle and feature rows into a forecast.

T, H and P come as a range: the 10th, 50th and 90th percentile of the change
after each horizon, so four readings in five should land inside
[low, high]. The range is narrow in settled weather and widens when the
inputs look unsettled. Rain comes as the chance of rain within the next n hours.
"""

import numpy as np
import pandas as pd

QUANTILES = {"low": 0.1, "mid": 0.5, "high": 0.9}
VARIABLES = ("T", "H", "P")


def predict(bundle: dict, features: pd.DataFrame, current: pd.DataFrame) -> pd.DataFrame:
    """Absolute forecast values per row: T_3h_low, T_3h_mid, ..., rain_3h."""
    features = features[bundle["features"]]
    forecast = pd.DataFrame(index=features.index)
    for n in bundle["horizons"]:
        for variable in VARIABLES:
            changes = np.column_stack([
                bundle["models"][f"{variable}_{n}h_{name}"].predict(features) for name in QUANTILES
            ])
            # Separately trained quantiles can cross; sorting restores low <= mid <= high.
            changes.sort(axis=1)
            for column, name in enumerate(QUANTILES):
                forecast[f"{variable}_{n}h_{name}"] = current[variable].to_numpy() + changes[:, column]
        forecast[f"rain_{n}h"] = bundle["models"][f"rain_{n}h"].predict_proba(features)[:, 1]
    return forecast
