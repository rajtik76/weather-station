"""Model inputs and targets: no look-ahead, fixed offsets across gaps, per-horizon targets."""

import numpy as np
import pandas as pd
import pytest

from conftest import START, grid_frame, weather_frame
from features import HORIZONS, STEPS_PER_HOUR, build_features, build_targets, dew_point, to_grid


def test_to_grid_snaps_readings_to_their_ten_minute_window() -> None:
    index = pd.DatetimeIndex(["2026-09-06 00:00:07", "2026-09-06 00:10:41", "2026-09-06 00:39:59"], tz="UTC")
    frame = pd.DataFrame({"T": [1.0, 2.0, 3.0]}, index=index)

    grid = to_grid(frame)

    assert list(grid.index.strftime("%H:%M")) == ["00:00", "00:10", "00:20", "00:30"]
    assert grid.index.name == "time"
    assert grid["T"].tolist()[:2] == [1.0, 2.0]
    assert grid["T"].iloc[3] == 3.0


def test_to_grid_turns_missing_windows_into_nan_rows() -> None:
    index = pd.DatetimeIndex(["2026-09-06 00:00:05", "2026-09-06 00:40:05"], tz="UTC")

    grid = to_grid(pd.DataFrame({"T": [1.0, 5.0]}, index=index))

    assert len(grid) == 5
    assert grid["T"].isna().tolist() == [False, True, True, True, False]


def test_to_grid_keeps_the_first_of_two_readings_in_one_window() -> None:
    index = pd.DatetimeIndex(["2026-09-06 00:00:05", "2026-09-06 00:09:55"], tz="UTC")

    grid = to_grid(pd.DataFrame({"T": [1.0, 2.0]}, index=index))

    assert grid["T"].tolist() == [1.0]


def test_to_grid_leaves_the_input_untouched() -> None:
    index = pd.DatetimeIndex(["2026-09-06 00:00:05", "2026-09-06 00:10:05"], tz="UTC")
    frame = pd.DataFrame({"T": [1.0, 2.0]}, index=index)

    to_grid(frame)

    assert frame.index[0] == pd.Timestamp("2026-09-06 00:00:05", tz="UTC")


def test_dew_point_matches_the_magnus_formula() -> None:
    dew = dew_point(pd.Series([20.0, 20.0]), pd.Series([100.0, 50.0]))

    # Saturated air is at its dew point; 20 °C at 50 % is the textbook 9.3 °C.
    assert dew.iloc[0] == pytest.approx(20.0)
    assert dew.iloc[1] == pytest.approx(9.26, abs=0.01)


def test_dew_point_survives_zero_humidity() -> None:
    assert np.isfinite(dew_point(pd.Series([10.0]), pd.Series([0.0])).iloc[0])


@pytest.mark.parametrize("with_rain", [True, False])
@pytest.mark.parametrize("cut", [100, 300, 480, 700])
def test_features_at_t_ignore_everything_after_t(cut: int, with_rain: bool) -> None:
    frame = weather_frame(800, rain=with_rain)
    spoiled = frame.copy()
    spoiled.iloc[cut + 1 :] = 1e6

    honest = build_features(frame, 13.4).iloc[: cut + 1]
    from_spoiled = build_features(spoiled, 13.4).iloc[: cut + 1]

    pd.testing.assert_frame_equal(honest, from_spoiled)


def test_features_do_not_depend_on_where_the_frame_ends() -> None:
    frame = weather_frame(800)

    whole = build_features(frame, 13.4)
    truncated = build_features(frame.iloc[:500], 13.4)

    pd.testing.assert_frame_equal(whole.iloc[:500], truncated)


def test_features_carry_no_pressure_level() -> None:
    features = build_features(weather_frame(400), 13.4)

    assert "P" not in features.columns
    assert features.dtypes.eq("float32").all()


def test_changes_are_fixed_offsets_back_in_time() -> None:
    # 0.1 °C, 1 % and 0.01 hPa per 10-minute step.
    frame = grid_frame(400, T=lambda i: 0.1 * i, H=lambda i: 1.0 * i, P=lambda i: 900 + 0.01 * i)

    features = build_features(frame, 13.4)
    row = features.iloc[300]

    assert row["T_d1h"] == pytest.approx(0.6)
    assert row["T_d3h"] == pytest.approx(1.8)
    assert row["T_d6h"] == pytest.approx(3.6)
    assert row["T_d24h"] == pytest.approx(14.4)
    assert row["H_d1h"] == pytest.approx(6.0)
    assert row["P_d12h"] == pytest.approx(0.72)
    assert row["P_d48h"] == pytest.approx(2.88)
    # A steady fall is not speeding up.
    assert row["P_accel3h"] == pytest.approx(0.0, abs=1e-4)
    # Not enough history behind the first rows.
    assert features["T_d1h"].iloc[:STEPS_PER_HOUR].isna().all()
    assert features["P_d48h"].iloc[: 48 * STEPS_PER_HOUR].isna().all()


def test_a_gap_blanks_only_the_windows_that_reach_into_it() -> None:
    frame = grid_frame(400, T=lambda i: 0.1 * i, H=50.0, P=1000.0)
    frame.iloc[200, frame.columns.get_loc("T")] = np.nan

    features = build_features(frame, 13.4)

    # The change over one hour at 200 has no end, and at 206 no start.
    assert np.isnan(features["T_d1h"].iloc[200])
    assert np.isnan(features["T_d1h"].iloc[200 + STEPS_PER_HOUR])
    # The neighbours keep their fixed offsets instead of sliding over the hole.
    assert features["T_d1h"].iloc[201] == pytest.approx(0.6)
    assert features["T_d1h"].iloc[200 + STEPS_PER_HOUR + 1] == pytest.approx(0.6)


def test_a_thin_window_gives_nan_rather_than_a_statistic_of_a_few_readings() -> None:
    frame = grid_frame(400, T=10.0, H=50.0, P=lambda i: 1000 + 0.01 * i)
    thin = frame.copy()
    # Leave 30 of the 48 hours before row 300: fewer than the 36 the swing needs.
    thin.iloc[300 - 288 : 300 - 30, thin.columns.get_loc("P")] = np.nan

    assert np.isfinite(build_features(frame, 13.4)["P_range48h"].iloc[300])
    assert np.isnan(build_features(thin, 13.4)["P_range48h"].iloc[300])


def test_rain_inputs_sum_the_gauge_up_to_and_including_t() -> None:
    frame = grid_frame(100, T=10.0, H=50.0, P=1000.0, SRA10M=0.0)
    frame.iloc[50, frame.columns.get_loc("SRA10M")] = 0.5

    features = build_features(frame, 13.4)

    assert features["rain_past1h"].iloc[49] == 0
    assert features["rain_past1h"].iloc[50] == 0.5
    assert features["rain_past1h"].iloc[55] == 0.5
    assert features["rain_past1h"].iloc[56] == 0
    assert features["rain_past3h"].iloc[67] == 0.5
    assert features["rain_past3h"].iloc[68] == 0


def test_rain_inputs_need_every_slot_of_their_window() -> None:
    frame = grid_frame(100, T=10.0, H=50.0, P=1000.0, SRA10M=0.0)
    frame.iloc[50, frame.columns.get_loc("SRA10M")] = np.nan

    features = build_features(frame, 13.4)

    assert features["rain_past1h"].iloc[50:56].isna().all()
    assert features["rain_past1h"].iloc[56] == 0


def test_rain_inputs_are_nan_for_a_station_without_a_rain_source() -> None:
    features = build_features(grid_frame(100, T=10.0, H=50.0, P=1000.0), 13.4)

    assert features["rain_past1h"].isna().all()
    assert features["rain_past3h"].isna().all()


def test_jitter_is_computed_on_readings_rounded_to_the_chmi_resolution() -> None:
    # A sensor finer than ČHMÚ's: noise below 0.05 °C and 0.5 % vanishes.
    wobble = lambda i: np.where(i % 2 == 0, 0.0, 0.04)  # noqa: E731
    frame = grid_frame(100, T=lambda i: 10 + wobble(i), H=lambda i: 50 + wobble(i) * 10, P=1000.0)

    features = build_features(frame, 13.4)

    assert features["T_jitter3h"].iloc[60] == 0
    assert features["H_jitter3h"].iloc[60] == 0


def test_jitter_sees_a_real_swing() -> None:
    frame = grid_frame(100, T=lambda i: 10 + np.where(i % 2 == 0, 0.0, 1.0), H=50.0, P=1000.0)

    assert build_features(frame, 13.4)["T_jitter3h"].iloc[60] > 0.5


@pytest.mark.parametrize(
    ("longitude", "utc", "expected_solar_hour"),
    [
        (0.0, "12:00", 12.0),
        (15.0, "00:00", 1.0),
        (13.4, "06:00", 6 + 13.4 / 15),
        (-15.0, "00:30", 23.5),
    ],
)
def test_time_of_day_is_local_solar_time(longitude: float, utc: str, expected_solar_hour: float) -> None:
    frame = grid_frame(1, start=pd.Timestamp(f"2026-09-06 {utc}", tz="UTC"), T=10.0, H=50.0, P=1000.0)

    row = build_features(frame, longitude).iloc[0]

    assert row["hour_sin"] == pytest.approx(np.sin(2 * np.pi * expected_solar_hour / 24), abs=1e-5)
    assert row["hour_cos"] == pytest.approx(np.cos(2 * np.pi * expected_solar_hour / 24), abs=1e-5)


def test_day_of_year_is_a_cycle() -> None:
    frame = grid_frame(1, start=pd.Timestamp("2026-01-01", tz="UTC"), T=10.0, H=50.0, P=1000.0)

    row = build_features(frame, 13.4).iloc[0]

    assert row["doy_sin"] == pytest.approx(np.sin(2 * np.pi / 365.25), abs=1e-5)
    assert row["doy_cos"] == pytest.approx(np.cos(2 * np.pi / 365.25), abs=1e-5)


@pytest.mark.parametrize("n", HORIZONS)
def test_targets_are_the_change_n_hours_ahead(n: int) -> None:
    frame = grid_frame(300, T=lambda i: 0.1 * i, H=lambda i: 50 - 0.2 * i, P=lambda i: 1000 + 0.01 * i, SRA10M=0.0)
    steps = n * STEPS_PER_HOUR

    targets = build_targets(frame)

    assert targets[f"T_{n}h"].iloc[10] == pytest.approx(0.1 * steps, abs=1e-4)
    assert targets[f"H_{n}h"].iloc[10] == pytest.approx(-0.2 * steps, abs=1e-4)
    assert targets[f"P_{n}h"].iloc[10] == pytest.approx(0.01 * steps, abs=1e-4)
    # Nothing to compare with once the horizon runs past the record.
    assert targets[f"T_{n}h"].iloc[-steps:].isna().all()
    assert targets[f"T_{n}h"].iloc[-steps - 1] == pytest.approx(0.1 * steps, abs=1e-4)


def test_a_missing_future_reading_blanks_only_its_own_target() -> None:
    frame = grid_frame(300, T=lambda i: 0.1 * i, H=50.0, P=1000.0, SRA10M=0.0)
    frame.iloc[100, frame.columns.get_loc("T")] = np.nan

    targets = build_targets(frame)

    # Issued 3 h before the gap, or at the gap itself: no target; the rows around are fine.
    assert np.isnan(targets["T_3h"].iloc[100 - 3 * STEPS_PER_HOUR])
    assert np.isnan(targets["T_3h"].iloc[100])
    assert targets["T_3h"].iloc[99] == pytest.approx(1.8)
    assert targets["T_3h"].iloc[101] == pytest.approx(1.8)


def test_rain_target_looks_strictly_ahead_of_the_issue_time() -> None:
    frame = grid_frame(100, T=10.0, H=50.0, P=1000.0, SRA10M=0.0)
    frame.iloc[50, frame.columns.get_loc("SRA10M")] = 0.5

    targets = build_targets(frame)

    # Rain in slot 50 is ahead of slot 49 and earlier, but already past for slot 50.
    assert targets["rain_1h"].iloc[49] == 1
    assert targets["rain_1h"].iloc[44] == 1
    assert targets["rain_1h"].iloc[43] == 0
    assert targets["rain_1h"].iloc[50] == 0
    assert targets["rain_3h"].iloc[32] == 1
    assert targets["rain_3h"].iloc[31] == 0


def test_rain_target_threshold_is_a_tenth_of_a_millimetre() -> None:
    frame = grid_frame(60, T=10.0, H=50.0, P=1000.0, SRA10M=0.0)
    frame.iloc[30, frame.columns.get_loc("SRA10M")] = 0.05
    frame.iloc[31, frame.columns.get_loc("SRA10M")] = 0.05

    assert build_targets(frame)["rain_1h"].iloc[29] == 1

    frame.iloc[31, frame.columns.get_loc("SRA10M")] = 0.04

    assert build_targets(frame)["rain_1h"].iloc[29] == 0


def test_rain_target_is_unknown_unless_every_slot_ahead_was_reported() -> None:
    frame = grid_frame(100, T=10.0, H=50.0, P=1000.0, SRA10M=0.0)
    frame.iloc[50, frame.columns.get_loc("SRA10M")] = np.nan

    targets = build_targets(frame)

    assert targets["rain_1h"].iloc[44:50].isna().all()
    assert targets["rain_1h"].iloc[43] == 0
    assert targets["rain_1h"].iloc[50] == 0
    # The last rows have no full window ahead.
    assert targets["rain_1h"].iloc[-STEPS_PER_HOUR:].isna().all()


def test_features_start_where_the_data_starts() -> None:
    assert build_features(weather_frame(10), 13.4).index[0] == START
