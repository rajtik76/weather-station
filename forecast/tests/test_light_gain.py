import numpy as np
import pandas as pd
import pytest

import light_gain as light
from conftest import grid_frame


def gains_of(current: pd.DataFrame, values: list) -> tuple[pd.Series, pd.Series]:
    smoothed = light.illumination(current)
    return light.daylight_gain(smoothed, current["L"], values), light.realized_gain(smoothed, current["L"], values)


def test_reference_uses_previous_days_and_excludes_late_arrivals() -> None:
    current = grid_frame(4 * 144, L=1000.0)
    current["received_at"] = current.index.astype("int64") / 1e9
    day = light.local_day(current.index[-1])
    before = current.index < day
    current.loc[before, "received_at"] = day.timestamp() + 100

    profile = light.profile(current, day)

    assert profile.values == [None] * 48
    assert profile.gains == [None] * 48


def test_cloudy_light_reduces_gain_and_bright_light_is_capped() -> None:
    current = grid_frame(6, L=200.0)

    assert gains_of(current, [1000.0] * 48)[0].iloc[-1] == pytest.approx(0.2)
    current["L"] = 2000.0
    assert gains_of(current, [1000.0] * 48)[0].iloc[-1] == 1.0


@pytest.mark.parametrize("values", [[0.0] * 48, [99.0] * 48])
def test_dark_has_no_daylight_gain_and_realizes_full_gain(values: list) -> None:
    daylight, realized = gains_of(grid_frame(6, L=0.0), values)

    assert daylight.isna().all()
    assert realized.eq(1.0).all()


def test_missing_reference_or_light_leaves_the_gain_unknown() -> None:
    current = grid_frame(6, L=200.0)
    current.loc[current.index[-1], "L"] = np.nan

    assert pd.isna(gains_of(current, [1000.0] * 48)[1].iloc[-1])
    assert gains_of(grid_frame(6, L=200.0), [None] * 48)[1].isna().all()


def test_future_light_cannot_change_historical_gains() -> None:
    current = grid_frame(4 * 144, L=1000.0)
    before = light.historical_gains(current)
    current.loc[current.index[400:], "L"] = 100_000.0
    after = light.historical_gains(current)

    for earlier, later in zip(before, after, strict=True):
        pd.testing.assert_series_equal(earlier.iloc[:400], later.iloc[:400])


def test_profile_gains_are_the_mean_daylight_gain_per_half_hour() -> None:
    current = grid_frame(3 * 144, L=lambda step: np.where(step % 144 < 72, 0.0, np.where(step < 288, 1000.0, 500.0)))
    day = light.local_day(current.index[-1] + pd.Timedelta(days=1))
    daylight, _ = light.historical_gains(current)
    phases = light.phases(current.index)
    afternoon = int(phases[100])

    gains = light.profile(current, day).gains

    assert gains[afternoon] == pytest.approx(daylight[phases == afternoon].mean())
    assert gains[int(phases[30])] is None


def test_expected_gain_fades_from_now_into_the_half_hours_mean() -> None:
    current = grid_frame(6, L=200.0)
    reference = light.LightProfile("2026-09-06", [1000.0] * 48, [0.8] * 48)

    assert light.expected_gain(current, reference, 3).iloc[-1] == pytest.approx(0.5 * 0.2 + 0.5 * 0.8)
    assert light.expected_gain(current, reference, light.GAIN_FADE_HOURS).iloc[-1] == pytest.approx(0.8)


def test_expected_gain_in_the_dark_is_the_targets_mean_and_full_without_one() -> None:
    current = grid_frame(6, L=0.0)
    gains = [None] * 48
    gains[int(light.phases(current.index[-1:] + pd.Timedelta(hours=2))[0])] = 0.3

    assert light.expected_gain(current, light.LightProfile("2026-09-06", [0.0] * 48, gains), 2).iloc[-1] == pytest.approx(0.3)
    assert light.expected_gain(current, light.LightProfile("2026-09-06", [0.0] * 48, [None] * 48), 2).iloc[-1] == 1.0
