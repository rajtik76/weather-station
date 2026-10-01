"""Turning feature rows and a model bundle into a forecast."""

import numpy as np
import pandas as pd
import pytest

from conftest import Constant, weather_frame
from features import build_features
from forecast import predict


def constant_bundle(horizons: list[int], low: float, mid: float, high: float, rain: float) -> dict:
    models: dict[str, Constant] = {}
    for n in horizons:
        for variable in ("T", "H", "P"):
            models[f"{variable}_{n}h_low"] = Constant(low)
            models[f"{variable}_{n}h_mid"] = Constant(mid)
            models[f"{variable}_{n}h_high"] = Constant(high)
        models[f"rain_{n}h"] = Constant(rain)
    return {"features": ["T", "H"], "horizons": horizons, "models": models}


def test_forecast_is_the_current_value_plus_the_predicted_change() -> None:
    current = weather_frame(10)
    bundle = constant_bundle([2], low=-1.0, mid=0.5, high=2.0, rain=0.25)

    forecast = predict(bundle, build_features(current, 13.4), current)

    for variable in ("T", "H", "P"):
        assert forecast[f"{variable}_2h_low"].to_numpy() == pytest.approx(current[variable].to_numpy() - 1.0)
        assert forecast[f"{variable}_2h_mid"].to_numpy() == pytest.approx(current[variable].to_numpy() + 0.5)
        assert forecast[f"{variable}_2h_high"].to_numpy() == pytest.approx(current[variable].to_numpy() + 2.0)
    assert forecast["rain_2h"].tolist() == [0.25] * 10
    assert forecast.index.equals(current.index)


def test_quantiles_that_cross_are_put_back_in_order() -> None:
    current = weather_frame(5)
    # Separately trained quantile models can disagree: low above high.
    bundle = constant_bundle([1], low=3.0, mid=1.0, high=-2.0, rain=0.1)

    forecast = predict(bundle, build_features(current, 13.4), current)

    assert (forecast["T_1h_low"] - current["T"]).tolist() == pytest.approx([-2.0] * 5)
    assert (forecast["T_1h_mid"] - current["T"]).tolist() == pytest.approx([1.0] * 5)
    assert (forecast["T_1h_high"] - current["T"]).tolist() == pytest.approx([3.0] * 5)


def test_only_the_bundles_horizons_and_features_are_used() -> None:
    current = weather_frame(5)
    bundle = constant_bundle([1, 4], low=-1.0, mid=0.0, high=1.0, rain=0.5)

    forecast = predict(bundle, build_features(current, 13.4), current)

    assert {column.split("_")[1] for column in forecast.columns} == {"1h", "4h"}
    assert len(forecast.columns) == 2 * (3 * 3 + 2)


def test_a_fitted_bundle_gives_ordered_finite_ranges_and_probabilities(bundle: dict) -> None:
    current = weather_frame(300)

    forecast = predict(bundle, build_features(current, 13.4), current)

    for n in bundle["horizons"]:
        for variable in ("T", "H", "P"):
            low, mid, high = (forecast[f"{variable}_{n}h_{name}"] for name in ("low", "mid", "high"))
            assert np.isfinite(forecast[[low.name, mid.name, high.name]].to_numpy()).all()
            assert (low <= mid).all()
            assert (mid <= high).all()
        assert forecast[f"rain_{n}h"].between(0, 1).all()


def test_rain_within_the_first_hour_is_no_likelier_than_within_two() -> None:
    current = weather_frame(5)
    bundle = constant_bundle([1, 2, 3], low=-1.0, mid=0.0, high=1.0, rain=0.3)
    # The 1 h classifier on a sunny morning: 94 % while the longer horizons say almost nothing.
    bundle["models"]["rain_1h"] = Constant(0.94)
    bundle["models"]["rain_2h"] = Constant(0.01)

    forecast = predict(bundle, build_features(current, 13.4), current)

    assert forecast["rain_1h"].tolist() == pytest.approx([0.01] * 5)
    assert forecast["rain_2h"].tolist() == pytest.approx([0.01] * 5)
    # What the classifier said stays beside it, so a capped run can be found.
    assert forecast["rain_1h_raw"].tolist() == pytest.approx([0.94] * 5)
    # Only the first hour is capped; the others keep what their models say.
    assert forecast["rain_3h"].tolist() == pytest.approx([0.3] * 5)


def test_a_first_hour_below_the_second_is_left_alone() -> None:
    current = weather_frame(5)
    bundle = constant_bundle([1, 2], low=-1.0, mid=0.0, high=1.0, rain=0.6)
    bundle["models"]["rain_1h"] = Constant(0.2)

    forecast = predict(bundle, build_features(current, 13.4), current)

    assert forecast["rain_1h"].tolist() == pytest.approx([0.2] * 5)
