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
    """Absolute forecast values per row: T_3h_low, T_3h_mid, ..., rain_3h, rain_3h_raw."""
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
        forecast[f"rain_{n}h_raw"] = bundle["models"][f"rain_{n}h"].predict_proba(features)[:, 1]
    return nest_rain(forecast, bundle["horizons"])


def nest_rain(forecast: pd.DataFrame, horizons: list[int]) -> pd.DataFrame:
    """Rain within the first hour is no likelier than within the first two.

    Each horizon's classifier is trained on its own, and the 1 h one goes
    wild on inputs it barely saw in training: the sun heating the sensor
    ten degrees in an hour, or a gap in the readings. On the balcony it
    gave 50-100 % on dry days while every longer horizon said 1-10 %, and
    it never once called a rain that came. Capping it by the 2 h chance
    keeps the two nested as the events are. The longer horizons stay as
    they are: capping each by the next pulled the 2 h chance down from
    23 % to 9 % on the morning it did rain. `rain_{n}h_raw` keeps what each
    classifier said, so a capped run can still be told apart.
    """
    for n in horizons:
        forecast[f"rain_{n}h"] = forecast[f"rain_{n}h_raw"]
    if 1 in horizons and 2 in horizons:
        forecast["rain_1h"] = forecast[["rain_1h_raw", "rain_2h_raw"]].min(axis=1)
    return forecast
