import numpy as np
import pandas as pd
import pytest

import sky_gate as gate
import sun_correction
from conftest import grid_frame, swinging, weather_frame
from features import build_features
from forecast import predict

LONGITUDE = 13.4
DAYS = 6
NOON = pd.Timestamp("2026-10-07 11:00", tz="UTC")


def lux(values: dict[str, float], start: str, steps: int) -> pd.Series:
    """Illuminance on the grid, `values` by UTC clock time, darkness elsewhere."""
    frame = grid_frame(steps, pd.Timestamp(start, tz="UTC"), L=0.0)["L"]
    for clock, value in values.items():
        frame[frame.index.strftime("%H:%M") == clock] = value
    return frame


def test_the_envelope_is_the_brightest_reading_per_clock_slot_before_the_day() -> None:
    readings = lux({"10:00": 1000.0}, "2026-09-20", 20 * 144)
    readings[pd.Timestamp("2026-09-30 10:00", tz="UTC")] = 2500.0
    readings[pd.Timestamp("2026-10-05 10:00", tz="UTC")] = 9000.0
    readings[readings.index.strftime("%H:%M") == "11:00"] = np.nan

    envelope = gate.envelope(readings, pd.Timestamp("2026-10-05", tz="UTC"))

    assert envelope[60] == 2500.0
    assert envelope[66] is None
    assert len(envelope) == gate.SLOTS


@pytest.mark.parametrize(("level", "state"), [(300.0, "day:overcast"), (600.0, "day:mixed"), (900.0, "day:clear")])
def test_daylight_reads_the_last_hour_against_the_envelope(level: float, state: str) -> None:
    readings = lux({}, "2026-10-06 12:00", 24 * 6) + level

    states = gate.states(readings, [1000.0] * gate.SLOTS, LONGITUDE)

    assert states[NOON] == state


def test_the_night_reads_the_daylight_of_the_last_24_hours() -> None:
    readings = lux({}, "2026-10-06 00:00", 46 * 6) + 900.0

    states = gate.states(readings, [1000.0] * gate.SLOTS, LONGITUDE)

    assert states[pd.Timestamp("2026-10-07 21:00", tz="UTC")] == "night:clear"


def test_without_an_envelope_or_illuminance_the_sky_is_unknown() -> None:
    readings = lux({}, "2026-10-06 12:00", 24 * 6) + 900.0

    assert (gate.states(readings, [None] * gate.SLOTS, LONGITUDE) == gate.UNKNOWN).all()
    assert (gate.states(readings * np.nan, [1000.0] * gate.SLOTS, LONGITUDE) == gate.UNKNOWN).all()


def test_each_issue_reads_the_envelope_of_its_own_day() -> None:
    readings = lux({}, "2026-09-20", 18 * 144) + 1000.0
    readings[readings.index >= pd.Timestamp("2026-10-07", tz="UTC")] *= 5
    issued = pd.DatetimeIndex([NOON - pd.Timedelta(days=1), NOON])

    states = gate.served_states(readings, issued, LONGITUDE)

    assert list(states) == ["day:clear", "day:clear"]
    assert gate.states(readings, gate.envelope(readings, NOON.normalize() + pd.Timedelta(days=1)), LONGITUDE)[issued[0]] == "day:overcast"


def test_a_factor_is_the_shift_weighted_median_of_miss_over_shift() -> None:
    state = pd.Series(["day:clear"] * 60 + ["day:overcast"] * 60 + ["night:clear"] * 10)
    shift = np.array([2.0] * 60 + [1.0] * 30 + [3.0] * 30 + [2.0] * 10)
    residual = pd.Series(np.r_[shift[:60] * 0.9, shift[60:90] * 0.1, shift[90:120] * 0.3, shift[120:] * 5.0])

    factors = gate.factors(state, residual, shift)

    assert factors == {"day:clear": pytest.approx(0.9), "day:overcast": pytest.approx(0.3)}


def test_factors_stay_between_zero_and_the_cap() -> None:
    state = pd.Series(["day:clear"] * 50 + ["day:overcast"] * 50)
    shift = np.full(100, 1.0)

    factors = gate.factors(state, pd.Series(np.r_[np.full(50, 4.0), np.full(50, -1.0)]), shift)

    assert factors == {"day:clear": gate.MAX_FACTOR, "day:overcast": 0.0}


@pytest.fixture
def history(bundle: dict) -> tuple[pd.DataFrame, pd.DataFrame]:
    current = swinging(weather_frame(DAYS * 144, rain=False))
    current["L"] = np.clip(current["T"] - 12, 0, None) * 300
    return current, predict(bundle, build_features(current, LONGITUDE), current)


def test_the_fit_keeps_light_v5_and_gates_every_fitted_horizon(history: tuple[pd.DataFrame, pd.DataFrame], bundle: dict) -> None:
    current, forecast = history

    fitted, fitted_gate = gate.fit(forecast, current, bundle["horizons"], LONGITUDE)

    assert fitted == sun_correction.fit(forecast, current, bundle["horizons"], LONGITUDE)
    assert [horizon.hours for horizon in fitted_gate.horizons] == bundle["horizons"]
    assert all(set(horizon.factors) <= set(gate.STATES) for horizon in fitted_gate.horizons)
    assert len(fitted_gate.envelope) == gate.SLOTS


def test_without_illuminance_light_v6_is_light_v5(history: tuple[pd.DataFrame, pd.DataFrame], bundle: dict) -> None:
    current, forecast = history
    current = current.drop(columns="L")
    fitted, fitted_gate = gate.fit(forecast, current, bundle["horizons"], LONGITUDE)
    noon = forecast.index[forecast.index.hour == 11][-1]

    v5 = sun_correction.bands(fitted, forecast.loc[:noon], current.loc[:noon], LONGITUDE)
    v6 = gate.bands(fitted, fitted_gate, forecast.loc[:noon], current.loc[:noon], LONGITUDE)

    assert all(horizon.factors == {} for horizon in fitted_gate.horizons)
    assert [band["temperature"]["mid"] for band in v6] == [band["temperature"]["mid"] for band in v5]
