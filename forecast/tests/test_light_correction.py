import numpy as np
import pandas as pd
import pytest

import light_correction as light
from conftest import grid_frame
from correction import SOLAR_INPUTS, Correction, inputs


def temperature_forecast(current: pd.DataFrame, horizons: list[int]) -> pd.DataFrame:
    return pd.DataFrame({
        f"T_{n}h_{name}": value
        for n in horizons for name, value in (("low", 8.0), ("mid", 10.0), ("high", 12.0))
    }, index=current.index)


def test_reference_uses_previous_days_and_excludes_late_arrivals() -> None:
    current = grid_frame(4 * 144, L=1000.0)
    current["received_at"] = current.index.astype("int64") / 1e9
    day = light.local_day(current.index[-1])
    before = current.index < day
    current.loc[before, "received_at"] = day.timestamp() + 100

    assert light.profile(current, day).values == [None] * 48


def test_cloudy_light_reduces_gain_and_bright_light_is_capped() -> None:
    current = grid_frame(6, L=200.0)
    reference = light.LightProfile("2026-09-06", [1000.0] * 48)

    assert light.gain(current, reference).iloc[-1] == pytest.approx(0.2)
    current["L"] = 2000.0
    assert light.gain(current, reference).iloc[-1] == 1.0


@pytest.mark.parametrize("reference", [[None] * 48, [0.0] * 48, [99.0] * 48])
def test_missing_reference_or_night_preserves_solar_gain(reference: list) -> None:
    current = grid_frame(6, L=0.0)

    assert light.gain(current, light.LightProfile("2026-09-06", reference)).eq(1.0).all()


def test_missing_current_light_does_not_reuse_older_illumination() -> None:
    current = grid_frame(6, L=200.0)
    current.loc[current.index[-1], "L"] = np.nan

    assert light.gain(current, light.LightProfile("2026-09-06", [1000.0] * 48)).iloc[-1] == 1.0


def test_future_light_cannot_change_historical_gains() -> None:
    current = grid_frame(4 * 144, L=1000.0)
    before = light.historical_gain(current)
    current.loc[current.index[400:], "L"] = 100_000.0

    pd.testing.assert_series_equal(before.iloc[:400], light.historical_gain(current).iloc[:400])


def test_light_only_scales_solar_inputs_with_fixed_long_horizon_shrinkage() -> None:
    current = grid_frame(144, T=10.0, H=50.0, P=980.0, L=200.0)
    forecast = temperature_forecast(current, [1, 4])
    gains = pd.Series(0.2, index=current.index)
    standard = inputs(forecast, current, "T", 4, 13.4)
    gated = light.light_inputs(forecast, current, 4, 13.4, gains)

    pd.testing.assert_frame_equal(gated[["error_same", "error_1h"]], standard[["error_same", "error_1h"]])
    assert gated[list(SOLAR_INPUTS)].sum(axis=1).to_numpy() == pytest.approx(0.1)
    assert light.light_inputs(forecast, current, 1, 13.4, gains)[list(SOLAR_INPUTS)].sum(axis=1).to_numpy() == pytest.approx(0.2)


def test_unverified_targets_cannot_teach_the_experiment() -> None:
    current = grid_frame(6 * 144, T=10.0, H=50.0, P=980.0, L=1000.0)
    forecast = temperature_forecast(current, [1])
    cutoff = current.index[600]
    fitted = light.fit(forecast, current, [1], 13.4, None, cutoff)
    current.loc[current.index > cutoff, "T"] = 1000.0

    assert light.fit(forecast, current, [1], 13.4, None, cutoff) == fitted


def test_sparse_verified_history_does_not_produce_experimental_targets() -> None:
    current = grid_frame(4 * 144, T=10.0, H=50.0, P=980.0, L=1000.0)
    current.loc[current.index[1:], "T"] = np.nan

    assert light.fit(temperature_forecast(current, [1]), current, [1], 13.4, None) == {}


def test_apply_preserves_base_and_never_crosses_the_median() -> None:
    current = grid_frame(144, T=10.0, H=50.0, P=980.0, L=200.0)
    forecast = temperature_forecast(current, [1])
    before = forecast.copy()
    fitted = {"T_1h": Correction(0.0, {name: 2.0 for name in SOLAR_INPUTS}, -10.0)}

    answer = light.apply(fitted, forecast, current, [1], 13.4, light.LightProfile("2026-09-06", [1000.0] * 48))

    pd.testing.assert_frame_equal(before, forecast)
    assert answer["T_1h_mid"].iloc[-1] == pytest.approx(10.4)
    assert answer["T_1h_low"].eq(answer["T_1h_mid"]).all()
    assert answer["T_1h_high"].eq(answer["T_1h_mid"]).all()


def test_historical_gain_uses_each_days_own_reference() -> None:
    current = grid_frame(5 * 144, L=1000.0)
    current["L"] = current["L"] * (1 + np.sin(np.arange(len(current)) / 7) / 2)
    current["received_at"] = current.index.astype("int64") / 1e9 + 600
    days = current.index.tz_convert(light.TIMEZONE).normalize()
    expected = pd.Series(1.0, index=current.index)
    for day in days.unique():
        today = days == day
        expected.loc[today] = light.gain(current, light.profile(current, day)).loc[today]

    pd.testing.assert_series_equal(light.historical_gain(current), expected)
