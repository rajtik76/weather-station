from dataclasses import dataclass

import numpy as np
import pandas as pd

TIMEZONE = "Europe/Prague"
PHASE_MINUTES = 30
PHASES = 24 * 60 // PHASE_MINUTES
HISTORY_DAYS = 14
REFERENCE_QUANTILE = 0.9
DAYLIGHT_FLOOR_LUX = 100.0
GAIN_FADE_HOURS = 6


@dataclass(frozen=True)
class LightProfile:
    day: str
    values: list[float | None]
    gains: list[float | None]


def local_day(time: pd.Timestamp) -> pd.Timestamp:
    return time.tz_convert(TIMEZONE).normalize()


def illumination(current: pd.DataFrame) -> pd.Series:
    return current["L"].rolling(3, min_periods=2).mean()


def phases(index: pd.DatetimeIndex) -> np.ndarray:
    return (index.hour * 60 + index.minute) // PHASE_MINUTES


def availability(current: pd.DataFrame) -> pd.Series | None:
    return current["received_at"].rolling(3, min_periods=2).max() if "received_at" in current else None


def by_phase(values: pd.Series) -> list[float | None]:
    return [float(values[i]) if i in values and pd.notna(values[i]) else None for i in range(PHASES)]


def before_day(index: pd.DatetimeIndex, available: pd.Series | None, day: pd.Timestamp) -> np.ndarray:
    before = (index < day) & (index >= day - pd.DateOffset(days=HISTORY_DAYS))
    if available is not None:
        before &= (available < day.timestamp()).to_numpy()
    return before


def reference_values(smoothed: pd.Series, available: pd.Series | None, day: pd.Timestamp) -> list[float | None]:
    before = before_day(smoothed.index, available, day)
    return by_phase(smoothed[before].groupby(phases(smoothed.index[before])).quantile(REFERENCE_QUANTILE))


def profile(current: pd.DataFrame, daylight: pd.Series, day: pd.Timestamp) -> LightProfile:
    available = availability(current)
    before = before_day(daylight.index, available, day)
    return LightProfile(
        day=day.strftime("%Y-%m-%d"),
        values=reference_values(illumination(current), available, day),
        gains=by_phase(daylight[before].groupby(phases(daylight.index[before])).mean()),
    )


def expected_lux(index: pd.DatetimeIndex, values: list[float | None]) -> pd.Series:
    return pd.Series(np.array(values, dtype=float)[phases(index)], index=index)


def daylight_gain(smoothed: pd.Series, raw: pd.Series, values: list[float | None]) -> pd.Series:
    expected = expected_lux(smoothed.index, values)
    usable = expected.ge(DAYLIGHT_FLOOR_LUX) & smoothed.notna() & raw.notna()
    return (smoothed / expected).clip(0.0, 1.0).where(usable)


def realized_gain(smoothed: pd.Series, raw: pd.Series, values: list[float | None]) -> pd.Series:
    dark = expected_lux(smoothed.index, values).lt(DAYLIGHT_FLOOR_LUX) & raw.notna()
    return daylight_gain(smoothed, raw, values).mask(dark, 1.0)


def historical_gains(current: pd.DataFrame) -> tuple[pd.Series, pd.Series]:
    smoothed = illumination(current)
    available = availability(current)
    daylight = pd.Series(np.nan, index=current.index)
    realized = pd.Series(np.nan, index=current.index)
    days = current.index.tz_convert(TIMEZONE).normalize()
    for day in days.unique():
        today = days == day
        values = reference_values(smoothed, available, day)
        daylight.loc[today] = daylight_gain(smoothed[today], current["L"][today], values)
        realized.loc[today] = realized_gain(smoothed[today], current["L"][today], values)
    return daylight, realized


def current_gain(current: pd.DataFrame, reference: LightProfile) -> pd.Series:
    return daylight_gain(illumination(current), current["L"], reference.values)


def expected_gain(now: pd.Series, reference: LightProfile, n: int) -> pd.Series:
    targets = now.index + pd.Timedelta(hours=n)
    usual = pd.Series(np.array(reference.gains, dtype=float)[phases(targets)], index=now.index).fillna(1.0)
    weight = max(0.0, 1 - n / GAIN_FADE_HOURS)
    return (weight * now + (1 - weight) * usual).fillna(usual)
