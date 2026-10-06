import numpy as np
import pandas as pd
import pytest

import light_gain as light
from conftest import grid_frame

LONGITUDE = 13.4
START = pd.Timestamp("2026-10-01", tz="UTC")


def daylight_frame(days: int, lux: float) -> pd.DataFrame:
    current = grid_frame(days * 144, START, L=lux)
    current["received_at"] = current.index.astype("int64") / 1e9
    return current


def test_profile_excludes_readings_that_arrived_after_the_day_began() -> None:
    current = daylight_frame(4, 1000.0)
    day = light.local_day(current.index[-1])
    current.loc[current.index < day, "received_at"] = day.timestamp() + 100

    reference = light.profile(current, day, LONGITUDE)

    assert reference.envelope == [None] * light.ENVELOPE_SIZE


def test_envelope_is_the_brightest_light_per_sun_position() -> None:
    current = daylight_frame(4, 600.0)
    slots = light.sun_slots(current.index, LONGITUDE)
    current.loc[(slots >= 0) & (current.index.day == 2), "L"] = 1500.0
    day = light.local_day(current.index[-1])

    reference = light.profile(current, day, LONGITUDE)
    lit = [value for value in reference.envelope if value is not None]

    assert lit and max(lit) == pytest.approx(1500.0)
    assert reference.envelope[light.ELEVATIONS - 1] is None


def test_sky_gain_is_capped_and_unknown_in_the_dark() -> None:
    current = daylight_frame(1, 3000.0)
    envelope = [2000.0] * light.ENVELOPE_SIZE
    gain = light.sky_gain(current, envelope, LONGITUDE)
    sun_up = light.sun_slots(current.index, LONGITUDE) >= 0

    assert gain[sun_up].dropna().eq(1.0).all()
    assert gain[~sun_up].isna().all()


def test_gain_now_is_the_unknown_sky_in_the_dark_or_without_light() -> None:
    reference = light.LightProfile("2026-10-06", [2000.0] * light.ENVELOPE_SIZE)
    night = grid_frame(3, pd.Timestamp("2026-10-06 00:00", tz="UTC"), L=0.0)
    unlit = grid_frame(3, pd.Timestamp("2026-10-06 08:00", tz="UTC"), L=np.nan)

    assert light.gain_now(night, reference, LONGITUDE) == light.UNKNOWN_SKY_GAIN
    assert light.gain_now(unlit, reference, LONGITUDE) == light.UNKNOWN_SKY_GAIN


def test_gain_now_reads_the_latest_window_in_daylight() -> None:
    morning = grid_frame(3, pd.Timestamp("2026-10-06 08:00", tz="UTC"), L=500.0)
    reference = light.LightProfile("2026-10-06", [2000.0] * light.ENVELOPE_SIZE)

    assert light.gain_now(morning, reference, LONGITUDE) == pytest.approx(0.25)
