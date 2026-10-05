import numpy as np
import pandas as pd
from sklearn.linear_model import Ridge

from correction import MIN_HISTORY_ROWS, RANGE_COVERAGE, RIDGE_ALPHA, SOLAR_INPUTS, Correction, errors, inputs
from features import STEPS_PER_HOUR
from light_gain import LightProfile, availability, current_gain, expected_gain

EXPERIMENT_VERSION = "light-v3"
ERROR_INPUT_HOURS = 3


def light_inputs(
    forecast: pd.DataFrame, current: pd.DataFrame, n: int, longitude: float, gains: pd.Series
) -> pd.DataFrame:
    frame = inputs(forecast, current, "T", n, longitude)
    frame[list(SOLAR_INPUTS)] = frame[list(SOLAR_INPUTS)].mul(gains, axis=0)
    return frame if n <= ERROR_INPUT_HOURS else frame[list(SOLAR_INPUTS)]


def fit(
    forecast: pd.DataFrame, current: pd.DataFrame, realized: pd.Series, horizons: list[int], longitude: float,
    since: pd.Timestamp | None, cutoff: pd.Timestamp | None = None,
) -> dict[str, Correction]:
    cutoff = current.index[-1] if cutoff is None else cutoff
    available = availability(current)
    corrections = {}
    for n in horizons:
        target = errors(forecast, current, "T", n)
        gains_at_target = realized.shift(-n * STEPS_PER_HOUR)
        known = target.notna() & gains_at_target.notna() & current[["T", "H", "P"]].notna().all(axis=1)
        known &= forecast.index + pd.Timedelta(hours=n) <= cutoff
        if since is not None:
            known &= forecast.index >= since
        if available is not None:
            known &= current["received_at"].le(cutoff.timestamp()) & available.shift(-n * STEPS_PER_HOUR).le(cutoff.timestamp())
        if int(known.sum()) < MIN_HISTORY_ROWS:
            continue
        x = light_inputs(forecast, current, n, longitude, gains_at_target).loc[known]
        ridge = Ridge(alpha=RIDGE_ALPHA).fit(x, target.loc[known])
        truth = current["T"].shift(-n * STEPS_PER_HOUR).loc[known]
        moved = ridge.predict(x)
        outside = np.maximum(
            forecast.loc[known, f"T_{n}h_low"] + moved - truth,
            truth - forecast.loc[known, f"T_{n}h_high"] - moved,
        )
        level = min(1.0, RANGE_COVERAGE * (1 + 1 / len(outside)))
        corrections[f"T_{n}h"] = Correction(
            intercept=float(ridge.intercept_),
            coefficients={name: float(value) for name, value in zip(x.columns, ridge.coef_, strict=True)},
            widen=float(np.quantile(outside, level)),
        )
    return corrections


def apply(
    corrections: dict[str, Correction], forecast: pd.DataFrame, current: pd.DataFrame,
    horizons: list[int], longitude: float, reference: LightProfile,
) -> pd.DataFrame:
    corrected = forecast.copy()
    now = current_gain(current, reference)
    for n in horizons:
        fitted = corrections.get(f"T_{n}h")
        if fitted is None:
            continue
        moved = fitted.shift(light_inputs(forecast, current, n, longitude, expected_gain(now, reference, n)))
        mid = forecast[f"T_{n}h_mid"] + moved
        corrected[f"T_{n}h_mid"] = mid
        corrected[f"T_{n}h_low"] = (forecast[f"T_{n}h_low"] + moved - fitted.widen).clip(upper=mid)
        corrected[f"T_{n}h_high"] = (forecast[f"T_{n}h_high"] + moved + fitted.widen).clip(lower=mid)
    return corrected
