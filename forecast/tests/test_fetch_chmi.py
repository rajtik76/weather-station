"""ČHMÚ listing, retrying download and parquet build, offline."""

import urllib.request
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

import fetch_chmi
from fetch_chmi import FILE_PATTERN, fetch, list_files

LISTING = """
<a href="../">../</a>
<a href="10m-0-20000-0-11450-T-202501.csv">10m-0-20000-0-11450-T-202501.csv</a>
<a href="10m-0-20000-0-11406-SRA10M-202512.csv">10m-0-20000-0-11406-SRA10M-202512.csv</a>
<a href="10m-0-20000-0-11450-H-202501.csv">10m-0-20000-0-11450-H-202501.csv</a>
<a href="10m-0-11000-0-11450-T-202501.csv">10m-0-11000-0-11450-T-202501.csv</a>
<a href="10m-0-20000-0-11450-T-2025.csv">10m-0-20000-0-11450-T-2025.csv</a>
"""


@pytest.fixture(autouse=True)
def no_sleeping(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(fetch_chmi.time, "sleep", lambda seconds: None)


def test_the_listing_pattern_takes_only_the_professional_series_monthly_files() -> None:
    assert FILE_PATTERN.findall(LISTING) == [
        ("10m-0-20000-0-11450-T-202501.csv", "11450", "T", "202501"),
        ("10m-0-20000-0-11406-SRA10M-202512.csv", "11406", "SRA10M", "202512"),
        ("10m-0-20000-0-11450-H-202501.csv", "11450", "H", "202501"),
    ]


def test_list_files_keeps_each_elements_own_files_under_its_directory_and_year(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    requested = []

    def listing(url: str, attempts: int = 4) -> bytes:
        requested.append(url)
        return LISTING.encode()

    monkeypatch.setattr(fetch_chmi, "fetch", listing)

    files = dict(list_files())

    assert len(requested) == len(fetch_chmi.ELEMENTS) * len(fetch_chmi.YEARS)
    url = f"{fetch_chmi.BASE}/data/10min/temperature/2018/10m-0-20000-0-11450-T-202501.csv"
    assert files[url] == fetch_chmi.RAW / "T" / "2018" / "10m-0-20000-0-11450-T-202501.csv"
    assert not any("temperature" in file_url and "-H-" in file_url for file_url in files)
    assert f"{fetch_chmi.BASE}/data/10min/precipitation/2025/10m-0-20000-0-11406-SRA10M-202512.csv" in files


def test_fetch_retries_a_failing_download_and_then_gives_up(monkeypatch: pytest.MonkeyPatch) -> None:
    calls = []

    def failing(url: str, timeout: int) -> None:
        calls.append(url)
        raise OSError("connection reset")

    monkeypatch.setattr(urllib.request, "urlopen", failing)

    with pytest.raises(OSError, match="connection reset"):
        fetch("https://example.test/file.csv", attempts=3)

    assert len(calls) == 3


def test_fetch_returns_the_body_of_a_retry_that_works(monkeypatch: pytest.MonkeyPatch) -> None:
    class Response:
        def __enter__(self) -> "Response":
            return self

        def __exit__(self, *exception: object) -> None:
            return None

        def read(self) -> bytes:
            return b"body"

    outcomes = [OSError("timeout"), Response()]

    def urlopen(url: str, timeout: int) -> Response:
        outcome = outcomes.pop(0)
        if isinstance(outcome, OSError):
            raise outcome
        return outcome

    monkeypatch.setattr(urllib.request, "urlopen", urlopen)

    assert fetch("https://example.test/file.csv") == b"body"


def test_build_keeps_good_readings_on_a_regular_grid_and_writes_the_station_list(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    pytest.importorskip("pyarrow")
    raw, stations = tmp_path / "raw", tmp_path / "stations"
    monkeypatch.setattr(fetch_chmi, "DATA", tmp_path)
    monkeypatch.setattr(fetch_chmi, "RAW", raw)
    monkeypatch.setattr(fetch_chmi, "STATIONS", stations)
    (raw / "T").mkdir(parents=True)
    (raw / "SRA10M").mkdir(parents=True)
    (raw / "T" / "10m-0-20000-0-11450-T-202501.csv").write_text(
        "ELEMENT,DT,VALUE,QUALITY\n"
        "T,2025-01-01T00:00:00Z,1.5,0\n"
        "T,2025-01-01T00:10:00Z,99.0,1\n"
        "T,2025-01-01T00:20:00Z,99.0,2\n"
        "T,2025-01-01T00:30:00Z,2.5,3\n"
        "T,2025-01-01T00:40:00Z,99.0,4\n"
        "T,2025-01-01T00:50:00Z,3.5,5\n"
    )
    (raw / "SRA10M" / "10m-0-20000-0-11450-SRA10M-202501.csv").write_text(
        "ELEMENT,DT,VALUE,QUALITY\nSRA10M,2025-01-01T00:00:00Z,0.3,0\nSRA10M,2025-01-01T00:30:00Z,#####,0\n"
    )
    (tmp_path / "meta1.csv").write_text(
        "WSI,FULL_NAME,GEOGR1,GEOGR2,ELEVATION,END_DATE\n"
        "0-20000-0-11450,Plzeň-Mikulka,13.0,49.0,360,2010-01-01\n"
        "0-20000-0-11450,Plzeň-Mikulka,13.4,49.7,360,2025-01-01\n"
        "0-20000-0-99999,Elsewhere,1.0,1.0,1,2025-01-01\n"
    )

    fetch_chmi.build()

    wide = pd.read_parquet(stations / "0-20000-0-11450.parquet")
    assert list(wide.columns) == ["T", "H", "P", "SRA10M"]
    assert len(wide) == 6
    assert (wide.index.to_series().diff().dropna() == pd.Timedelta("10min")).all()
    assert wide["T"].tolist()[0] == 1.5
    assert wide["T"].isna().tolist() == [False, True, True, False, True, False]
    assert wide["SRA10M"].iloc[0] == pytest.approx(0.3)
    assert wide["SRA10M"].iloc[1:].isna().all()
    assert wide["H"].isna().all()
    assert wide.dtypes.eq(np.float32).all()

    meta = pd.read_csv(tmp_path / "stations.csv")
    assert meta.to_dict(orient="records") == [
        {"wsi": "0-20000-0-11450", "name": "Plzeň-Mikulka", "lat": 49.7, "lon": 13.4, "elevation": 360}
    ]

