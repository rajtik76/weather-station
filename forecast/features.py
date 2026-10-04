"""Model inputs and targets, shared by training and serving (train/serve parity).

Input: 10-minute UTC grid with T (°C), H (% RH), P (station pressure, hPa); training adds SRA10M (mm per 10 min).
Gaps stay NaN. Pressure enters only as changes: its level is station-specific.
"""

import numpy as np
import pandas as pd

STEPS_PER_HOUR = 6
HORIZONS = range(1, 7)
RAIN_THRESHOLD_MM = 0.1


def to_grid(frame: pd.DataFrame) -> pd.DataFrame:
    """Snap readings onto the 10-minute grid; gaps become NaN rows so fixed shifts mean fixed offsets."""
    frame = frame.copy()
    frame.index = frame.index.floor("10min")
    frame = frame[~frame.index.duplicated()]
    grid = pd.date_range(frame.index.min(), frame.index.max(), freq="10min", name="time")
    return frame.reindex(grid)


def dew_point(temperature: pd.Series, humidity: pd.Series) -> pd.Series:
    """Magnus formula, same constants as App\\ValueObject\\DewPoint."""
    a, b = 17.62, 243.12
    gamma = np.log(humidity.clip(lower=1) / 100) + a * temperature / (b + temperature)
    return b * gamma / (a - gamma)


def build_features(frame: pd.DataFrame, longitude: float) -> pd.DataFrame:
    """Inputs per timestamp from readings up to that timestamp only."""
    t, h, p = frame["T"], frame["H"], frame["P"]
    hours = lambda n: n * STEPS_PER_HOUR  # noqa: E731
    features = pd.DataFrame(index=frame.index)

    features["T"] = t
    features["H"] = h
    dew = dew_point(t, h)
    features["dew_spread"] = t - dew

    for n in (1, 3, 6):
        features[f"T_d{n}h"] = t - t.shift(hours(n))
        features[f"H_d{n}h"] = h - h.shift(hours(n))
        features[f"P_d{n}h"] = p - p.shift(hours(n))
    features["P_d12h"] = p - p.shift(hours(12))
    features["P_d24h"] = p - p.shift(hours(24))
    features["P_d48h"] = p - p.shift(hours(48))
    two_days = p.rolling(hours(48), min_periods=hours(36))
    features["P_range48h"] = two_days.max() - two_days.min()
    features["P_below_max48h"] = two_days.max() - p

    # Rain source: ČHMÚ gauge in training, microphone detector on the balcony; NaN without one.
    if "SRA10M" in frame:
        rain = frame["SRA10M"]
        features["rain_past1h"] = rain.rolling(hours(1), min_periods=hours(1)).sum()
        features["rain_past3h"] = rain.rolling(hours(3), min_periods=hours(3)).sum()
    else:
        features["rain_past1h"] = np.nan
        features["rain_past3h"] = np.nan
    features["T_d24h"] = t - t.shift(hours(24))
    features["H_d24h"] = h - h.shift(hours(24))

    # Round to ČHMÚ resolution (0.1 °C, 1 %) so a finer sensor does not look calmer than the training stations.
    t_steps, h_steps = t.round(1).diff(), h.round(0).diff()
    features["T_jitter3h"] = t_steps.rolling(hours(3), min_periods=hours(2)).std()
    features["H_jitter3h"] = h_steps.rolling(hours(3), min_periods=hours(2)).std()
    features["P_accel3h"] = (p - p.shift(hours(3))) - (p.shift(hours(3)) - p.shift(hours(6)))

    day = t.rolling(hours(24), min_periods=hours(18))
    features["T_vs_mean24h"] = t - day.mean()
    features["T_range24h"] = day.max() - day.min()
    features["H_mean6h"] = h.rolling(hours(6), min_periods=hours(4)).mean()

    # Solar time, not UTC: stations span six degrees of longitude.
    index = frame.index
    solar_hour = (index.hour + index.minute / 60 + longitude / 15) % 24
    features["hour_sin"] = np.sin(2 * np.pi * solar_hour / 24)
    features["hour_cos"] = np.cos(2 * np.pi * solar_hour / 24)
    features["doy_sin"] = np.sin(2 * np.pi * index.dayofyear / 365.25)
    features["doy_cos"] = np.cos(2 * np.pi * index.dayofyear / 365.25)

    return features.astype("float32")


def build_targets(frame: pd.DataFrame) -> pd.DataFrame:
    """Changes of T, H, P after each horizon, and rain within the next n hours."""
    targets = pd.DataFrame(index=frame.index)
    for n in HORIZONS:
        future = frame.shift(-n * STEPS_PER_HOUR)
        targets[f"T_{n}h"] = future["T"] - frame["T"]
        targets[f"H_{n}h"] = future["H"] - frame["H"]
        targets[f"P_{n}h"] = future["P"] - frame["P"]

        # NaN unless every slot in the window was reported.
        steps = n * STEPS_PER_HOUR
        total = frame["SRA10M"].rolling(steps, min_periods=steps).sum().shift(-steps)
        targets[f"rain_{n}h"] = (total >= RAIN_THRESHOLD_MM).astype("float32").where(total.notna())

    return targets.astype("float32")
