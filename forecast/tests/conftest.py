"""Shared fixtures: no network, small frames, and a tiny fitted model bundle."""

import ipaddress
import socket

import numpy as np
import pandas as pd
import pytest
from sklearn.ensemble import HistGradientBoostingClassifier, HistGradientBoostingRegressor

from features import HORIZONS, build_features, build_targets
from forecast import QUANTILES, VARIABLES

# A Sunday, 00:00 UTC: a round starting point for hand-built grids.
START = pd.Timestamp("2026-09-06", tz="UTC")


@pytest.fixture(autouse=True)
def block_network(monkeypatch: pytest.MonkeyPatch) -> None:
    """Only loopback connections may happen: the HTTP tests talk to a local server."""
    connect = socket.socket.connect

    def guarded(self: socket.socket, address: object) -> None:
        if isinstance(address, tuple) and not ipaddress.ip_address(address[0]).is_loopback:
            raise AssertionError(f"test tried to reach {address[0]}")
        return connect(self, address)

    monkeypatch.setattr(socket.socket, "connect", guarded)


def grid_frame(steps: int, start: pd.Timestamp = START, **columns: object) -> pd.DataFrame:
    """A frame on the 10-minute grid; each column is a scalar or a callable of the step index."""
    index = pd.date_range(start, periods=steps, freq="10min", name="time")
    steps_index = np.arange(steps)
    data = {name: value(steps_index) if callable(value) else np.full(steps, value) for name, value in columns.items()}
    return pd.DataFrame(data, index=index).astype(float)


def weather_frame(steps: int, rain: bool = True) -> pd.DataFrame:
    """A smooth, day-shaped series with a few rain showers, deterministic."""
    rng = np.random.default_rng(1)
    columns: dict[str, object] = {
        "T": lambda i: 12 + 6 * np.sin(2 * np.pi * i / 144) + rng.normal(0, 0.1, len(i)),
        "H": lambda i: 70 - 15 * np.sin(2 * np.pi * i / 144) + rng.normal(0, 0.5, len(i)),
        "P": lambda i: 980 + 3 * np.sin(2 * np.pi * i / 700) + rng.normal(0, 0.05, len(i)),
    }
    if rain:
        columns["SRA10M"] = lambda i: np.where((i % 97) < 4, 0.3, 0.0)
    return grid_frame(steps, **columns)


class Constant:
    """A stand-in model that always gives the same answer."""

    def __init__(self, value: float) -> None:
        self.value = value

    def predict(self, features: pd.DataFrame) -> np.ndarray:
        return np.full(len(features), self.value)

    def predict_proba(self, features: pd.DataFrame) -> np.ndarray:
        return np.column_stack([np.full(len(features), 1 - self.value), np.full(len(features), self.value)])


@pytest.fixture(scope="session")
def bundle() -> dict:
    """The same structure train.py writes, fitted on a week of synthetic weather."""
    frame = weather_frame(7 * 144)
    rows = build_features(frame, 13.4).join(build_targets(frame))
    names = list(build_features(frame, 13.4).columns)
    models = {}
    for n in HORIZONS:
        for variable in VARIABLES:
            fit = rows[rows[f"{variable}_{n}h"].notna()]
            for name, quantile in QUANTILES.items():
                model = HistGradientBoostingRegressor(loss="quantile", quantile=quantile, max_iter=5, random_state=0)
                models[f"{variable}_{n}h_{name}"] = model.fit(fit[names], fit[f"{variable}_{n}h"])
        fit = rows[rows[f"rain_{n}h"].notna()]
        models[f"rain_{n}h"] = HistGradientBoostingClassifier(max_iter=5, random_state=0).fit(
            fit[names], fit[f"rain_{n}h"].astype(int)
        )
    return {"features": names, "horizons": list(HORIZONS), "models": models, "trained_at": "2026-09-24T08:40:43+00:00"}
