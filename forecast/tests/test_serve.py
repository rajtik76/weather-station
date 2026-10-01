"""Service contract with the Laravel app: payload in, forecast out, 422 for bad input."""

import http.client
import json
import math
import os
import threading
from collections.abc import Iterator
from http.server import ThreadingHTTPServer
from pathlib import Path
from types import SimpleNamespace

import joblib
import pytest

import serve
from conftest import START, Constant, weather_frame
from correction import CORRECTION_VERSION, INPUTS
from serve import InvalidRequest, StaleCorrection, make_base, make_correction, make_forecast

LONGITUDE = 13.4
BAND = {"low", "mid", "high"}


def readings(hours: float, rain: bool = False, offset: int = 37) -> list[dict]:
    """What ForecastWeather sends: oldest first, offset seconds into each 10-minute window."""
    frame = weather_frame(int(hours * 6))
    return [
        {
            "timestamp": int(time.timestamp()) + offset,
            "temperature": round(float(row["T"]), 2),
            "humidity": round(float(row["H"]), 2),
            "pressure": round(float(row["P"]), 2),
            **({"rain": float(row["SRA10M"])} if rain else {}),
        }
        for time, row in frame.iterrows()
    ]


def corrected_forecast(payload: list[dict], **correction_keys: object) -> dict:
    """What ForecastWeather does: fit the correction on the history, forecast with it."""
    correction = make_correction({"longitude": LONGITUDE, "readings": payload, **correction_keys})
    return make_forecast({"longitude": LONGITUDE, "readings": payload, "correction": correction})


@pytest.fixture
def service(monkeypatch: pytest.MonkeyPatch, bundle: dict) -> dict:
    """Service on the tiny test bundle."""
    monkeypatch.setattr(serve, "model", SimpleNamespace(get=lambda: bundle))
    return bundle


@pytest.fixture
def server(service: dict) -> Iterator[tuple[str, int]]:
    httpd = ThreadingHTTPServer(("127.0.0.1", 0), serve.Handler)
    thread = threading.Thread(target=httpd.serve_forever, kwargs={"poll_interval": 0.01}, daemon=True)
    thread.start()
    yield "127.0.0.1", httpd.server_address[1]
    httpd.shutdown()
    httpd.server_close()
    thread.join()


def request(server: tuple[str, int], method: str, path: str, body: object = None) -> tuple[int, dict]:
    connection = http.client.HTTPConnection(*server, timeout=30)
    raw = body if isinstance(body, bytes) else json.dumps(body).encode() if body is not None else b""
    connection.request(method, path, raw, {"Content-Type": "application/json"})
    response = connection.getresponse()
    assert response.getheader("Content-Type") == "application/json"
    answer = json.loads(response.read())
    connection.close()
    return response.status, answer


def test_a_forecast_has_the_shape_laravel_stores(service: dict) -> None:
    answer = corrected_forecast(readings(96))

    assert set(answer) == {"issued_at", "model", "corrected", "correction", "horizons"}
    assert isinstance(answer["issued_at"], int)
    assert answer["model"] == service["trained_at"]
    assert answer["corrected"] is True
    assert answer["correction"] == CORRECTION_VERSION
    assert [horizon["hours"] for horizon in answer["horizons"]] == [1, 2, 3, 4, 5, 6]
    for horizon in answer["horizons"]:
        assert set(horizon) == {"hours", "temperature", "humidity", "pressure", "rain_probability", "base"}
        for variable in ("temperature", "humidity", "pressure"):
            assert set(horizon[variable]) == BAND
            assert horizon[variable]["low"] <= horizon[variable]["mid"] <= horizon[variable]["high"]
        assert 0 <= horizon["rain_probability"] <= 1
        assert set(horizon["base"]) == {"temperature", "humidity", "rain_probability"}
        assert set(horizon["base"]["temperature"]) == BAND
        assert round(horizon["base"]["rain_probability"], 3) == horizon["base"]["rain_probability"]
    assert json.loads(json.dumps(answer)) == answer


def test_values_are_rounded_for_storage(service: dict) -> None:
    answer = make_forecast({"longitude": LONGITUDE, "readings": readings(96)})

    for horizon in answer["horizons"]:
        assert round(horizon["temperature"]["mid"], 2) == horizon["temperature"]["mid"]
        assert round(horizon["rain_probability"], 3) == horizon["rain_probability"]


def test_a_forecast_with_any_target_left_uncorrected_is_not_called_corrected(service: dict) -> None:
    payload = readings(96)
    correction = make_correction({"longitude": LONGITUDE, "readings": payload})
    del correction["targets"]["H_1h"]

    answer = make_forecast({"longitude": LONGITUDE, "readings": payload, "correction": correction})

    assert answer["corrected"] is False


def test_a_forecast_without_a_correction_is_the_base(service: dict) -> None:
    answer = make_forecast({"longitude": LONGITUDE, "readings": readings(96)})

    assert answer["corrected"] is False
    for horizon in answer["horizons"]:
        assert horizon["temperature"] == horizon["base"]["temperature"]
        assert horizon["humidity"] == horizon["base"]["humidity"]


def test_a_correction_answers_every_corrected_target_with_plain_numbers(service: dict) -> None:
    correction = make_correction({"longitude": LONGITUDE, "readings": readings(96)})

    assert correction["model"] == service["trained_at"]
    assert correction["correction"] == CORRECTION_VERSION
    assert set(correction["targets"]) == {f"{variable}_{n}h" for n in range(1, 7) for variable in ("T", "H")}
    for fitted in correction["targets"].values():
        assert set(fitted) == {"intercept", "coefficients", "widen"}
        assert set(fitted["coefficients"]) == set(INPUTS)
    assert json.loads(json.dumps(correction)) == correction


def test_the_last_54_hours_forecast_what_the_whole_history_does(service: dict) -> None:
    payload = readings(96)
    correction = make_correction({"longitude": LONGITUDE, "readings": payload})
    lookback = payload[-(54 * 6 + 1) :]

    recent = make_forecast({"longitude": LONGITUDE, "readings": lookback, "correction": correction})
    whole = make_forecast({"longitude": LONGITUDE, "readings": payload, "correction": correction})

    assert recent["corrected"] is True
    assert recent == whole


def test_a_correction_for_another_model_or_version_is_stale(service: dict) -> None:
    payload = readings(24)
    correction = make_correction({"longitude": LONGITUDE, "readings": payload})

    for stale in ({**correction, "model": "2025-01-01T00:00:00+00:00"}, {**correction, "correction": CORRECTION_VERSION - 1}):
        with pytest.raises(StaleCorrection):
            make_forecast({"longitude": LONGITUDE, "readings": payload, "correction": stale})


@pytest.mark.parametrize(
    ("targets", "message"),
    [
        ({"P_1h": {"intercept": 0.0, "coefficients": {}, "widen": 0.0}}, "P_1h is unknown"),
        ({"T_1h": {"intercept": 0.0, "coefficients": {"sunshine": 1.0}, "widen": 0.0}}, "unknown inputs"),
        ({"T_1h": {"intercept": 0.0, "widen": 0.0}}, "needs intercept, coefficients and widen"),
        ({"T_1h": {"intercept": "0", "coefficients": {}, "widen": 0.0}}, "T_1h intercept must be a finite number"),
        ({"T_1h": {"intercept": 0.0, "coefficients": {"error_1h": True}, "widen": 0.0}}, "error_1h must be a finite"),
        ({"T_1h": {"intercept": 0.0, "coefficients": {}, "widen": 10**400}}, "T_1h widen must be a finite number"),
    ],
)
def test_a_malformed_correction_is_rejected_with_the_reason(service: dict, targets: dict, message: str) -> None:
    correction = {"model": service["trained_at"], "correction": CORRECTION_VERSION, "targets": targets}

    with pytest.raises(InvalidRequest, match=message):
        make_forecast({"longitude": LONGITUDE, "readings": readings(2), "correction": correction})


def test_issued_at_is_the_ten_minute_window_of_the_latest_reading(service: dict) -> None:
    payload = readings(96)

    answer = make_forecast({"longitude": LONGITUDE, "readings": payload})

    assert payload[-1]["timestamp"] % 600 == 37
    assert answer["issued_at"] == payload[-1]["timestamp"] - 37


def test_a_short_history_is_forecast_without_the_station_correction(service: dict) -> None:
    assert make_correction({"longitude": LONGITUDE, "readings": readings(24)})["targets"] == {}

    answer = corrected_forecast(readings(24))

    assert answer["corrected"] is False
    for horizon in answer["horizons"]:
        assert horizon["temperature"] == horizon["base"]["temperature"]
        assert horizon["humidity"] == horizon["base"]["humidity"]


def test_since_keeps_the_correction_off_but_not_the_base_models(service: dict) -> None:
    payload = readings(96)
    since = START.timestamp() + 60 * 3600

    with_since = corrected_forecast(payload, since=int(since))
    without = corrected_forecast(payload)

    assert without["corrected"] is True
    # 36 h after since: too little to learn from.
    assert with_since["corrected"] is False
    assert [h["base"] for h in with_since["horizons"]] == [h["base"] for h in without["horizons"]]
    assert [h["pressure"] for h in with_since["horizons"]] == [h["pressure"] for h in without["horizons"]]


def test_a_null_since_is_the_same_as_none(service: dict) -> None:
    payload = readings(96)

    assert make_correction({"longitude": LONGITUDE, "readings": payload, "since": None}) == make_correction(
        {"longitude": LONGITUDE, "readings": payload}
    )


def test_rain_that_is_null_everywhere_equals_a_station_without_a_rain_source(service: dict) -> None:
    plain = readings(96)
    nulled = [{**reading, "rain": None} for reading in plain]

    assert make_forecast({"longitude": LONGITUDE, "readings": nulled}) == make_forecast(
        {"longitude": LONGITUDE, "readings": plain}
    )


def test_a_rain_source_is_accepted(service: dict) -> None:
    answer = make_forecast({"longitude": LONGITUDE, "readings": readings(96, rain=True)})

    assert len(answer["horizons"]) == 6


def test_the_order_of_the_readings_does_not_matter(service: dict) -> None:
    payload = readings(96)

    assert make_forecast({"longitude": LONGITUDE, "readings": payload[::-1]}) == make_forecast(
        {"longitude": LONGITUDE, "readings": payload}
    )


def test_gaps_and_null_values_in_the_history_are_forecast_through(service: dict) -> None:
    payload = readings(96)
    del payload[200:260]
    payload[100]["humidity"] = None
    payload[101]["pressure"] = None

    answer = make_forecast({"longitude": LONGITUDE, "readings": payload})

    assert answer["issued_at"] == payload[-1]["timestamp"] - 37
    assert len(answer["horizons"]) == 6


def test_a_single_reading_still_gets_a_forecast(service: dict) -> None:
    answer = make_forecast({"longitude": LONGITUDE, "readings": readings(1)[:1]})

    assert answer["corrected"] is False
    assert len(answer["horizons"]) == 6


def test_the_latest_reading_must_be_complete(service: dict) -> None:
    payload = readings(24)
    payload[-1]["humidity"] = None

    with pytest.raises(InvalidRequest, match="latest reading needs temperature, humidity and pressure"):
        make_forecast({"longitude": LONGITUDE, "readings": payload})


def test_the_latest_reading_may_be_missing_its_rain(service: dict) -> None:
    payload = readings(24, rain=True)
    payload[-1]["rain"] = None

    assert make_forecast({"longitude": LONGITUDE, "readings": payload})["horizons"]


@pytest.mark.parametrize(
    ("payload", "message"),
    [
        ({"readings": readings(2)}, "longitude is required"),
        ({"longitude": None, "readings": readings(2)}, "longitude is required"),
        ({"longitude": "13.4", "readings": readings(2)}, "numbers or null"),
        ({"longitude": True, "readings": readings(2)}, "numbers or null"),
        ({"longitude": LONGITUDE}, "readings must be a non-empty list"),
        ({"longitude": LONGITUDE, "readings": []}, "readings must be a non-empty list"),
        ({"longitude": LONGITUDE, "readings": {"timestamp": 1}}, "readings must be a non-empty list"),
        ({"longitude": LONGITUDE, "readings": ["x"]}, "timestamp must be an integer"),
        ({"longitude": LONGITUDE, "readings": [{"temperature": 1}]}, "timestamp must be an integer"),
        ({"longitude": LONGITUDE, "readings": [{"timestamp": "1790000000"}]}, "timestamp must be an integer"),
        ({"longitude": LONGITUDE, "readings": [{"timestamp": 1790000000.5}]}, "timestamp must be an integer"),
        ({"longitude": LONGITUDE, "readings": [{"timestamp": True}]}, "timestamp must be an integer"),
        ({"longitude": LONGITUDE, "readings": [{"timestamp": 10**20}]}, "timestamp is out of range"),
        (
            {"longitude": LONGITUDE, "readings": [{"timestamp": 1790000000, "temperature": 10**400}]},
            "finite numbers or null",
        ),
        (
            {"longitude": LONGITUDE, "readings": [{"timestamp": 1790000000, "temperature": math.inf}]},
            "finite numbers or null",
        ),
        (
            {"longitude": LONGITUDE, "readings": [{"timestamp": 1790000000, "temperature": "9.7"}]},
            "numbers or null",
        ),
        (
            {"longitude": LONGITUDE, "readings": [{"timestamp": 1790000000, "temperature": True}]},
            "numbers or null",
        ),
    ],
)
def test_malformed_payloads_are_rejected_with_the_reason(service: dict, payload: dict, message: str) -> None:
    with pytest.raises(InvalidRequest, match=message):
        make_forecast(payload)


@pytest.mark.parametrize(
    ("payload", "message"),
    [
        ({"readings": readings(2)}, "longitude is required"),
        ({"longitude": LONGITUDE}, "readings must be a non-empty list"),
        ({"longitude": LONGITUDE, "readings": readings(2), "since": "1790000000"}, "since must be an integer"),
        ({"longitude": LONGITUDE, "readings": readings(2), "since": 1790000000.5}, "since must be an integer"),
        ({"longitude": LONGITUDE, "readings": readings(2), "since": True}, "since must be an integer"),
        ({"longitude": LONGITUDE, "readings": readings(2), "since": 10**20}, "since is out of range"),
    ],
)
def test_a_correction_request_is_validated_like_a_forecast(service: dict, payload: dict, message: str) -> None:
    with pytest.raises(InvalidRequest, match=message):
        make_correction(payload)


def test_more_than_a_year_of_readings_are_refused(service: dict) -> None:
    too_many = [{"timestamp": 1790000000 + 600 * i} for i in range(serve.MAX_READINGS + 1)]

    with pytest.raises(InvalidRequest, match="at most"):
        make_forecast({"longitude": LONGITUDE, "readings": too_many})


def test_readings_spread_wider_than_the_history_are_refused(service: dict) -> None:
    recent = readings(2)
    stray = {**recent[0], "timestamp": recent[-1]["timestamp"] - serve.MAX_SPAN_SECONDS - 1}

    with pytest.raises(InvalidRequest, match="span at most"):
        make_forecast({"longitude": LONGITUDE, "readings": [stray, *recent]})


def test_a_sparse_history_is_forecast_without_the_correction(service: dict) -> None:
    newest = readings(1)[-1]
    stray = {**newest, "timestamp": newest["timestamp"] - 4 * 86_400}

    forecast = corrected_forecast([stray, newest])

    assert forecast["corrected"] is False
    assert forecast["horizons"]


def test_a_year_of_history_fits_the_span(service: dict) -> None:
    newest = readings(1)[-1]
    oldest = {**newest, "timestamp": newest["timestamp"] - serve.MAX_SPAN_SECONDS}

    assert len(serve.readings_frame([oldest, newest])) > serve.MAX_READINGS


def test_base_forecasts_every_complete_window_from_since(service: dict) -> None:
    payload = readings(96)
    payload[-2]["pressure"] = None
    since = payload[-10]["timestamp"] - 37

    answer = make_base({"longitude": LONGITUDE, "since": since, "readings": payload})

    assert answer["model"] == service["trained_at"]
    issued = [forecast["issued_at"] for forecast in answer["forecasts"]]
    assert issued == [reading["timestamp"] - 37 for reading in payload[-10:] if reading["pressure"] is not None]
    horizon = answer["forecasts"][0]["horizons"][0]
    assert set(horizon) == {"hours", "temperature", "humidity", "rain_probability"}
    assert set(horizon["temperature"]) == BAND
    assert [h["hours"] for h in answer["forecasts"][0]["horizons"]] == [1, 2, 3, 4, 5, 6]


def test_base_agrees_with_the_base_the_forecast_endpoint_returns(service: dict) -> None:
    payload = readings(96)
    last = payload[-1]["timestamp"] - 37

    forecast = make_forecast({"longitude": LONGITUDE, "readings": payload})
    base = make_base({"longitude": LONGITUDE, "since": last, "readings": payload})

    assert [forecast_at["issued_at"] for forecast_at in base["forecasts"]] == [last]
    assert [
        {"hours": h["hours"], **h["base"]} for h in forecast["horizons"]
    ] == base["forecasts"][0]["horizons"]


def test_base_with_a_since_after_the_record_has_nothing_to_give(service: dict) -> None:
    answer = make_base({"longitude": LONGITUDE, "since": 2_000_000_000, "readings": readings(24)})

    assert answer["forecasts"] == []


@pytest.mark.parametrize(
    ("payload", "message"),
    [
        ({"longitude": LONGITUDE, "readings": readings(2)}, "since must be an integer"),
        ({"longitude": LONGITUDE, "since": None, "readings": readings(2)}, "since must be an integer"),
        ({"since": 1790000000, "readings": readings(2)}, "longitude is required"),
        ({"longitude": LONGITUDE, "since": 1790000000}, "readings must be a non-empty list"),
    ],
)
def test_base_rejects_what_it_cannot_answer(service: dict, payload: dict, message: str) -> None:
    with pytest.raises(InvalidRequest, match=message):
        make_base(payload)


def test_health_reports_the_model_and_the_correction_version(server: tuple[str, int], service: dict) -> None:
    status, answer = request(server, "GET", "/health")

    assert status == 200
    assert answer == {"status": "ok", "model": service["trained_at"], "correction": CORRECTION_VERSION}


def test_unknown_paths_are_404(server: tuple[str, int]) -> None:
    assert request(server, "GET", "/forecast")[0] == 404
    assert request(server, "POST", "/elsewhere", {})[0] == 404


def test_post_correction_then_forecast_over_http_with_the_payloads_laravel_sends(server: tuple[str, int]) -> None:
    history = readings(96)

    fitted, correction = request(server, "POST", "/correction", {"longitude": 13.4, "readings": history, "since": int(START.timestamp())})
    status, answer = request(server, "POST", "/forecast", {"longitude": 13.4, "readings": history[-336:], "correction": correction})

    assert fitted == 200
    assert status == 200
    assert answer["corrected"] is True
    assert answer["correction"] == CORRECTION_VERSION
    assert len(answer["horizons"]) == 6


def test_a_stale_correction_gets_a_409(server: tuple[str, int], service: dict) -> None:
    stale = {"model": "2025-01-01T00:00:00+00:00", "correction": CORRECTION_VERSION, "targets": {}}

    status, answer = request(server, "POST", "/forecast", {"longitude": 13.4, "readings": readings(2), "correction": stale})

    assert status == 409
    assert "another model" in answer["error"]


def test_post_base_over_http(server: tuple[str, int]) -> None:
    payload = readings(96)

    status, answer = request(server, "POST", "/base", {"longitude": 13.4, "since": payload[-1]["timestamp"] - 37, "readings": payload})

    assert status == 200
    assert len(answer["forecasts"]) == 1


@pytest.mark.parametrize(
    "body",
    [b"not json", b"", b"[1, 2]", b'"text"', json.dumps({"longitude": 13.4, "readings": []}).encode()],
)
def test_bad_bodies_get_a_422_with_the_reason(server: tuple[str, int], body: bytes) -> None:
    status, answer = request(server, "POST", "/forecast", body)

    assert status == 422
    assert isinstance(answer["error"], str)
    assert answer["error"]


def test_the_model_is_reloaded_only_when_its_file_changes(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    path = tmp_path / "forecast.joblib"
    joblib.dump({"trained_at": "first"}, path)
    loads = []
    real_load = joblib.load
    monkeypatch.setattr(joblib, "load", lambda file: loads.append(file) or real_load(file))
    model = serve.Model(path)

    assert model.get()["trained_at"] == "first"
    assert model.get()["trained_at"] == "first"
    assert len(loads) == 1

    joblib.dump({"trained_at": "second"}, path)
    os.utime(path, (1, 1))

    assert model.get()["trained_at"] == "second"
    assert len(loads) == 2


def test_base_keeps_the_rain_chance_before_the_first_hour_was_capped(
    service: dict, monkeypatch: pytest.MonkeyPatch
) -> None:
    for n, chance in ((1, 0.94), (2, 0.01)):
        monkeypatch.setitem(service["models"], f"rain_{n}h", Constant(chance))

    answer = make_forecast({"longitude": LONGITUDE, "readings": readings(96)})

    first, second = answer["horizons"][0], answer["horizons"][1]
    assert first["rain_probability"] == 0.01
    assert first["base"]["rain_probability"] == 0.94
    assert second["rain_probability"] == second["base"]["rain_probability"] == 0.01
