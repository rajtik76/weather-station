"""Station correction of the base forecast: ridge on solar-time bins and recent errors, conformal 10-90 % range.

Frames must be on the regular 10-minute grid (gaps as NaN rows): the error inputs are fixed shifts.
"""

from dataclasses import dataclass

import numpy as np
import pandas as pd
from sklearn.linear_model import Ridge

from features import STEPS_PER_HOUR
from sun import solar_hour

MIN_HISTORY_ROWS = 3 * 24 * STEPS_PER_HOUR
RIDGE_ALPHA = 10.0
RANGE_COVERAGE = 0.8

# Bump when the correction logic changes; stored forecasts record it.
CORRECTION_VERSION = 3

# Solar-hour bin edges: 20-minute bins in the morning (fast sun warming), hourly elsewhere.
MORNING = (5, 12)
SOLAR_BIN_EDGES = [
    *range(1, MORNING[0]),
    *(MORNING[0] + step / 3 for step in range(3 * (MORNING[1] - MORNING[0]) + 1)),
    *range(MORNING[1] + 1, 24),
]

# Pressure is left uncorrected (correcting it scored worse).
CORRECTED_VARIABLES = ("T", "H")

SOLAR_INPUTS = tuple(f"solar_{b}" for b in range(len(SOLAR_BIN_EDGES) + 1))
INPUTS = (*SOLAR_INPUTS, "error_same", "error_1h")


@dataclass
class Correction:
    intercept: float
    coefficients: dict[str, float]
    widen: float

    def shift(self, x: pd.DataFrame) -> np.ndarray:
        return x[list(self.coefficients)].to_numpy() @ np.array(list(self.coefficients.values())) + self.intercept


def errors(forecast: pd.DataFrame, current: pd.DataFrame, variable: str, n: int) -> pd.Series:
    """Truth minus median forecast, by issue time."""
    truth = current[variable].shift(-n * STEPS_PER_HOUR)
    return truth - forecast[f"{variable}_{n}h_mid"]


def inputs(forecast: pd.DataFrame, current: pd.DataFrame, variable: str, n: int, longitude: float) -> pd.DataFrame:
    target_time = forecast.index + pd.Timedelta(hours=n)
    bins = np.digitize(solar_hour(target_time, longitude), SOLAR_BIN_EDGES)
    frame = pd.DataFrame(
        {
            **{name: (bins == b).astype(float) for b, name in enumerate(SOLAR_INPUTS)},
            # Issued n h ago, verified at t, so known at t.
            "error_same": errors(forecast, current, variable, n).shift(n * STEPS_PER_HOUR),
            "error_1h": errors(forecast, current, variable, 1).shift(STEPS_PER_HOUR),
        },
        index=forecast.index,
    )
    return frame.fillna(0.0)


def fit(
    forecast: pd.DataFrame,
    current: pd.DataFrame,
    horizons: list[int],
    longitude: float,
    since: pd.Timestamp | None = None,
) -> dict:
    """One Correction per 'T_3h'-style target; empty while history is short. Rows before `since` are ignored."""
    learnable = forecast.index >= since if since is not None else np.full(len(forecast), True)
    if int(learnable.sum()) < MIN_HISTORY_ROWS:
        return {}
    corrections = {}
    for n in horizons:
        for variable in CORRECTED_VARIABLES:
            target = errors(forecast, current, variable, n)
            known = target.notna() & learnable
            # Sparse history: no verified pair at all, so skip the correction instead of failing.
            if not known.any():
                continue
            x = inputs(forecast, current, variable, n, longitude)[known]
            ridge = Ridge(alpha=RIDGE_ALPHA).fit(x, target[known])

            truth = current[variable].shift(-n * STEPS_PER_HOUR)[known]
            moved = ridge.predict(x)
            low = forecast.loc[known, f"{variable}_{n}h_low"] + moved
            high = forecast.loc[known, f"{variable}_{n}h_high"] + moved
            # Distance outside the range (negative: inside).
            outside = np.maximum(low - truth, truth - high)
            level = min(1.0, RANGE_COVERAGE * (1 + 1 / len(outside)))
            corrections[f"{variable}_{n}h"] = Correction(
                intercept=float(ridge.intercept_),
                coefficients={name: float(value) for name, value in zip(x.columns, ridge.coef_, strict=True)},
                widen=float(np.quantile(outside, level)),
            )
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
            moved = correction.shift(inputs(forecast, current, variable, n, longitude))
            names = [f"{variable}_{n}h_{name}" for name in ("low", "mid", "high")]
            corrected[names[1]] = forecast[names[1]] + moved
            corrected[names[0]] = forecast[names[0]] + moved - correction.widen
            corrected[names[2]] = forecast[names[2]] + moved + correction.widen
            # Narrowing must not cross the median.
            corrected[names[0]] = corrected[names[0]].clip(upper=corrected[names[1]])
            corrected[names[2]] = corrected[names[2]].clip(lower=corrected[names[1]])
    return corrected
