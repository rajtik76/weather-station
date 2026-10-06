"""Radiation-shield heating on the balcony's east facade: how far the reading runs above the air in direct sun.

Fitted offline against the air temperature at ČHMÚ Plzeň-Mikulka (mornings before 10 h also Plzeň-Slovany),
30 September to 6 October 2026. Per 10-minute window the heating grows by HEATING[sun azimuth bin] for every
1000 lx above DIFFUSE_LUX and sheds COOLING of itself (time constant 54 min). Only azimuths 90-160° were seen;
earlier spring and summer morning sun (below 90°) heats nothing here.
"""

import numpy as np
import pandas as pd

from features import dew_point

LATITUDE = 49.74
DIFFUSE_LUX = 500.0
AZIMUTH_BIN = 10
HEATING = {90: 1.501, 100: 1.673, 110: 0.809, 120: 0.555, 130: 0.418, 140: 0.419, 150: 0.378}
COOLING = 0.169
MAGNUS_A, MAGNUS_B = 17.62, 243.12


def sun_position(index: pd.DatetimeIndex, longitude: float) -> tuple[np.ndarray, np.ndarray]:
    """Elevation and azimuth from north, degrees, at the middle of each 10-minute window."""
    middle = index + pd.Timedelta(minutes=5)
    day = middle.dayofyear.to_numpy()
    hours = (middle.hour + middle.minute / 60).to_numpy()
    year = 2 * np.pi / 365 * (day - 1 + (hours - 12) / 24)
    declination = (0.006918 - 0.399912 * np.cos(year) + 0.070257 * np.sin(year) - 0.006758 * np.cos(2 * year)
                   + 0.000907 * np.sin(2 * year) - 0.002697 * np.cos(3 * year) + 0.00148 * np.sin(3 * year))
    equation = 229.18 * (0.000075 + 0.001868 * np.cos(year) - 0.032077 * np.sin(year)
                         - 0.014615 * np.cos(2 * year) - 0.040849 * np.sin(2 * year))
    hour_angle = np.radians((hours * 60 + equation + 4 * longitude) / 4 - 180)
    latitude = np.radians(LATITUDE)
    sine = np.sin(latitude) * np.sin(declination) + np.cos(latitude) * np.cos(declination) * np.cos(hour_angle)
    elevation = np.degrees(np.arcsin(np.clip(sine, -1, 1)))
    azimuth = np.degrees(np.arctan2(
        np.sin(hour_angle), np.cos(hour_angle) * np.sin(latitude) - np.tan(declination) * np.cos(latitude),
    )) + 180
    return elevation, azimuth


def heating_rate(index: pd.DatetimeIndex, longitude: float) -> np.ndarray:
    """K per 10 minutes for every 1000 lx above DIFFUSE_LUX; zero while the sun is off the facade."""
    elevation, azimuth = sun_position(index, longitude)
    bins = (np.floor(azimuth / AZIMUTH_BIN) * AZIMUTH_BIN).astype(int)
    return np.where(elevation > 0, [HEATING.get(int(b), 0.0) for b in bins], 0.0)


def drive(lux: np.ndarray) -> np.ndarray:
    """Thousands of lux above the diffuse light; missing light drives nothing."""
    return np.clip(np.nan_to_num(lux) - DIFFUSE_LUX, 0, None) / 1000


def heating(current: pd.DataFrame, longitude: float) -> pd.Series:
    """Heating at the start of each window, from zero at the first; a window without light only cools."""
    rate = heating_rate(current.index, longitude)
    push = drive(current["L"].to_numpy(dtype=float))
    values = np.zeros(len(current))
    level = 0.0
    for step in range(len(current)):
        values[step] = level
        level = level * (1 - COOLING) + rate[step] * push[step]
    return pd.Series(values, index=current.index)


def air(current: pd.DataFrame, heated: pd.Series) -> pd.DataFrame:
    """Readings with the heating taken out; humidity recomputed at the same dew point."""
    cooled = current.copy()
    dew = dew_point(current["T"], current["H"])
    cooled["T"] = current["T"] - heated
    saturation = MAGNUS_A * dew / (MAGNUS_B + dew) - MAGNUS_A * cooled["T"] / (MAGNUS_B + cooled["T"])
    cooled["H"] = (100 * np.exp(saturation)).clip(upper=100).where(heated.gt(0), current["H"])
    return cooled
