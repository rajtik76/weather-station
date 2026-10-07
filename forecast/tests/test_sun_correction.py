import pandas as pd
import pytest

import sun_correction as sun
from conftest import weather_frame
from features import build_features
from forecast import predict

LONGITUDE = 13.4
DAYS = 6


def swinging(frame: pd.DataFrame) -> pd.DataFrame:
    """In-window minimum and maximum, wider while the reading rises."""
    swing = 0.2 + frame["T"].diff().clip(lower=0).fillna(0)
    return frame.assign(Tmin=frame["T"] - swing / 2, Tmax=frame["T"] + swing / 2)


@pytest.fixture
def history(bundle: dict) -> tuple[pd.DataFrame, pd.DataFrame]:
    current = swinging(weather_frame(DAYS * 144, rain=False))
    return current, predict(bundle, build_features(current, LONGITUDE), current)


def test_night_is_issue_and_target_with_the_sun_below_the_horizon() -> None:
    midnight = pd.DatetimeIndex([pd.Timestamp("2026-10-06 23:00", tz="UTC")])
    before_dawn = pd.DatetimeIndex([pd.Timestamp("2026-10-07 02:00", tz="UTC")])

    assert not sun.daytime(midnight, 1, LONGITUDE)[0]
    assert sun.daytime(before_dawn, 6, LONGITUDE)[0]


def test_inputs_read_the_errors_verified_now(history: tuple[pd.DataFrame, pd.DataFrame]) -> None:
    current, forecast = history
    x = sun.inputs(current, forecast, 3, LONGITUDE)
    now = forecast.index[-1]

    assert x.loc[now, "error_same"] == pytest.approx(current.loc[now, "T"] - forecast["T_3h_mid"].iloc[-19])
    assert x.loc[now, "error_1h"] == pytest.approx(current.loc[now, "T"] - forecast["T_1h_mid"].iloc[-7])
    assert x.loc[now, "base_change"] == pytest.approx(forecast.loc[now, "T_3h_mid"] - current.loc[now, "T"])


def test_without_minimum_and_maximum_the_swing_is_unknown(history: tuple[pd.DataFrame, pd.DataFrame]) -> None:
    current, forecast = history

    x = sun.inputs(current.drop(columns=["Tmin", "Tmax"]), forecast, 1, LONGITUDE)

    assert x[["swing", "swing_1h", "swing_smoothed"]].isna().all().all()


def test_the_slow_swing_forgets_across_a_gap(history: tuple[pd.DataFrame, pd.DataFrame]) -> None:
    current, forecast = history
    gap = current.index[-48:-6]
    current.loc[current.index[:-48], ["Tmin", "Tmax"]] = current.loc[current.index[:-48], ["T"]].to_numpy() + [[-1.5, 1.5]]
    current.loc[gap, ["Tmin", "Tmax"]] = None

    x = sun.inputs(current, forecast, 1, LONGITUDE)

    assert x["swing_smoothed"].iloc[-1] < 0.5


def test_every_horizon_is_fitted_from_enough_daytime_history(history: tuple[pd.DataFrame, pd.DataFrame], bundle: dict) -> None:
    current, forecast = history

    fitted = sun.fit(forecast, current, bundle["horizons"], LONGITUDE)

    assert [horizon.hours for horizon in fitted] == bundle["horizons"]
    assert all(horizon.trees for horizon in fitted)


def test_rows_before_since_do_not_teach(history: tuple[pd.DataFrame, pd.DataFrame], bundle: dict) -> None:
    current, forecast = history

    assert sun.fit(forecast, current, bundle["horizons"], LONGITUDE, since=forecast.index[-144]) == []


def test_the_band_holds_the_mid_and_moves_with_it_by_day(history: tuple[pd.DataFrame, pd.DataFrame], bundle: dict) -> None:
    current, forecast = history
    fitted = sun.fit(forecast, current, bundle["horizons"], LONGITUDE)
    noon = forecast.index[forecast.index.hour == 11][-1]

    answered = sun.bands(fitted, forecast.loc[:noon], current.loc[:noon], LONGITUDE)

    for horizon, band in zip(fitted, answered, strict=True):
        moved = horizon.shift(sun.inputs(current, forecast, horizon.hours, LONGITUDE).loc[[noon]])[0]
        assert band["temperature"]["mid"] == pytest.approx(forecast.loc[noon, f"T_{horizon.hours}h_mid"] + moved, abs=0.01)
        assert band["temperature"]["low"] <= band["temperature"]["mid"] <= band["temperature"]["high"]


def test_night_keeps_the_base_band(history: tuple[pd.DataFrame, pd.DataFrame], bundle: dict) -> None:
    current, forecast = history
    fitted = sun.fit(forecast, current, bundle["horizons"], LONGITUDE)
    night = forecast.index[forecast.index.hour == 22][-1]

    answered = sun.bands(fitted, forecast.loc[:night], current.loc[:night], LONGITUDE)

    assert answered[0] == {"hours": 1, "temperature": {
        name: round(float(forecast.loc[night, f"T_1h_{name}"]), 2) for name in ("low", "mid", "high")
    }}
