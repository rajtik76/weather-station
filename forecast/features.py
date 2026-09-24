"""Model inputs and targets, shared by training and the forecast service.

Input is a frame on a regular 10-minute UTC grid with columns T (°C),
H (% RH) and P (station pressure, hPa); training data adds SRA10M
(10-minute precipitation, mm) for the rain labels. Missing readings stay NaN:
the gradient boosting models accept NaN inputs.

Pressure only enters as changes, never as a level, because the level mostly
encodes the station's elevation and would not transfer between stations.
"""

import numpy as np
import pandas as pd

STEPS_PER_HOUR = 6
HORIZONS = range(1, 7)
RAIN_THRESHOLD_MM = 0.1


def to_grid(frame: pd.DataFrame) -> pd.DataFrame:
    """Snap readings indexed by UTC time onto the regular 10-minute grid.

    Stations report a few seconds into each window; gaps become NaN rows,
    so the fixed shifts below always mean the same time offsets.
    """
    frame = frame.copy()
    frame.index = frame.index.floor("10min")
    frame = frame[~frame.index.duplicated()]
    grid = pd.date_range(frame.index.min(), frame.index.max(), freq="10min", name="time")
    return frame.reindex(grid)


def dew_point(temperature: pd.Series, humidity: pd.Series) -> pd.Series:
    """Magnus formula, the same constants as App\\ValueObject\\DewPoint."""
    a, b = 17.62, 243.12
    gamma = np.log(humidity.clip(lower=1) / 100) + a * temperature / (b + temperature)
    return b * gamma / (a - gamma)


def build_features(frame: pd.DataFrame, longitude: float) -> pd.DataFrame:
    """One row of inputs per timestamp, using only readings up to that timestamp."""
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
    # Where the pressure sits within its two-day swing: fronts show up here first.
    two_days = p.rolling(hours(48), min_periods=hours(36))
    features["P_range48h"] = two_days.max() - two_days.min()
    features["P_below_max48h"] = two_days.max() - p

    # Rain in the last hours: the ČHMÚ gauge in training, the microphone's rain
    # detector on the balcony. NaN when the station has no rain source.
    if "SRA10M" in frame:
        rain = frame["SRA10M"]
        features["rain_past1h"] = rain.rolling(hours(1), min_periods=hours(1)).sum()
        features["rain_past3h"] = rain.rolling(hours(3), min_periods=hours(3)).sum()
    else:
        features["rain_past1h"] = np.nan
        features["rain_past3h"] = np.nan
    features["T_d24h"] = t - t.shift(hours(24))
    features["H_d24h"] = h - h.shift(hours(24))

    # How unsettled the weather is: jitter of T and H over the last 3 hours and
    # whether the pressure trend is speeding up. Readings are rounded to the
    # ČHMÚ resolution (0.1 °C, 1 %) first, so a finer station sensor does not
    # look calmer or noisier than the training stations.
    t_steps, h_steps = t.round(1).diff(), h.round(0).diff()
    features["T_jitter3h"] = t_steps.rolling(hours(3), min_periods=hours(2)).std()
    features["H_jitter3h"] = h_steps.rolling(hours(3), min_periods=hours(2)).std()
    features["P_accel3h"] = (p - p.shift(hours(3))) - (p.shift(hours(3)) - p.shift(hours(6)))

    day = t.rolling(hours(24), min_periods=hours(18))
    features["T_vs_mean24h"] = t - day.mean()
    features["T_range24h"] = day.max() - day.min()
    features["H_mean6h"] = h.rolling(hours(6), min_periods=hours(4)).mean()

    # Local solar time rather than UTC: the stations span six degrees of longitude.
    index = frame.index
    solar_hour = (index.hour + index.minute / 60 + longitude / 15) % 24
    features["hour_sin"] = np.sin(2 * np.pi * solar_hour / 24)
    features["hour_cos"] = np.cos(2 * np.pi * solar_hour / 24)
    features["doy_sin"] = np.sin(2 * np.pi * index.dayofyear / 365.25)
    features["doy_cos"] = np.cos(2 * np.pi * index.dayofyear / 365.25)

    return features.astype("float32")


def build_targets(frame: pd.DataFrame) -> pd.DataFrame:
    """Changes of T, H, P after each horizon, and rain within each hour ahead."""
    targets = pd.DataFrame(index=frame.index)
    for n in HORIZONS:
        future = frame.shift(-n * STEPS_PER_HOUR)
        targets[f"T_{n}h"] = future["T"] - frame["T"]
        targets[f"H_{n}h"] = future["H"] - frame["H"]
        targets[f"P_{n}h"] = future["P"] - frame["P"]

        # Precipitation summed from t to t + n hours; NaN unless every slot was reported.
        steps = n * STEPS_PER_HOUR
        total = frame["SRA10M"].rolling(steps, min_periods=steps).sum().shift(-steps)
        targets[f"rain_{n}h"] = (total >= RAIN_THRESHOLD_MM).astype("float32").where(total.notna())

    return targets.astype("float32")
