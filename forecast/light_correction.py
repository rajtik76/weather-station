"""light-v4: the base model run on the air under the shield, plus the shield's heating carried to the target."""

import pandas as pd

from features import STEPS_PER_HOUR
from light_gain import LightProfile, clear_lux, gain_now
from shield import COOLING, drive, heating_rate

EXPERIMENT_VERSION = "light-v4"


def heating_ahead(
    current: pd.DataFrame, heated: pd.Series, longitude: float, reference: LightProfile, horizons: list[int],
) -> dict[int, float]:
    """Heating at each horizon after the latest window, the sky held at its gain now."""
    steps = max(horizons) * STEPS_PER_HOUR
    times = pd.date_range(current.index[-1], periods=steps, freq="10min")
    rate = heating_rate(times, longitude)
    push = drive(gain_now(current, reference, longitude) * clear_lux(times, reference.envelope, longitude).to_numpy())
    push[0] = drive(current["L"].to_numpy(dtype=float)[-1:])[0]
    level = float(heated.iloc[-1])
    ahead = {}
    for step in range(steps):
        level = level * (1 - COOLING) + rate[step] * push[step]
        if (step + 1) % STEPS_PER_HOUR == 0:
            ahead[(step + 1) // STEPS_PER_HOUR] = level
    return {n: ahead[n] for n in horizons}


def bands(air_forecast: pd.Series, ahead: dict[int, float]) -> list[dict]:
    """The air forecast's temperature band moved up by the heating at its horizon."""
    return [
        {"hours": n, "temperature": {
            name: round(float(air_forecast[f"T_{n}h_{name}"]) + heat, 2) for name in ("low", "mid", "high")
        }}
        for n, heat in ahead.items()
    ]
