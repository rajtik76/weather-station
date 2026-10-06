"""Light on the balcony: the clear-sky envelope by sun position and the sky's gain against it."""

from dataclasses import dataclass

import numpy as np
import pandas as pd

from shield import sun_position

TIMEZONE = "Europe/Prague"
HISTORY_DAYS = 14
DAYLIGHT_FLOOR_LUX = 100.0
ELEVATIONS = 91
ENVELOPE_SIZE = 2 * ELEVATIONS
UNKNOWN_SKY_GAIN = 0.5


@dataclass(frozen=True)
class LightProfile:
    """`envelope`: brightest lx per sun position (`sun_slots`)."""

    day: str
    envelope: list[float | None]


def local_day(time: pd.Timestamp) -> pd.Timestamp:
    return time.tz_convert(TIMEZONE).normalize()


def illumination(current: pd.DataFrame) -> pd.Series:
    return current["L"].rolling(3, min_periods=2).mean()


def availability(current: pd.DataFrame) -> pd.Series | None:
    return current["received_at"].rolling(3, min_periods=2).max() if "received_at" in current else None


def before_day(index: pd.DatetimeIndex, available: pd.Series | None, day: pd.Timestamp) -> np.ndarray:
    before = (index < day) & (index >= day - pd.DateOffset(days=HISTORY_DAYS))
    if available is not None:
        before &= (available < day.timestamp()).to_numpy()
    return before


def sun_slots(index: pd.DatetimeIndex, longitude: float) -> np.ndarray:
    """Envelope slot of each window: whole degrees of elevation, morning 0-90, afternoon 91-181; -1 below the horizon."""
    elevation, azimuth = sun_position(index, longitude)
    degrees = np.floor(elevation).astype(int)
    return np.where(elevation < 0, -1, np.where(azimuth < 180, degrees, ELEVATIONS + degrees))


def clear_lux(index: pd.DatetimeIndex, envelope: list[float | None], longitude: float) -> pd.Series:
    slots = sun_slots(index, longitude)
    values = np.array([np.nan if value is None else value for value in envelope], dtype=float)
    return pd.Series(np.where(slots >= 0, values[np.clip(slots, 0, None)], np.nan), index=index)


def sky_gain(current: pd.DataFrame, envelope: list[float | None], longitude: float) -> pd.Series:
    """Smoothed lux over the clear-sky envelope, 0-1; unknown in the dark or without light."""
    clear = clear_lux(current.index, envelope, longitude)
    lux = illumination(current)
    return (lux / clear).clip(0.0, 1.0).where(clear.ge(DAYLIGHT_FLOOR_LUX) & lux.notna() & current["L"].notna())


def profile(current: pd.DataFrame, day: pd.Timestamp, longitude: float) -> LightProfile:
    """From the 14 days before `day`, readings that had arrived by then."""
    before = before_day(current.index, availability(current), day)
    lux = illumination(current)[before].dropna()
    slots = sun_slots(lux.index, longitude)
    lit = slots >= 0
    brightest = lux[lit].groupby(slots[lit]).max()
    envelope: list[float | None] = []
    for branch in (range(ELEVATIONS), range(ELEVATIONS, ENVELOPE_SIZE)):
        filled = brightest.reindex(branch).astype(float).interpolate(limit_area="inside")
        envelope += [None if pd.isna(value) else float(value) for value in filled]
    return LightProfile(day.strftime("%Y-%m-%d"), envelope)


def gain_now(current: pd.DataFrame, reference: LightProfile, longitude: float) -> float:
    """Sky gain of the latest window; UNKNOWN_SKY_GAIN in the dark or without light."""
    latest = sky_gain(current.iloc[-3:], reference.envelope, longitude).iloc[-1]
    return float(latest) if pd.notna(latest) else UNKNOWN_SKY_GAIN
