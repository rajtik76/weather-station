"""light-v5: the base forecast plus a median-loss boosting model of its daytime miss per horizon.

Sun on the shield from the temperature swing within each 10-minute window. Night (sun below DAYLIGHT_ELEVATION at
issue and target) stays the base forecast.
"""

from dataclasses import dataclass

import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingRegressor

import trees
from features import STEPS_PER_HOUR
from sun import on_facade, solar_hour, sun_position

EXPERIMENT_VERSION = "light-v5"
TIMEZONE = "Europe/Prague"
DAYLIGHT_ELEVATION = -3.0
RANGE_COVERAGE = 0.8
FOLDS = 4
MIN_TRAINING_ROWS = 300
BOOSTING = {
    "loss": "absolute_error", "max_iter": 80, "learning_rate": 0.1, "max_leaf_nodes": 8,
    "min_samples_leaf": 30, "l2_regularization": 1.0, "early_stopping": False,
}
INPUTS = (
    "target_solar", "target_elevation", "target_azimuth", "target_on_facade", "solar", "on_facade",
    "swing", "swing_1h", "swing_smoothed", "T_d1h", "T_d3h", "base_change", "error_1h", "error_same",
)


@dataclass(frozen=True)
class Horizon:
    """`baseline` and `trees` as in trees.py, over INPUTS."""

    hours: int
    baseline: float
    trees: list[trees.Tree]
    widen: float

    def shift(self, x: pd.DataFrame) -> np.ndarray:
        return trees.predict(self.baseline, self.trees, x[list(INPUTS)].to_numpy(dtype=float))


def local_day(time: pd.Timestamp) -> str:
    return time.tz_convert(TIMEZONE).strftime("%Y-%m-%d")


def daytime(index: pd.DatetimeIndex, n: int, longitude: float) -> np.ndarray:
    """Sun above DAYLIGHT_ELEVATION at issue or at the target."""
    at_issue, _ = sun_position(index, longitude)
    at_target, _ = sun_position(index + pd.Timedelta(hours=n), longitude)
    return (at_issue > DAYLIGHT_ELEVATION) | (at_target > DAYLIGHT_ELEVATION)


def inputs(current: pd.DataFrame, forecast: pd.DataFrame, n: int, longitude: float) -> pd.DataFrame:
    """Per window of `forecast` (a contiguous run of the grid); `current` reaches 3 h behind its first window."""
    temperature = current["T"]
    swing = current["Tmax"] - current["Tmin"] if {"Tmin", "Tmax"} <= set(current) else temperature * np.nan
    station = pd.DataFrame({
        "swing": swing.rolling(3, min_periods=1).mean(),
        "swing_1h": swing.rolling(STEPS_PER_HOUR, min_periods=3).max(),
        "swing_smoothed": swing.ewm(halflife=4).mean(),
        "T_d1h": temperature - temperature.shift(STEPS_PER_HOUR),
        "T_d3h": temperature - temperature.shift(3 * STEPS_PER_HOUR),
    }).reindex(forecast.index)

    now = temperature.reindex(forecast.index)
    target = forecast.index + pd.Timedelta(hours=n)
    elevation, azimuth = sun_position(forecast.index, longitude)
    target_elevation, target_azimuth = sun_position(target, longitude)
    station["base_change"] = forecast[f"T_{n}h_mid"] - now
    # Issued n h ago, verified now.
    station["error_1h"] = now - forecast["T_1h_mid"].shift(STEPS_PER_HOUR)
    station["error_same"] = now - forecast[f"T_{n}h_mid"].shift(n * STEPS_PER_HOUR)
    station["solar"] = solar_hour(forecast.index, longitude)
    station["on_facade"] = on_facade(elevation, azimuth).astype(float)
    station["target_solar"] = solar_hour(target, longitude)
    station["target_elevation"] = target_elevation
    station["target_azimuth"] = target_azimuth
    station["target_on_facade"] = on_facade(target_elevation, target_azimuth).astype(float)
    return station[list(INPUTS)]


def outside(forecast: pd.DataFrame, truth: pd.Series, shift: np.ndarray, n: int) -> np.ndarray:
    """Distance of the truth outside the moved base band (negative: inside)."""
    low = forecast[f"T_{n}h_low"].to_numpy() + shift
    high = forecast[f"T_{n}h_high"].to_numpy() + shift
    return np.maximum(low - truth.to_numpy(), truth.to_numpy() - high)


def out_of_fold(x: pd.DataFrame, residual: pd.Series) -> np.ndarray:
    """Each local day predicted by a model that did not see it."""
    days = x.index.tz_convert(TIMEZONE).normalize()
    order = {day: position % FOLDS for position, day in enumerate(sorted(days.unique()))}
    folds = np.array([order[day] for day in days])
    predicted = np.zeros(len(x))
    for fold in np.unique(folds):
        held = folds == fold
        if held.all():
            continue
        model = HistGradientBoostingRegressor(**BOOSTING).fit(x[~held], residual[~held])
        predicted[held] = model.predict(x[held])
    return predicted


@dataclass(frozen=True)
class Fitted:
    """A fitted horizon with its training rows: the out-of-fold shift is what another model may build on."""

    horizon: Horizon
    index: pd.DatetimeIndex
    truth: pd.Series
    residual: pd.Series
    shift: np.ndarray


def widen(forecast: pd.DataFrame, truth: pd.Series, shift: np.ndarray, n: int) -> float:
    """How far the moved base band must widen to hold RANGE_COVERAGE of the truth."""
    distance = outside(forecast, truth, shift, n)
    level = min(1.0, RANGE_COVERAGE * (1 + 1 / len(distance)))
    return float(np.quantile(distance, level))


def fit_horizon(
    forecast: pd.DataFrame, current: pd.DataFrame, n: int, longitude: float, since: pd.Timestamp | None,
) -> Fitted | None:
    """None without MIN_TRAINING_ROWS verified daytime rows from `since` on."""
    x = inputs(current, forecast, n, longitude)
    truth = current["T"].shift(-n * STEPS_PER_HOUR).reindex(forecast.index)
    residual = truth - forecast[f"T_{n}h_mid"]
    rows = residual.notna().to_numpy() & daytime(forecast.index, n, longitude)
    if since is not None:
        rows &= forecast.index >= since
    if rows.sum() < MIN_TRAINING_ROWS:
        return None
    model = HistGradientBoostingRegressor(**BOOSTING).fit(x[rows], residual[rows])
    shift = out_of_fold(x[rows], residual[rows])
    horizon = Horizon(n, *trees.encode(model), widen(forecast[rows], truth[rows], shift, n))
    return Fitted(horizon, forecast.index[rows], truth[rows], residual[rows], shift)


def fit(
    forecast: pd.DataFrame, current: pd.DataFrame, horizons: list[int], longitude: float,
    since: pd.Timestamp | None = None,
) -> list[Horizon]:
    """One Horizon per horizon with MIN_TRAINING_ROWS verified daytime rows from `since` on."""
    fitted = (fit_horizon(forecast, current, n, longitude, since) for n in horizons)
    return [one.horizon for one in fitted if one is not None]


def latest_shift(horizon: Horizon, forecast: pd.DataFrame, current: pd.DataFrame, longitude: float) -> float | None:
    """The fitted miss of the latest window; None at night."""
    if not daytime(forecast.index[-1:], horizon.hours, longitude)[0]:
        return None
    return float(horizon.shift(inputs(current, forecast, horizon.hours, longitude).iloc[-1:])[0])


def moved_band(forecast: pd.DataFrame, n: int, shift: float | None, widen: float) -> dict:
    """The latest base band moved by `shift` and widened; the base band itself for None."""
    latest = forecast.iloc[-1]
    shift, widen = (0.0, 0.0) if shift is None else (shift, widen)
    mid = float(latest[f"T_{n}h_mid"]) + shift
    low = min(float(latest[f"T_{n}h_low"]) + shift - widen, mid)
    high = max(float(latest[f"T_{n}h_high"]) + shift + widen, mid)
    return {"hours": n, "temperature": {"low": round(low, 2), "mid": round(mid, 2), "high": round(high, 2)}}


def bands(fitted: list[Horizon], forecast: pd.DataFrame, current: pd.DataFrame, longitude: float) -> list[dict]:
    """Temperature band of the latest window per fitted horizon; the base band at night."""
    return [
        moved_band(forecast, horizon.hours, latest_shift(horizon, forecast, current, longitude), horizon.widen)
        for horizon in fitted
    ]
