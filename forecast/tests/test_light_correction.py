import pandas as pd
import pytest

import light_correction as light
from conftest import grid_frame
from light_gain import ENVELOPE_SIZE, LightProfile
from shield import COOLING, DIFFUSE_LUX

LONGITUDE = 13.4
CLEAR = LightProfile("2026-10-06", [2000.0] * ENVELOPE_SIZE)


def test_heating_only_cools_through_the_night() -> None:
    night = grid_frame(3, pd.Timestamp("2026-10-05 21:00", tz="UTC"), L=0.0)
    heated = pd.Series(2.0, index=night.index)

    ahead = light.heating_ahead(night, heated, LONGITUDE, CLEAR, [1, 2])

    assert ahead[1] == pytest.approx(2.0 * (1 - COOLING) ** 6)
    assert ahead[2] == pytest.approx(2.0 * (1 - COOLING) ** 12)


def test_heating_ahead_holds_the_sky_gain_now() -> None:
    start = pd.Timestamp("2026-10-06 05:40", tz="UTC")
    clear = grid_frame(3, start, L=2000.0)
    hazy = grid_frame(3, start, L=900.0)
    dim = grid_frame(3, start, L=DIFFUSE_LUX / 4)
    cold = pd.Series(0.0, index=clear.index)

    sunny = light.heating_ahead(clear, cold, LONGITUDE, CLEAR, [1, 2])
    half = light.heating_ahead(hazy, cold, LONGITUDE, CLEAR, [1, 2])

    assert sunny[1] > half[1] > 0.0
    assert light.heating_ahead(dim, cold, LONGITUDE, CLEAR, [1, 2]) == {1: 0.0, 2: 0.0}


def test_bands_move_the_air_forecast_by_the_heating() -> None:
    air_forecast = pd.Series({"T_1h_low": 9.0, "T_1h_mid": 10.0, "T_1h_high": 11.0})

    assert light.bands(air_forecast, {1: 2.5}) == [{"hours": 1, "temperature": {"low": 11.5, "mid": 12.5, "high": 13.5}}]
