"""Download ČHMÚ 10-minute station history and pack it into one parquet per station.

Source: https://opendata.chmi.cz/meteorology/climate/historical_csv/ (CC BY 4.0, ČHMÚ).
Only the professional "20000" series stations are used: they are the ones that
measure temperature, humidity, station pressure and precipitation together,
which is what the balcony station has (plus precipitation as a training label).

    uv run fetch_chmi.py download   # raw CSVs into data/raw, resumable
    uv run fetch_chmi.py build      # data/stations/<wsi>.parquet + data/stations.csv
"""

import re
import sys
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

import pandas as pd

BASE = "https://opendata.chmi.cz/meteorology/climate/historical_csv"
YEARS = range(2018, 2026)
SERIES = "20000"

# element code -> directory on the server
ELEMENTS = {
    "T": "temperature",
    "H": "humidity",
    "P": "air_pressure",
    "SRA10M": "precipitation",
}

# QUALITY codes from meta4.csv: 0 good, 3 estimated, 5 unknown are kept;
# 1 suspect, 2 poor and 4 missing are dropped.
GOOD_QUALITY = {0.0, 3.0, 5.0}

DATA = Path(__file__).parent / "data"
RAW = DATA / "raw"
STATIONS = DATA / "stations"

FILE_PATTERN = re.compile(rf'href="(10m-0-{SERIES}-0-(\d+)-([A-Z0-9]+)-(\d{{6}})\.csv)"')


def fetch(url: str, attempts: int = 4) -> bytes:
    for attempt in range(attempts):
        try:
            with urllib.request.urlopen(url, timeout=60) as response:
                return response.read()
        except OSError:
            if attempt == attempts - 1:
                raise
            time.sleep(2**attempt)
    raise AssertionError("unreachable")


def list_files() -> list[tuple[str, Path]]:
    """Every (url, local path) pair for the wanted elements and years."""
    files = []
    for element, directory in ELEMENTS.items():
        for year in YEARS:
            listing = fetch(f"{BASE}/data/10min/{directory}/{year}/").decode()
            for name, _station, file_element, _month in FILE_PATTERN.findall(listing):
                if file_element == element:
                    url = f"{BASE}/data/10min/{directory}/{year}/{name}"
                    files.append((url, RAW / element / str(year) / name))
    return files


def download() -> None:
    files = [(url, path) for url, path in list_files() if not path.exists()]
    print(f"{len(files)} files to download")

    def save(url: str, path: Path) -> None:
        body = fetch(url)
        path.parent.mkdir(parents=True, exist_ok=True)
        partial = path.with_suffix(".part")
        partial.write_bytes(body)
        partial.rename(path)

    with ThreadPoolExecutor(max_workers=8) as pool:
        futures = [pool.submit(save, url, path) for url, path in files]
        for done, future in enumerate(as_completed(futures), 1):
            future.result()
            if done % 500 == 0:
                print(f"  {done}/{len(files)}")

    meta = fetch(f"{BASE}/metadata/meta1.csv")
    (DATA / "meta1.csv").write_bytes(meta)


def build() -> None:
    STATIONS.mkdir(parents=True, exist_ok=True)
    by_station: dict[str, list[Path]] = {}
    for path in RAW.rglob("*.csv"):
        wsi = "-".join(path.name.split("-")[1:5])
        by_station.setdefault(wsi, []).append(path)

    for wsi, paths in sorted(by_station.items()):
        frames = [
            pd.read_csv(path, usecols=["ELEMENT", "DT", "VALUE", "QUALITY"])
            for path in paths
        ]
        long = pd.concat(frames, ignore_index=True)
        long = long[long["QUALITY"].isin(GOOD_QUALITY)]
        # A few files carry filler such as "#####" in VALUE.
        long["VALUE"] = pd.to_numeric(long["VALUE"], errors="coerce")
        long["DT"] = pd.to_datetime(long["DT"], utc=True)
        wide = (
            long.pivot_table(index="DT", columns="ELEMENT", values="VALUE", aggfunc="first")
            .reindex(columns=list(ELEMENTS))
            .astype("float32")
        )
        # A regular 10-minute grid, so shifts in the feature code mean fixed time offsets.
        grid = pd.date_range(wide.index.min(), wide.index.max(), freq="10min", name="time")
        wide = wide.reindex(grid)
        wide.to_parquet(STATIONS / f"{wsi}.parquet")
        print(f"{wsi}: {len(wide)} rows, complete {wide.notna().all(axis=1).mean():.0%}")

    meta = pd.read_csv(DATA / "meta1.csv")
    meta = meta[meta["WSI"].isin(by_station)]
    # The latest record per station is its current location; GEOGR1 is longitude.
    current = meta.sort_values("END_DATE").groupby("WSI").tail(1)
    current = current.rename(
        columns={"WSI": "wsi", "FULL_NAME": "name", "GEOGR1": "lon", "GEOGR2": "lat", "ELEVATION": "elevation"}
    )[["wsi", "name", "lat", "lon", "elevation"]]
    current.to_csv(DATA / "stations.csv", index=False)


if __name__ == "__main__":
    {"download": download, "build": build}[sys.argv[1]]()
