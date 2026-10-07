import numpy as np
import pandas as pd
import pytest

import sun

LONGITUDE = 13.4


def test_sun_stands_south_at_its_highest() -> None:
    index = pd.date_range("2026-10-06 10:00", periods=13, freq="10min", tz="UTC")
    elevation, azimuth = sun.sun_position(index, LONGITUDE)
    highest = int(np.argmax(elevation))

    assert azimuth[highest] == pytest.approx(180, abs=3)
    assert elevation[highest] == pytest.approx(90 - sun.LATITUDE - 5.4, abs=1)


def test_solar_hour_runs_ahead_of_utc_by_the_longitude() -> None:
    index = pd.DatetimeIndex([pd.Timestamp("2026-10-06 23:30", tz="UTC")])

    assert sun.solar_hour(index, 15.0)[0] == pytest.approx(0.5)


@pytest.mark.parametrize(("time", "facing"), [
    ("2026-10-06 06:30", True), ("2026-06-21 03:30", True), ("2026-10-06 12:00", False), ("2026-10-06 03:00", False),
])
def test_the_facade_sees_the_morning_sun_only(time: str, facing: bool) -> None:
    elevation, azimuth = sun.sun_position(pd.DatetimeIndex([pd.Timestamp(time, tz="UTC")]), LONGITUDE)

    assert sun.on_facade(elevation, azimuth)[0] == facing
