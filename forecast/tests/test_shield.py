import numpy as np
import pandas as pd
import pytest

import shield
from conftest import grid_frame
from features import dew_point

LONGITUDE = 13.4
MORNING = pd.Timestamp("2026-10-06 06:20", tz="UTC")
AFTERNOON = pd.Timestamp("2026-10-06 13:00", tz="UTC")


def test_sun_stands_south_at_its_highest() -> None:
    index = pd.date_range("2026-10-06 10:00", periods=13, freq="10min", tz="UTC")
    elevation, azimuth = shield.sun_position(index, LONGITUDE)
    highest = int(np.argmax(elevation))

    assert azimuth[highest] == pytest.approx(180, abs=3)
    assert elevation[highest] == pytest.approx(90 - shield.LATITUDE - 5.4, abs=1)


def test_heating_grows_only_above_diffuse_light_while_the_sun_is_on_the_facade() -> None:
    bright = grid_frame(4, MORNING, L=1500.0)
    diffuse = grid_frame(4, MORNING, L=shield.DIFFUSE_LUX)
    behind = grid_frame(4, AFTERNOON, L=2000.0)

    assert shield.heating(bright, LONGITUDE).is_monotonic_increasing
    assert shield.heating(bright, LONGITUDE).iloc[-1] > 1.0
    assert shield.heating(diffuse, LONGITUDE).eq(0.0).all()
    assert shield.heating(behind, LONGITUDE).eq(0.0).all()


def test_heating_cools_in_the_dark_and_through_a_window_without_light() -> None:
    current = grid_frame(6, MORNING, L=1500.0)
    current.loc[current.index[2:], "L"] = 0.0
    current.loc[current.index[4], "L"] = np.nan
    heated = shield.heating(current, LONGITUDE)

    assert heated.iloc[3] == pytest.approx(heated.iloc[2] * (1 - shield.COOLING))
    assert heated.iloc[5] == pytest.approx(heated.iloc[4] * (1 - shield.COOLING))
    assert heated.iloc[5] > 0.0


def test_air_keeps_the_dew_point() -> None:
    current = grid_frame(2, MORNING, T=20.0, H=50.0)
    heated = pd.Series([0.0, 5.0], index=current.index)
    cooled = shield.air(current, heated)

    assert cooled["T"].tolist() == [20.0, 15.0]
    assert cooled["H"].iloc[0] == 50.0
    assert cooled["H"].iloc[1] > 50.0
    assert dew_point(cooled["T"], cooled["H"]).iloc[1] == pytest.approx(dew_point(current["T"], current["H"]).iloc[1])
