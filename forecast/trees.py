"""A fitted HistGradientBoostingRegressor as plain numbers.

Per tree its nodes, children after their parent: a split [input, threshold (null: +inf), missing goes left 0/1, left,
right], a leaf [value]. The prediction is the baseline plus one leaf per tree.
"""

import math

import numpy as np
from sklearn.ensemble import HistGradientBoostingRegressor

Tree = list[list[float | None]]

SPLIT_SIZE = 5
MAX_TREES = 1000
MAX_NODES = 255


def encode(model: HistGradientBoostingRegressor) -> tuple[float, list[Tree]]:
    trees = []
    for (predictor,) in model._predictors:
        trees.append([
            [float(node["value"])] if node["is_leaf"] else [
                int(node["feature_idx"]),
                float(node["num_threshold"]) if math.isfinite(node["num_threshold"]) else None,
                int(node["missing_go_to_left"]), int(node["left"]), int(node["right"]),
            ]
            for node in predictor.nodes
        ])
    return float(model._baseline_prediction.ravel()[0]), trees


def predict(baseline: float, trees: list[Tree], rows: np.ndarray) -> np.ndarray:
    return np.array([baseline + sum(leaf(tree, row) for tree in trees) for row in rows])


def leaf(tree: Tree, row: np.ndarray) -> float:
    node = tree[0]
    while len(node) == SPLIT_SIZE:
        feature, threshold, missing_left, left, right = node
        value = row[int(feature)]
        goes_left = bool(missing_left) if math.isnan(value) else threshold is None or value <= threshold
        node = tree[int(left if goes_left else right)]
    return float(node[0])


def decode(value: object, inputs: int) -> list[Tree]:
    """Trees as received; ValueError with the reason for anything `encode` cannot have made for `inputs` inputs."""
    if not isinstance(value, list) or not 0 < len(value) <= MAX_TREES:
        raise ValueError(f"1 to {MAX_TREES} trees")
    return [decode_tree(tree, inputs) for tree in value]


def decode_tree(value: object, inputs: int) -> Tree:
    if not isinstance(value, list) or not 0 < len(value) <= MAX_NODES:
        raise ValueError(f"a tree has 1 to {MAX_NODES} nodes")
    nodes = []
    for position, node in enumerate(value):
        if isinstance(node, list) and len(node) == 1:
            nodes.append([number(node[0], "a leaf")])
            continue
        if not isinstance(node, list) or len(node) != SPLIT_SIZE:
            raise ValueError("a node is a leaf or a split")
        feature, threshold, missing_left, left, right = node
        if any(isinstance(index, bool) or not isinstance(index, int) for index in (feature, missing_left, left, right)):
            raise ValueError("a split has integer input, missing flag and children")
        if not (0 <= feature < inputs and missing_left in (0, 1) and position < left < len(value) and position < right < len(value)):
            raise ValueError("a split points outside its tree")
        nodes.append([feature, None if threshold is None else number(threshold, "a threshold"), missing_left, left, right])
    return nodes


def number(value: object, name: str) -> float:
    if not isinstance(value, bool) and isinstance(value, (int, float)):
        try:
            if math.isfinite(result := float(value)):
                return result
        except OverflowError:
            pass
    raise ValueError(f"{name} must be a finite number")
