"""Hole grid, parts and runs of the base board, shared by the generators (plain Python 3.9).

Perfboard 22 x 27 holes, plated. Printed labels, component side upright: columns 22..01 left to right,
rows A-Z then A bottom-up (A22 bottom left). Holes here are (column, row) from 1, top left, as KiCad draws them.
Rows 1 and 27 are both printed A, so the layout keeps them empty.
Runs: 0 ohm links over straight free stretches (component side), solder bridges elsewhere (solder side).
"""

PITCH = 2.54
COLS, ROWS = 22, 27
BOARD_W, BOARD_H = 60.0, 80.0
HOLE_X = (BOARD_W - (COLS - 1) * PITCH) / 2
HOLE_Y = (BOARD_H - (ROWS - 1) * PITCH) / 2


def row_letter(row):
    return chr(ord("A") + (ROWS - row) % 26)


def col_number(col):
    return f"{COLS + 1 - col:02d}"


def hole_name(col, row):
    return f"{row_letter(row)}{col_number(col)}"


def strip(count):
    return [(str(n + 1), 0.0, n * PITCH) for n in range(count)]


# Footprint pads (number, x, y) in mm, Y down. DevKit, antenna up: left row EN (15) to VIN (1), right row D23 (30) to 3V3 (16), 25.4 mm apart; pin 15 is the origin.
FOOTPRINT_PADS = {
    "DOIT_ESP32_DevKit_V1_Socketed": ([(str(n), 0.0, (15 - n) * PITCH) for n in range(1, 16)]
                                      + [(str(n), 25.4, (30 - n) * PITCH) for n in range(16, 31)]),
    "BMP280_Module_Socketed": strip(6),
    "R_Socketed_1x02": strip(2),
    "R_Axial_P10.16": [("1", 0.0, 0.0), ("2", 10.16, 0.0)],
    "C_Disc_P2.54": [("1", 0.0, 0.0), ("2", PITCH, 0.0)],
    "CP_Radial_D5.0mm_P2.54": [("1", 0.0, 0.0), ("2", PITCH, 0.0)],
    "PinHeader_1x02": strip(2),
    "PinHeader_1x09": strip(9),
}

NETS = {
    "5V": "+5V", "3V3": "+3V3", "GND": "GND",
    "SDA": "/SDA", "SCL": "/SCL", "SD": "/SD",
    "SCK": "/SCK", "WS": "/WS", "SCK_CABLE": "/SCK_CABLE", "WS_CABLE": "/WS_CABLE",
}

# Parts: reference, footprint, value, origin hole, orientation, pad nets.
# Orientation turns the footprint's down direction: 0 down, 90 right, -90 left, 180 up.
PARTS = [
    # USB port to the left edge, antenna to the right
    ("U1", "DOIT_ESP32_DevKit_V1_Socketed", "ESP32 DevKit V1", (20, 3), -90,
     {"1": "5V", "2": "GND", "7": "SCK", "8": "WS", "9": "SD",
      "16": "3V3", "17": "GND", "26": "SDA", "29": "SCL"}),
    # Pins read SDO..VCC from the left, chip side up
    ("U2", "BMP280_Module_Socketed", "BMP280 0x76", (12, 23), -90,
     {"1": "3V3", "2": "GND", "3": "SCL", "4": "SDA", "5": "3V3", "6": "GND"}),
    ("R2", "R_Socketed_1x02", "4k7", (8, 21), 90, {"1": "3V3", "2": "SDA"}),
    ("R1", "R_Socketed_1x02", "4k7", (11, 21), -90, {"1": "3V3", "2": "SCL"}),
    ("R3", "R_Axial_P10.16", "47R", (12, 5), 180, {"1": "SCK", "2": "SCK_CABLE"}),
    ("R4", "R_Axial_P10.16", "47R", (13, 7), 180, {"1": "WS", "2": "WS_CABLE"}),
    ("C1", "C_Disc_P2.54", "100n", (6, 14), 0, {"1": "3V3", "2": "GND"}),
    ("C2", "CP_Radial_D5.0mm_P2.54", "10u", (6, 19), 0, {"1": "3V3", "2": "GND"}),
    ("J1", "PinHeader_1x09", "FTP cable", (2, 17), 90,
     {"1": "GND", "2": "SCK_CABLE", "3": "WS_CABLE", "4": "SD", "5": "3V3",
      "6": "GND", "7": "GND", "8": "SDA", "9": "SCL"}),
    ("J2", "PinHeader_1x02", "5V in", (1, 2), 0, {"1": "5V", "2": "GND"}),
]
# Expected pad holes, checked by the board script
EXPECTED = {("U1", "1"): (6, 3), ("U1", "15"): (20, 3), ("U1", "16"): (6, 13), ("U1", "30"): (20, 13),
            ("U2", "6"): (7, 23), ("R2", "2"): (9, 21), ("R1", "2"): (10, 21),
            ("R3", "2"): (8, 5), ("C2", "2"): (7, 19),
            ("J1", "9"): (10, 17), ("J2", "2"): (1, 3)}
# DevKit joins its two GND pins internally
INTERNAL_LINKS = [(("U1", "2"), ("U1", "17"))]

ROUTES = [
    ("5V", [(6, 3), (6, 2), (1, 2)]),
    ("GND", [(7, 3), (7, 4), (1, 4)]),
    ("GND", [(1, 3), (1, 25), (11, 25)]),
    ("GND", [(1, 17), (2, 17)]),
    ("GND", [(7, 25), (7, 23)]),
    ("GND", [(11, 25), (11, 23)]),
    ("SCK", [(12, 3), (12, 5)]),
    ("SCK_CABLE", [(8, 5), (3, 5), (3, 17)]),
    ("WS", [(13, 3), (13, 7)]),
    ("WS_CABLE", [(9, 7), (4, 7), (4, 17)]),
    ("SD", [(14, 3), (14, 9), (5, 9), (5, 17)]),
    ("3V3", [(6, 13), (6, 20), (8, 20), (8, 24)]),
    ("GND", [(7, 13), (7, 19)]),
    ("GND", [(7, 17), (8, 17)]),
    ("SDA", [(16, 13), (16, 15), (9, 15), (9, 23)]),
    ("SCL", [(19, 13), (19, 16), (10, 16), (10, 23)]),
    ("3V3", [(11, 21), (12, 21), (12, 24)]),
]
# Hand-placed 0 ohm links (crossings): holes under the body stay free for other nets' bridges.
CROSSINGS = [("3V3", (8, 24), (12, 24))]
# Link lengths in holes: 3 fits the 6.3 mm body, 8 is the comfortable lead reach
LINK_SPANS = {span: f"R_Jumper_P{span * PITCH:.2f}" for span in range(3, 9)}
FOOTPRINT_PADS.update({name: [("1", 0.0, 0.0), ("2", span * PITCH, 0.0)] for span, name in LINK_SPANS.items()})


def rotate(x, y, angle):
    return {0: (x, y), 90: (y, -x), -90: (-y, x), 180: (-x, -y)}[angle]


def part_pads(part):
    ref, name, _, (col, row), angle, _ = part
    result = []
    for number, x, y in FOOTPRINT_PADS[name]:
        dx, dy = rotate(x, y, angle)
        result.append((number, (col + round(dx / PITCH), row + round(dy / PITCH))))
    return result


def expand(nodes):
    holes = [nodes[0]]
    for (c1, r1), (c2, r2) in zip(nodes, nodes[1:]):
        if c1 != c2 and r1 != r2:
            raise ValueError(f"diagonal run {hole_name(c1, r1)}-{hole_name(c2, r2)}")
        steps = max(abs(c2 - c1), abs(r2 - r1))
        dc, dr = (c2 > c1) - (c2 < c1), (r2 > r1) - (r2 < r1)
        holes += [(c1 + i * dc, r1 + i * dr) for i in range(1, steps + 1)]
    return holes


def occupied():
    result = {}
    for part in PARTS:
        ref, pad_nets = part[0], part[5]
        for number, spot in part_pads(part):
            col, row = spot
            if not (1 <= col <= COLS and 1 <= row <= ROWS):
                raise ValueError(f"{ref} pad {number} is off the board")
            if spot in result:
                raise ValueError(f"{ref} and {result[spot][0]} share {hole_name(*spot)}")
            result[spot] = (ref, number, pad_nets.get(number))
    return result


def between(a, b):
    return expand([a, b])[1:-1]


def check():
    """Reject shorts and split nets; return hole -> net key."""
    parts = occupied()
    crossed = {spot for _, a, b in CROSSINGS for spot in between(a, b)}
    for (ref, number), spot in EXPECTED.items():
        actual = next(key for key, value in parts.items() if value[:2] == (ref, number))
        if actual != spot:
            raise ValueError(f"{ref} pad {number} at {hole_name(*actual)}, expected {hole_name(*spot)}")
    hole_net = {spot: signal for spot, (_, _, signal) in parts.items()}
    for signal, nodes in ROUTES:
        for spot in expand(nodes):
            if spot in parts and parts[spot][2] != signal:
                ref, number, other = parts[spot]
                raise ValueError(f"{signal} run crosses {ref} pad {number} ({other or 'unused'}) at {hole_name(*spot)}")
            if hole_net.get(spot, signal) != signal:
                raise ValueError(f"{signal} run meets {hole_net[spot]} at {hole_name(*spot)}")
            hole_net[spot] = signal

    parent = {}

    def find(spot):
        parent.setdefault(spot, spot)
        while parent[spot] != spot:
            parent[spot] = parent[parent[spot]]
            spot = parent[spot]
        return spot

    for _, nodes in ROUTES:
        run = expand(nodes)
        for a, b in zip(run, run[1:]):
            parent[find(a)] = find(b)
    for signal, a, b in CROSSINGS:
        if hole_net.get(a) != signal or hole_net.get(b) != signal:
            raise ValueError(f"crossing {hole_name(*a)}-{hole_name(*b)} does not end on {signal}")
        for spot in between(a, b):
            if spot in parts:
                raise ValueError(f"crossing {hole_name(*a)}-{hole_name(*b)} lies over a pad at {hole_name(*spot)}")
        parent[find(a)] = find(b)
    where = {value[:2]: spot for spot, value in parts.items()}
    for a, b in INTERNAL_LINKS:
        parent[find(where[a])] = find(where[b])
    for key in NETS:
        pieces = {find(spot) for spot, (_, _, signal) in parts.items() if signal == key}
        if len(pieces) > 1:
            raise ValueError(f"net {key} is split into {len(pieces)} pieces")
    return hole_net


def wiring():
    """Split runs into (links, bridges): links (net, first, last), bridges (net, hole, neighbour). Fewest bridges, then fewest links."""
    parts = occupied()
    crossed = {spot for _, a, b in CROSSINGS for spot in between(a, b)}
    uses = {}
    for _, nodes in ROUTES:
        for spot in set(expand(nodes)):
            uses[spot] = uses.get(spot, 0) + 1
    legs = {spot for _, a, b in CROSSINGS for spot in (a, b)}
    branches = {spot for spot, count in uses.items() if count > 1}
    no_leg = set(parts) | crossed | legs
    no_body = no_leg | branches
    links = [(signal, a, b) for signal, a, b in CROSSINGS]
    bridges = []
    for signal, nodes in ROUTES:
        run = expand(nodes)
        steps = len(run) - 1

        def straight(i, j):
            dc, dr = run[i + 1][0] - run[i][0], run[i + 1][1] - run[i][1]
            return all((run[k + 1][0] - run[k][0], run[k + 1][1] - run[k][1]) == (dc, dr) for k in range(i, j))

        def usable(i, j):
            return (straight(i, j) and run[i] not in no_leg and run[j] not in no_leg
                    and not any(run[k] in no_body for k in range(i + 1, j)))

        # cost[i] = (bridges, links) to wire run[0..i]; a link may not start where the last one ended
        best = {(0, False): ((0, 0), [])}
        for i in range(steps + 1):
            for ended in (False, True):
                if (i, ended) not in best:
                    continue
                (bridge_count, link_count), plan = best[(i, ended)]
                options = []
                if i < steps:
                    options.append(((i + 1, False), (bridge_count + 1, link_count), plan + [("bridge", i, i + 1)]))
                if not ended:
                    for span in LINK_SPANS:
                        j = i + span
                        if j <= steps and usable(i, j):
                            options.append(((j, True), (bridge_count, link_count + 1), plan + [("link", i, j)]))
                for state, cost, new_plan in options:
                    if state not in best or cost < best[state][0]:
                        best[state] = (cost, new_plan)
        final = min((best[state] for state in ((steps, False), (steps, True)) if state in best), key=lambda entry: entry[0])
        for kind, i, j in final[1]:
            (links if kind == "link" else bridges).append((signal, run[i], run[j]))
            if kind == "link":
                # A branch hole now carries a leg: later runs must bridge to it
                no_leg.update((run[i], run[j]))
    return links, bridges


def free_holes():
    taken = set(occupied()) | {spot for _, a, b in wiring()[0] for spot in (a, b)}
    return [(col, row) for row in range(1, ROWS + 1) for col in range(1, COLS + 1) if (col, row) not in taken]
