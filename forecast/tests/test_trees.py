import json

import numpy as np
import pytest
from sklearn.ensemble import HistGradientBoostingRegressor

import trees

INPUTS = 3


@pytest.fixture(scope="module")
def model() -> tuple[HistGradientBoostingRegressor, np.ndarray]:
    rng = np.random.default_rng(0)
    x = rng.normal(size=(600, INPUTS))
    target = 2 * x[:, 0] - x[:, 1] + rng.normal(0, 0.1, len(x))
    x[::7, 0] = np.nan
    fitted = HistGradientBoostingRegressor(loss="absolute_error", max_iter=30, max_leaf_nodes=8).fit(x, target)
    return fitted, x


def test_plain_trees_predict_what_the_fitted_model_does(model: tuple[HistGradientBoostingRegressor, np.ndarray]) -> None:
    fitted, x = model
    baseline, encoded = trees.encode(fitted)

    received = trees.decode(json.loads(json.dumps(encoded)), INPUTS)

    assert trees.predict(baseline, received, x) == pytest.approx(fitted.predict(x))


def test_a_split_without_threshold_sends_every_number_left_and_missing_by_its_flag() -> None:
    tree = [[0, None, 0, 1, 2], [1.0], [2.0]]

    assert trees.leaf(tree, np.array([1e9])) == 1.0
    assert trees.leaf(tree, np.array([np.nan])) == 2.0


@pytest.mark.parametrize(("value", "reason"), [
    (None, "1 to"), ([], "1 to"), ([[]], "1 to"), ([[[0.5]] * 256], "1 to"), ([[[0, 1.0, 0]]], "leaf or a split"),
    ([[[0, 1.0, 0, 0, 2], [0.5], [1.5]]], "outside"), ([[[0, 1.0, 0, 1, 3], [0.5], [1.5]]], "outside"),
    ([[[INPUTS, 1.0, 0, 1, 2], [0.5], [1.5]]], "outside"), ([[[0, 1.0, 2, 1, 2], [0.5], [1.5]]], "outside"),
    ([[[0, 1.0, 0, True, 2], [0.5], [1.5]]], "integer"), ([[[0, "1", 0, 1, 2], [0.5], [1.5]]], "threshold"),
    ([[[0, 1.0, 0, 1, 2], [None], [1.5]]], "leaf"), ([[[0, 1.0, 0, 1, 2], [10**400], [1.5]]], "leaf"),
])
def test_decode_refuses_what_encode_cannot_make(value: object, reason: str) -> None:
    with pytest.raises(ValueError, match=reason):
        trees.decode(value, INPUTS)
