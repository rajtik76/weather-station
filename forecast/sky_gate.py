"""light-v6: light-v5's shift times a factor per horizon and sky state, the sky read from the illuminance alone.

Sky score of a daylight window (sun above MIN_ELEVATION): its illuminance over the brightest reading at the same UTC
clock slot in the ENVELOPE_DAYS days before the issue's local day. State at issue: in daylight the mean score of the
last hour ("day:"), otherwise of the daylight in the last 24 h ("night:"); overcast below LOW, clear above HIGH, mixed
between; without a score "unknown", which keeps light-v5. A factor is the |shift|-weighted median of the miss over
light-v5's out-of-fold shift on the training rows of its state.
"""

from dataclasses import dataclass

import numpy as np
import pandas as pd

import sun_correction
from features import STEPS_PER_HOUR
from sun import sun_position

EXPERIMENT_VERSION = "light-v6"
SLOTS = 24 * STEPS_PER_HOUR
ENVELOPE_DAYS = 14
MIN_ELEVATION = 8.0
LOW, HIGH = 0.45, 0.8
MIN_STATE_ROWS = 40
MIN_SHIFT = 0.2
MAX_FACTOR = 1.5
UNKNOWN = "unknown"
STATES = tuple(f"{when}:{sky}" for when in ("day", "night") for sky in ("overcast", "mixed", "clear"))
# A state reads back the last 24 h; one more hour covers an issue at local midnight across DST.
STATE_HISTORY = pd.Timedelta(hours=25)


@dataclass(frozen=True)
class GateHorizon:
    hours: int
    factors: dict[str, float]
    widen: float


@dataclass(frozen=True)
class Gate:
    """`envelope`: brightest illuminance per UTC clock slot before the fit's local day, None where never lit."""

    envelope: list[float | None]
    horizons: list[GateHorizon]


def illuminance(current: pd.DataFrame) -> pd.Series:
    return current["L"] if "L" in current else pd.Series(np.nan, index=current.index)


def clock_slot(index: pd.DatetimeIndex) -> np.ndarray:
    return ((index.hour * 60 + index.minute) // 10).to_numpy()


def local_midnight(time: pd.Timestamp) -> pd.Timestamp:
    return time.tz_convert(sun_correction.TIMEZONE).normalize().tz_convert("UTC")


def envelope(lux: pd.Series, before: pd.Timestamp) -> list[float | None]:
    window = lux[(lux.index >= before - pd.Timedelta(days=ENVELOPE_DAYS)) & (lux.index < before)].dropna()
    brightest = window.groupby(clock_slot(window.index)).max()
    return [float(brightest[slot]) if slot in brightest.index else None for slot in range(SLOTS)]


def sky(prefix: str, score: pd.Series) -> pd.Series:
    level = np.where(score < LOW, "overcast", np.where(score > HIGH, "clear", "mixed"))
    return prefix + pd.Series(level, index=score.index)


def states(lux: pd.Series, bright: list[float | None], longitude: float) -> pd.Series:
    """State of every window of `lux`, a contiguous run of the grid."""
    elevation, _ = sun_position(lux.index, longitude)
    daylight = pd.Series(elevation > MIN_ELEVATION, index=lux.index)
    reference = np.array([np.nan if value is None else value for value in bright])[clock_slot(lux.index)]
    score = (lux / np.maximum(reference, 1)).where(daylight)
    hour = score.rolling(STEPS_PER_HOUR, min_periods=3).mean().where(daylight)
    day = score.rolling(SLOTS, min_periods=6).mean()
    return sky("day:", hour).where(hour.notna(), sky("night:", day).where(day.notna(), UNKNOWN))


def served_states(lux: pd.Series, index: pd.DatetimeIndex, longitude: float) -> pd.Series:
    """The state each issue in `index` was served with: the envelope of its local day."""
    out = pd.Series(UNKNOWN, index=index, dtype=object)
    days = pd.Series(index.tz_convert(sun_correction.TIMEZONE).normalize(), index=index)
    for day, issued in days.groupby(days).groups.items():
        start = day.tz_convert("UTC")
        history = lux[(lux.index >= start - STATE_HISTORY) & (lux.index <= issued[-1])]
        out[issued] = states(history, envelope(lux, start), longitude).reindex(issued).fillna(UNKNOWN).to_numpy()
    return out


def weighted_median(values: np.ndarray, weights: np.ndarray) -> float:
    order = np.argsort(values)
    cumulative = np.cumsum(weights[order])
    return float(values[order][np.searchsorted(cumulative, cumulative[-1] / 2)])


def factors(state: pd.Series, residual: pd.Series, shift: np.ndarray) -> dict[str, float]:
    found = {}
    for name in STATES:
        rows = (state == name).to_numpy() & (np.abs(shift) > MIN_SHIFT)
        if rows.sum() < MIN_STATE_ROWS:
            continue
        ratio = residual.to_numpy()[rows] / shift[rows]
        found[name] = float(np.clip(weighted_median(ratio, np.abs(shift[rows])), 0, MAX_FACTOR))
    return found


def fit(
    forecast: pd.DataFrame, current: pd.DataFrame, horizons: list[int], longitude: float,
    since: pd.Timestamp | None = None,
) -> tuple[list[sun_correction.Horizon], Gate]:
    """light-v5's horizons and the gate on them, from `since` on."""
    lux = illuminance(current)
    state = served_states(lux, forecast.index, longitude)
    fitted, gates = [], []
    for n in horizons:
        horizon = sun_correction.fit_horizon(forecast, current, n, longitude, since)
        if horizon is None:
            continue
        found = factors(state.reindex(horizon.index), horizon.residual, horizon.shift)
        moved = horizon.shift * state.reindex(horizon.index).map(found).fillna(1.0).to_numpy()
        widen = sun_correction.widen(forecast.loc[horizon.index], horizon.truth, moved, n)
        fitted.append(horizon.horizon)
        gates.append(GateHorizon(n, found, widen))
    return fitted, Gate(envelope(lux, local_midnight(current.index[-1])), gates)


def bands(
    fitted: list[sun_correction.Horizon], gate: Gate, forecast: pd.DataFrame, current: pd.DataFrame, longitude: float,
) -> list[dict]:
    """Temperature band of the latest window per gated horizon; the base band at night."""
    state = states(illuminance(current), gate.envelope, longitude).iloc[-1]
    gated = {horizon.hours: horizon for horizon in gate.horizons}
    answered = []
    for horizon in fitted:
        if horizon.hours not in gated:
            continue
        shift = sun_correction.latest_shift(horizon, forecast, current, longitude)
        factor = gated[horizon.hours].factors.get(state, 1.0)
        moved = None if shift is None else shift * factor
        answered.append(sun_correction.moved_band(forecast, horizon.hours, moved, gated[horizon.hours].widen))
    return answered
