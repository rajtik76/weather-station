"""Station correction on small hand-built frames."""

import numpy as np
import pandas as pd
import pytest

from conftest import grid_frame, weather_frame
from correction import (
    CORRECTED_VARIABLES,
    MIN_HISTORY_ROWS,
    SOLAR_BIN_EDGES,
    Correction,
    apply,
    errors,
    fit,
    inputs,
)
from features import STEPS_PER_HOUR

HORIZONS = [1, 3]
LONGITUDE = 13.4


def flat_frame(steps: int) -> pd.DataFrame:
    return grid_frame(steps, T=10.0, H=50.0, P=1000.0)


def base_forecast(current: pd.DataFrame, bias: object, spread: float = 1.0) -> pd.DataFrame:
    """Forecast whose median is `bias` below the truth."""
    forecast = pd.DataFrame(index=current.index)
    for n in HORIZONS:
        for variable in ("T", "H", "P"):
            truth = current[variable].shift(-n * STEPS_PER_HOUR).fillna(current[variable])
            mid = truth - bias
            forecast[f"{variable}_{n}h_low"] = mid - spread
            forecast[f"{variable}_{n}h_mid"] = mid
            forecast[f"{variable}_{n}h_high"] = mid + spread
    return forecast


def test_error_is_the_truth_minus_the_median_indexed_by_issue_time() -> None:
    current = grid_frame(20, T=lambda i: 1.0 * i)
    forecast = pd.DataFrame({"T_1h_mid": 4.0}, index=current.index)

    error = errors(forecast, current, "T", 1)

    assert error.iloc[2] == 8.0 - 4.0
    assert error.iloc[0] == 6.0 - 4.0
    assert error.iloc[-STEPS_PER_HOUR:].isna().all()


def test_inputs_mark_exactly_one_solar_bin_of_the_target_time() -> None:
    current = flat_frame(288)
    forecast = base_forecast(current, 0.0)

    x = inputs(forecast, current, "T", 1, longitude=0.0)

    solar = x.filter(like="solar_")
    assert solar.sum(axis=1).eq(1.0).all()
    assert solar.shape[1] == len(SOLAR_BIN_EDGES) + 1


def test_morning_bins_are_twenty_minutes_and_the_rest_of_the_day_an_hour() -> None:
    current = flat_frame(288)
    forecast = base_forecast(current, 0.0)
    bin_of = lambda clock: inputs(forecast, current, "T", 1, 0.0).filter(like="solar_").idxmax(axis=1)[  # noqa: E731
        pd.Timestamp(f"2026-09-06 {clock}", tz="UTC") - pd.Timedelta(hours=1)
    ]

    assert bin_of("07:00") == bin_of("07:10")
    assert bin_of("07:10") != bin_of("07:30")
    assert bin_of("13:00") == bin_of("13:50")
    assert bin_of("13:50") != bin_of("14:00")
    assert bin_of("02:00") == bin_of("02:50")


def test_solar_bin_follows_the_longitude() -> None:
    current = flat_frame(288)
    forecast = base_forecast(current, 0.0)
    issued = pd.Timestamp("2026-09-06 05:00", tz="UTC")

    at_greenwich = inputs(forecast, current, "T", 1, 0.0).filter(like="solar_").loc[issued]
    at_fifteen_east = inputs(forecast, current, "T", 1, 15.0).filter(like="solar_").loc[issued]
    # 15 degrees east, one hour ahead of UTC: the same bin as Greenwich an hour later.
    later = inputs(forecast, current, "T", 1, 0.0).filter(like="solar_").loc[issued + pd.Timedelta(hours=1)]

    assert at_fifteen_east.equals(later)
    assert not at_fifteen_east.equals(at_greenwich)


def test_error_inputs_are_what_was_already_verified_at_issue_time() -> None:
    current = grid_frame(288, T=0.0, H=50.0, P=1000.0)
    bias = pd.Series(np.arange(288, dtype=float), index=current.index)
    forecast = base_forecast(current, bias)

    x = inputs(forecast, current, "T", 3, LONGITUDE)

    # error at issue time j is bias[j]; the 3 h and 1 h forecasts issued 3 h / 1 h ago are verified now.
    assert x["error_same"].iloc[100] == 100 - 3 * STEPS_PER_HOUR
    assert x["error_1h"].iloc[100] == 100 - STEPS_PER_HOUR
    assert x["error_same"].iloc[0] == 0.0
    assert not x.isna().any().any()


def test_no_correction_is_fitted_from_too_short_a_history() -> None:
    current = weather_frame(MIN_HISTORY_ROWS - 1)

    assert fit(base_forecast(current, 2.0), current, HORIZONS, LONGITUDE) == {}


def test_no_correction_is_fitted_when_since_leaves_too_little_to_learn_from() -> None:
    current = weather_frame(MIN_HISTORY_ROWS + 200)
    since = current.index[300]

    assert fit(base_forecast(current, 2.0), current, HORIZONS, LONGITUDE, since) == {}
    assert fit(base_forecast(current, 2.0), current, HORIZONS, LONGITUDE, current.index[100]) != {}


def test_a_history_long_enough_but_never_verified_gets_no_correction() -> None:
    current = weather_frame(MIN_HISTORY_ROWS + 10)
    current.loc[current.index[1:], ["T", "H"]] = np.nan

    assert fit(base_forecast(current, 2.0), current, HORIZONS, LONGITUDE) == {}


def test_a_variable_with_nothing_verified_is_skipped_and_the_other_still_corrected() -> None:
    current = weather_frame(700)
    forecast = base_forecast(current, 2.0)
    current["H"] = np.nan

    corrections = fit(forecast, current, HORIZONS, LONGITUDE)

    assert set(corrections) == {f"T_{n}h" for n in HORIZONS}


def test_corrections_cover_t_and_h_at_every_horizon_but_not_pressure() -> None:
    current = weather_frame(700)

    corrections = fit(base_forecast(current, 2.0), current, HORIZONS, LONGITUDE)

    assert set(corrections) == {f"{variable}_{n}h" for variable in CORRECTED_VARIABLES for n in HORIZONS}
    assert CORRECTED_VARIABLES == ("T", "H")


def test_a_constant_bias_is_learned_and_removed() -> None:
    current = weather_frame(700)
    forecast = base_forecast(current, 2.0)

    corrected = apply(fit(forecast, current, HORIZONS, LONGITUDE), forecast, current, HORIZONS, LONGITUDE)

    known = current["T"].shift(-STEPS_PER_HOUR).notna()
    truth = current["T"].shift(-STEPS_PER_HOUR)[known]
    assert corrected.loc[known, "T_1h_mid"].to_numpy() == pytest.approx(truth.to_numpy(), abs=0.05)
    assert (forecast.loc[known, "T_1h_mid"] - truth).to_numpy() == pytest.approx(-2.0)


def test_a_bias_that_depends_on_solar_time_is_learned_per_bin() -> None:
    current = weather_frame(1000)
    # 3 degrees too cold for targets between 8 and 10 solar time, right otherwise.
    target = current.index + pd.Timedelta(hours=1)
    solar = (target.hour + target.minute / 60 + LONGITUDE / 15) % 24
    bias = pd.Series(np.where((solar >= 8) & (solar < 10), 3.0, 0.0), index=current.index)
    forecast = base_forecast(current, bias)

    corrected = apply(fit(forecast, current, HORIZONS, LONGITUDE), forecast, current, HORIZONS, LONGITUDE)

    truth = current["T"].shift(-STEPS_PER_HOUR)
    known = truth.notna()
    morning = known & (bias == 3.0)
    before = (forecast["T_1h_mid"] - truth)[morning].abs().mean()
    after = (corrected["T_1h_mid"] - truth)[morning].abs().mean()
    assert before == pytest.approx(3.0)
    # Ridge shrinks a little.
    assert after < 1.0
    assert (corrected["T_1h_mid"] - truth)[known & ~morning].abs().mean() < 0.5


def test_the_range_is_rescaled_until_it_holds_four_readings_in_five() -> None:
    current = weather_frame(1500)
    rng = np.random.default_rng(7)
    noise = pd.Series(rng.normal(0, 2.0, len(current)), index=current.index)
    forecast = base_forecast(current, noise, spread=0.2)

    corrected = apply(fit(forecast, current, HORIZONS, LONGITUDE), forecast, current, HORIZONS, LONGITUDE)

    truth = current["T"].shift(-STEPS_PER_HOUR)
    known = truth.notna()
    held = ((truth >= corrected["T_1h_low"]) & (truth <= corrected["T_1h_high"]))[known].mean()
    original = ((truth >= forecast["T_1h_low"]) & (truth <= forecast["T_1h_high"]))[known].mean()
    assert original < 0.2
    assert held >= 0.79
    assert held < 0.85


def test_a_range_that_was_too_wide_is_narrowed() -> None:
    current = weather_frame(700)
    forecast = base_forecast(current, 0.0, spread=5.0)

    corrected = apply(fit(forecast, current, HORIZONS, LONGITUDE), forecast, current, HORIZONS, LONGITUDE)

    before = (forecast["T_1h_high"] - forecast["T_1h_low"]).mean()
    after = (corrected["T_1h_high"] - corrected["T_1h_low"]).mean()
    assert before == pytest.approx(10.0)
    assert after < 1.0


def test_since_keeps_older_readings_from_teaching_the_correction() -> None:
    current = weather_frame(1000)
    bias = pd.Series(np.where(np.arange(1000) < 400, 5.0, 2.0), index=current.index)
    forecast = base_forecast(current, bias)

    corrections = fit(forecast, current, HORIZONS, LONGITUDE, since=current.index[400])
    corrected = apply(corrections, forecast, current, HORIZONS, LONGITUDE)

    shift = (corrected["T_1h_mid"] - forecast["T_1h_mid"]).iloc[600:900]
    assert shift.mean() == pytest.approx(2.0, abs=0.2)


def test_apply_leaves_pressure_and_the_input_frame_alone() -> None:
    current = weather_frame(700)
    forecast = base_forecast(current, 2.0)
    before = forecast.copy()

    corrected = apply(fit(forecast, current, HORIZONS, LONGITUDE), forecast, current, HORIZONS, LONGITUDE)

    pd.testing.assert_frame_equal(forecast, before)
    pressure = [column for column in forecast.columns if column.startswith("P_")]
    pd.testing.assert_frame_equal(corrected[pressure], forecast[pressure])
    assert not corrected["T_1h_mid"].equals(forecast["T_1h_mid"])


def test_apply_without_corrections_returns_the_forecast_unchanged() -> None:
    current = weather_frame(100)
    forecast = base_forecast(current, 2.0)

    corrected = apply({}, forecast, current, HORIZONS, LONGITUDE)

    pd.testing.assert_frame_equal(corrected, forecast)
    assert corrected is not forecast


def test_a_negative_widening_never_narrows_the_range_past_the_median() -> None:
    current = weather_frame(200)
    forecast = base_forecast(current, 0.0, spread=1.0)
    x = inputs(forecast, current, "T", 1, LONGITUDE)
    no_shift = {name: 0.0 for name in x.columns}

    corrected = apply({"T_1h": Correction(intercept=0.0, coefficients=no_shift, widen=-5.0)}, forecast, current, [1], LONGITUDE)

    assert (corrected["T_1h_low"] <= corrected["T_1h_mid"]).all()
    assert (corrected["T_1h_high"] >= corrected["T_1h_mid"]).all()
    assert corrected["T_1h_low"].to_numpy() == pytest.approx(corrected["T_1h_mid"].to_numpy())
    assert corrected["T_1h_high"].to_numpy() == pytest.approx(corrected["T_1h_mid"].to_numpy())
