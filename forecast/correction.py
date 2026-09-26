"""Correct the ČHMÚ-trained forecast for one particular station.

A balcony is not a ČHMÚ screen on a lawn: morning sun, the warm wall and
the sensor itself give it its own dynamics. The station's own history
shows how the base models go wrong there, so the correction is refitted
from that history on every run - nothing is stored, and it sharpens as the
record grows.

Per variable and horizon, a ridge regression predicts the base model's
error from
  - the solar time of the target time, in bins: recurring daily effects
    such as the morning sun. Twenty minutes through the morning, when the
    sun warms the shield within minutes, an hour elsewhere; two harmonics
    smeared the morning's warming into the night and the afternoon,
  - the error of the forecast that verified just now for the same horizon,
    and of the latest 1-hour forecast: whatever is off today.
Then the 10-90 % range is widened or narrowed so that it holds 80 % of the
station's own readings (conformalized quantile regression).

All frames must sit on the regular 10-minute grid (gaps as NaN rows), since
the error inputs are fixed shifts.
"""

from dataclasses import dataclass

import numpy as np
import pandas as pd
from sklearn.linear_model import Ridge

from features import STEPS_PER_HOUR

MIN_HISTORY_ROWS = 3 * 24 * STEPS_PER_HOUR
RIDGE_ALPHA = 10.0
RANGE_COVERAGE = 0.8

# Raised whenever the correction's logic changes, so a stored forecast says
# which one made it: 1 fitted two daily harmonics, 2 the solar-time bins.
CORRECTION_VERSION = 2

# Solar hours of the morning bins, and the edges of every bin over the day.
MORNING = (5, 12)
SOLAR_BIN_EDGES = [
    *range(1, MORNING[0]),
    *(MORNING[0] + step / 3 for step in range(3 * (MORNING[1] - MORNING[0]) + 1)),
    *range(MORNING[1] + 1, 24),
]

# Pressure has no balcony-specific dynamics; correcting it scored worse.
CORRECTED_VARIABLES = ("T", "H")


@dataclass
class Correction:
    shift: Ridge
    widen: float


def errors(forecast: pd.DataFrame, current: pd.DataFrame, variable: str, n: int) -> pd.Series:
    """Truth minus median forecast, indexed by issue time."""
    truth = current[variable].shift(-n * STEPS_PER_HOUR)
    return truth - forecast[f"{variable}_{n}h_mid"]


def inputs(forecast: pd.DataFrame, current: pd.DataFrame, variable: str, n: int, longitude: float) -> pd.DataFrame:
    """What the correction knows at issue time t."""
    target_time = forecast.index + pd.Timedelta(hours=n)
    solar = (target_time.hour + target_time.minute / 60 + longitude / 15) % 24
    bins = np.digitize(solar, SOLAR_BIN_EDGES)
    frame = pd.DataFrame(
        {
            **{f"solar_{b}": (bins == b).astype(float) for b in range(len(SOLAR_BIN_EDGES) + 1)},
            # Issued n hours ago and verified at t, so already known at t.
            "error_same": errors(forecast, current, variable, n).shift(n * STEPS_PER_HOUR),
            "error_1h": errors(forecast, current, variable, 1).shift(STEPS_PER_HOUR),
        },
        index=forecast.index,
    )
    return frame.fillna(0.0)


def fit(forecast: pd.DataFrame, current: pd.DataFrame, horizons: list[int], longitude: float) -> dict:
    """One Correction per 'T_3h'-style target; empty while the history is short."""
    if len(forecast) < MIN_HISTORY_ROWS:
        return {}
    corrections = {}
    for n in horizons:
        for variable in CORRECTED_VARIABLES:
            target = errors(forecast, current, variable, n)
            known = target.notna()
            x = inputs(forecast, current, variable, n, longitude)[known]
            shift = Ridge(alpha=RIDGE_ALPHA).fit(x, target[known])

            truth = current[variable].shift(-n * STEPS_PER_HOUR)[known]
            moved = shift.predict(x)
            low = forecast.loc[known, f"{variable}_{n}h_low"] + moved
            high = forecast.loc[known, f"{variable}_{n}h_high"] + moved
            # How far the truth fell outside the range (negative: inside).
            outside = np.maximum(low - truth, truth - high)
            level = min(1.0, RANGE_COVERAGE * (1 + 1 / len(outside)))
            corrections[f"{variable}_{n}h"] = Correction(shift, float(np.quantile(outside, level)))
    return corrections


def apply(
    corrections: dict, forecast: pd.DataFrame, current: pd.DataFrame, horizons: list[int], longitude: float
) -> pd.DataFrame:
    corrected = forecast.copy()
    for n in horizons:
        for variable in CORRECTED_VARIABLES:
            correction = corrections.get(f"{variable}_{n}h")
            if correction is None:
                continue
            moved = correction.shift.predict(inputs(forecast, current, variable, n, longitude))
            names = [f"{variable}_{n}h_{name}" for name in ("low", "mid", "high")]
            corrected[names[1]] = forecast[names[1]] + moved
            corrected[names[0]] = forecast[names[0]] + moved - correction.widen
            corrected[names[2]] = forecast[names[2]] + moved + correction.widen
            # A negative widen may narrow the range, but never past the median.
            corrected[names[0]] = corrected[names[0]].clip(upper=corrected[names[1]])
            corrected[names[2]] = corrected[names[2]].clip(lower=corrected[names[1]])
    return corrected
