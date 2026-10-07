"""Sun position and solar time at the station."""

import numpy as np
import pandas as pd

LATITUDE = 49.74
FACADE_NORMAL_AZIMUTH = 71.8


def solar_hour(index: pd.DatetimeIndex, longitude: float) -> np.ndarray:
    return ((index.hour + index.minute / 60 + longitude / 15) % 24).to_numpy()


def on_facade(elevation: np.ndarray, azimuth: np.ndarray) -> np.ndarray:
    """Sun above the horizon and within 90° of the east facade's normal."""
    off_normal = (azimuth - FACADE_NORMAL_AZIMUTH + 180) % 360 - 180
    return (elevation > 0) & (np.abs(off_normal) < 90)


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
