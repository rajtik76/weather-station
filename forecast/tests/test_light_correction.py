import numpy as np
import pandas as pd
import pytest

import light_correction as light
from conftest import grid_frame
from correction import SOLAR_INPUTS, Correction, inputs
from light_gain import LightProfile


def temperature_forecast(current: pd.DataFrame, horizons: list[int]) -> pd.DataFrame:
    return pd.DataFrame({
        f"T_{n}h_{name}": value
        for n in horizons for name, value in (("low", 8.0), ("mid", 10.0), ("high", 12.0))
    }, index=current.index)


def test_light_only_scales_solar_inputs() -> None:
    current = grid_frame(144, T=10.0, H=50.0, P=980.0, L=200.0)
    forecast = temperature_forecast(current, [1, 4])
    gains = pd.Series(0.2, index=current.index)
    standard = inputs(forecast, current, "T", 4, 13.4)
    gated = light.light_inputs(forecast, current, 4, 13.4, gains)

    pd.testing.assert_frame_equal(gated[["error_same", "error_1h"]], standard[["error_same", "error_1h"]])
    assert gated[list(SOLAR_INPUTS)].sum(axis=1).to_numpy() == pytest.approx(0.2)


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


def test_history_without_light_does_not_teach_the_experiment() -> None:
    current = grid_frame(6 * 144, T=10.0, H=50.0, P=980.0, L=np.nan)

    assert light.fit(temperature_forecast(current, [1]), current, [1], 13.4, None) == {}


def test_apply_preserves_base_and_never_crosses_the_median() -> None:
    current = grid_frame(144, T=10.0, H=50.0, P=980.0, L=200.0)
    forecast = temperature_forecast(current, [1])
    before = forecast.copy()
    fitted = {"T_1h": Correction(0.0, {name: 2.0 for name in SOLAR_INPUTS}, -10.0)}

    answer = light.apply(fitted, forecast, current, [1], 13.4, LightProfile("2026-09-06", [1000.0] * 48, [0.2] * 48))

    pd.testing.assert_frame_equal(before, forecast)
    assert answer["T_1h_mid"].iloc[-1] == pytest.approx(10.4)
    assert answer["T_1h_low"].eq(answer["T_1h_mid"]).all()
    assert answer["T_1h_high"].eq(answer["T_1h_mid"]).all()


def test_apply_in_the_dark_scales_by_the_targets_mean_gain() -> None:
    current = grid_frame(144, T=10.0, H=50.0, P=980.0, L=0.0)
    forecast = temperature_forecast(current, [1, 3])
    fitted = {"T_3h": Correction(0.0, {name: 2.0 for name in SOLAR_INPUTS}, 0.0)}

    answer = light.apply(fitted, forecast, current, [3], 13.4, LightProfile("2026-09-06", [0.0] * 48, [0.25] * 48))

    assert answer["T_3h_mid"].to_numpy() == pytest.approx(10.5)
