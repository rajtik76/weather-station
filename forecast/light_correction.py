from dataclasses import dataclass

import numpy as np
import pandas as pd
from sklearn.linear_model import Ridge

from correction import MIN_HISTORY_ROWS, RANGE_COVERAGE, RIDGE_ALPHA, SOLAR_INPUTS, Correction, errors, inputs
from features import STEPS_PER_HOUR

EXPERIMENT_VERSION = "light-v1"
TIMEZONE = "Europe/Prague"
PHASE_MINUTES = 30
HISTORY_DAYS = 14
REFERENCE_QUANTILE = 0.9
DAYLIGHT_FLOOR_LUX = 100.0


@dataclass(frozen=True)
class LightProfile:
    day: str
    values: list[float | None]


def local_day(time: pd.Timestamp) -> pd.Timestamp:
    return time.tz_convert(TIMEZONE).normalize()


def illumination(current: pd.DataFrame) -> pd.Series:
    return current["L"].rolling(3, min_periods=2).mean()


def phases(index: pd.DatetimeIndex) -> np.ndarray:
    return (index.hour * 60 + index.minute) // PHASE_MINUTES


def availability(current: pd.DataFrame) -> pd.Series | None:
    return current["received_at"].rolling(3, min_periods=2).max() if "received_at" in current else None


def profile(current: pd.DataFrame, day: pd.Timestamp) -> LightProfile:
    return reference_profile(illumination(current), availability(current), day)


def reference_profile(smoothed: pd.Series, available: pd.Series | None, day: pd.Timestamp) -> LightProfile:
    before = (smoothed.index < day) & (smoothed.index >= day - pd.DateOffset(days=HISTORY_DAYS))
    if available is not None:
        before &= available < day.timestamp()
    values = smoothed[before].groupby(phases(smoothed.index[before])).quantile(REFERENCE_QUANTILE)
    return LightProfile(
        day=day.strftime("%Y-%m-%d"),
        values=[float(values[i]) if i in values and pd.notna(values[i]) else None for i in range(48)],
    )


def gain(current: pd.DataFrame, reference: LightProfile) -> pd.Series:
    return light_gain(illumination(current), current["L"], reference)


def light_gain(smoothed: pd.Series, raw: pd.Series, reference: LightProfile) -> pd.Series:
    expected = pd.Series(np.array(reference.values, dtype=float)[phases(smoothed.index)], index=smoothed.index)
    usable = expected.ge(DAYLIGHT_FLOOR_LUX) & smoothed.notna() & raw.notna()
    return (smoothed / expected).clip(0.0, 1.0).where(usable, 1.0)


def historical_gain(current: pd.DataFrame) -> pd.Series:
    smoothed = illumination(current)
    available = availability(current)
    gains = pd.Series(1.0, index=current.index)
    days = current.index.tz_convert(TIMEZONE).normalize()
    for day in days.unique():
        today = days == day
        reference = reference_profile(smoothed, available, day)
        gains.loc[today] = light_gain(smoothed[today], current["L"][today], reference)
    return gains


def light_inputs(
    forecast: pd.DataFrame, current: pd.DataFrame, n: int, longitude: float, gains: pd.Series
) -> pd.DataFrame:
    frame = inputs(forecast, current, "T", n, longitude)
    solar_scale = 1.0 if n <= 3 else 0.5
    frame[list(SOLAR_INPUTS)] = frame[list(SOLAR_INPUTS)].mul(gains * solar_scale, axis=0)
    return frame


def fit(
    forecast: pd.DataFrame, current: pd.DataFrame, horizons: list[int], longitude: float,
    since: pd.Timestamp | None, cutoff: pd.Timestamp | None = None,
) -> dict[str, Correction]:
    cutoff = current.index[-1] if cutoff is None else cutoff
    gains = historical_gain(current)
    corrections = {}
    for n in horizons:
        target = errors(forecast, current, "T", n)
        known = target.notna() & current[["T", "H", "P"]].notna().all(axis=1)
        known &= forecast.index + pd.Timedelta(hours=n) <= cutoff
        if since is not None:
            known &= forecast.index >= since
        if "received_at" in current:
            known &= current["received_at"].le(cutoff.timestamp()) & current["received_at"].shift(-n * STEPS_PER_HOUR).le(cutoff.timestamp())
        if int(known.sum()) < MIN_HISTORY_ROWS:
            continue
        x = light_inputs(forecast, current, n, longitude, gains).loc[known]
        ridge = Ridge(alpha=RIDGE_ALPHA).fit(x, target.loc[known])
        truth = current["T"].shift(-n * STEPS_PER_HOUR).loc[known]
        moved = ridge.predict(x)
        outside = np.maximum(
            forecast.loc[known, f"T_{n}h_low"] + moved - truth,
            truth - forecast.loc[known, f"T_{n}h_high"] - moved,
        )
        level = min(1.0, RANGE_COVERAGE * (1 + 1 / len(outside)))
        corrections[f"T_{n}h"] = Correction(
            intercept=float(ridge.intercept_),
            coefficients={name: float(value) for name, value in zip(x.columns, ridge.coef_, strict=True)},
            widen=float(np.quantile(outside, level)),
        )
    return corrections


def apply(
    corrections: dict[str, Correction], forecast: pd.DataFrame, current: pd.DataFrame,
    horizons: list[int], longitude: float, reference: LightProfile,
) -> pd.DataFrame:
    corrected = forecast.copy()
    gains = gain(current, reference)
    for n in horizons:
        fitted = corrections.get(f"T_{n}h")
        if fitted is None:
            continue
        moved = fitted.shift(light_inputs(forecast, current, n, longitude, gains))
        mid = forecast[f"T_{n}h_mid"] + moved
        corrected[f"T_{n}h_mid"] = mid
        corrected[f"T_{n}h_low"] = (forecast[f"T_{n}h_low"] + moved - fitted.widen).clip(upper=mid)
        corrected[f"T_{n}h_high"] = (forecast[f"T_{n}h_high"] + moved + fitted.widen).clip(lower=mid)
    return corrected
