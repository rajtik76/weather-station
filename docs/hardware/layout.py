"""Perfboard layouts as plain data: connectivity check, ASCII map and SVG render.

A board file builds a Board, adds parts and bottom runs, then calls board.main().
Holes are (col, row) counted from 1, row 1 along the top edge; rows are lettered.
Every hole a bottom run passes over is soldered to it, so a run crossing a pin of
another net is a short. Jumpers (wire links, 0 Ω) join two holes on the top side
and may cross runs freely. The solder side is drawn as the board flipped over its
long edge, the way it is turned while soldering: columns stay put, rows run upside
down, a cable keeps its side.

    .venv/bin/python docs/hardware/<board>.py         check + ASCII map
    .venv/bin/python docs/hardware/<board>.py --svg   also render the SVG
"""

from __future__ import annotations

import sys
from pathlib import Path

import matplotlib

matplotlib.use('svg')
# Text as text and fixed ids keep the SVG small and its diffs clean
matplotlib.rcParams['svg.fonttype'] = 'none'
matplotlib.rcParams['svg.hashsalt'] = 'weather-station'

import matplotlib.pyplot as plt
from matplotlib.patches import Circle, FancyBboxPatch, Polygon, Rectangle

P = 2.54

Hole = tuple[int, int]


def row_name(row: int) -> str:
    name = ''
    while row:
        row, rest = divmod(row - 1, 26)
        name = chr(65 + rest) + name
    return name


def hole_name(hole: Hole) -> str:
    return f'{row_name(hole[1])}{hole[0]}'


def line(a: Hole, b: Hole) -> list[Hole]:
    """Holes from a to b inclusive; a and b share a column or a row."""
    (c1, r1), (c2, r2) = a, b
    steps = max(abs(c2 - c1), abs(r2 - r1))
    dc = (c2 > c1) - (c2 < c1)
    dr = (r2 > r1) - (r2 < r1)
    return [(c1 + i * dc, r1 + i * dr) for i in range(steps + 1)]


def inside_polygon(x: float, y: float, pts: list[tuple[float, float]]) -> bool:
    hit = False
    for (x1, y1), (x2, y2) in zip(pts, pts[1:] + pts[:1]):
        if (y1 > y) != (y2 > y) and x < x1 + (y - y1) * (x2 - x1) / (y2 - y1):
            hit = not hit
    return hit


def box(ax, corner_a, corner_b, **kw) -> None:
    (x1, y1), (x2, y2) = corner_a, corner_b
    ax.add_patch(Rectangle((min(x1, x2), min(y1, y2)), abs(x2 - x1), abs(y2 - y1), **kw))


def striped(ax, pts, base: str, stripe: str | None, lw: float, z: int, alpha: float = 1.0) -> None:
    xs, ys = zip(*pts)
    ax.plot(xs, ys, color='#555555', lw=lw + 1.2, solid_capstyle='round', zorder=z, alpha=alpha)
    ax.plot(xs, ys, color=base, lw=lw, solid_capstyle='round', zorder=z, alpha=alpha)
    if stripe:
        ax.plot(xs, ys, color=stripe, lw=lw, dashes=(2, 2), zorder=z, alpha=alpha)


class Pin:
    def __init__(self, name: str, hole: Hole, net: str | None) -> None:
        self.name = name
        self.hole = hole
        self.net = net


class Part:
    """Base part: pins sit in holes, mechanical holes must stay free, links join holes on top.

    height is how far the part stands above the board, in mm; it decides what fits under a module.
    """

    ref: str
    pins: list[Pin]
    height: float = 0.0
    mechanical: list[Hole] = []
    links: list[tuple[Hole, Hole]] = []

    def describe(self) -> str:
        return ', '.join(f'{p.name} {hole_name(p.hole)} {p.net or "nc"}' for p in self.pins)

    def body(self, board: Board) -> set[Hole]:
        """Holes the part covers on the top side, its own pins excluded."""
        return set()

    def draw(self, ax, board: Board, mirror: bool) -> None:
        raise NotImplementedError


class Module(Part):
    """Breakout module soldered by its header pins; shapes are in mm from the pin centre.

    pins: {hole: (pin name, net or None)}. shapes: dicts with one geometry key
    (rect=(x0, x1, y0, y1), poly=[(dx, dy), ...], circle=(dx, dy, r)) plus patch kwargs;
    dx runs along the columns, dy down the rows. The first shape is the outline and is
    also shown dashed from below. Shapes and the note sit relative to origin (a hole),
    or to the pin centre when origin is None. label_dirs: {pin name: (dx, dy)} in mm.
    standoff is the clear height under the module (header, sockets); lower parts may sit there.
    """

    def __init__(self, ref: str, pins: dict[Hole, tuple[str, str | None]], shapes: list[dict],
                 note: str = '', note_at: tuple[float, float] = (0, 0), note_va: str = 'top',
                 label_side: int = 1, label_colour: str = 'white', keep_free: list[Hole] | None = None,
                 origin: Hole | None = None, label_dirs: dict[str, tuple[float, float]] | None = None,
                 standoff: float = 0.0, note_ha: str = 'center') -> None:
        self.ref = ref
        self.standoff = standoff
        self.note_ha = note_ha
        self.origin = origin
        self.label_dirs = label_dirs or {}
        self.pins = [Pin(name, hole, net) for hole, (name, net) in pins.items()]
        self.mechanical = keep_free or []
        self.shapes = shapes
        self.note = note
        self.note_at = note_at
        self.note_va = note_va
        self.label_side = label_side
        self.label_colour = label_colour

    def anchor(self) -> tuple[float, float]:
        if self.origin:
            return self.origin
        cols = [p.hole[0] for p in self.pins]
        rows = [p.hole[1] for p in self.pins]
        return sum(cols) / len(cols), sum(rows) / len(rows)

    def body(self, board: Board) -> set[Hole]:
        col, row = self.anchor()
        outline = dict(self.shapes[0]) if self.shapes else {}
        covered = set()
        for c in range(1, board.cols + 1):
            for r in range(1, board.rows + 1):
                dx, dy = (c - col) * P, (r - row) * P
                if 'rect' in outline:
                    x0, x1, y0, y1 = outline['rect']
                    hit = min(x0, x1) <= dx <= max(x0, x1) and min(y0, y1) <= dy <= max(y0, y1)
                elif 'poly' in outline:
                    hit = inside_polygon(dx, dy, outline['poly'])
                elif 'circle' in outline:
                    cx, cy, radius = outline['circle']
                    hit = (dx - cx) ** 2 + (dy - cy) ** 2 <= radius ** 2
                else:
                    hit = False
                if hit:
                    covered.add((c, r))
        return covered - {p.hole for p in self.pins} - set(self.mechanical)

    def draw(self, ax, board: Board, mirror: bool) -> None:
        col, row = self.anchor()

        def at(dx: float, dy: float) -> tuple[float, float]:
            return board.mm(col, row, dx, dy, mirror)

        for i, shape in enumerate(self.shapes):
            if mirror and i:
                break
            kw = dict(shape)
            kw.setdefault('zorder', 11 if i == 0 else 12)
            if mirror:
                kw.update(fc='none', ls='--', alpha=0.6)
            if 'rect' in kw:
                x0, x1, y0, y1 = kw.pop('rect')
                box(ax, at(x0, y0), at(x1, y1), **kw)
            elif 'poly' in kw:
                ax.add_patch(Polygon([at(dx, dy) for dx, dy in kw.pop('poly')], closed=True, **kw))
            else:
                dx, dy, r = kw.pop('circle')
                ax.add_patch(Circle(at(dx, dy), r, **kw))

        for pin in self.pins:
            x, y = board.xy(*pin.hole, mirror)
            if not mirror:
                ax.add_patch(Circle((x, y), 0.8, fc='#d4af37', ec='none', zorder=12))
            dx, dy = self.label_dirs.get(pin.name, (1.3 * self.label_side, 0))
            dy = dy if mirror else -dy
            if abs(dx) >= abs(dy):
                align = {'ha': 'left' if dx > 0 else 'right', 'va': 'center', 'rotation': 0}
            else:
                align = {'ha': 'center', 'va': 'bottom' if dy > 0 else 'top', 'rotation': 90}
            ax.text(x + dx, y + dy, pin.name, fontsize=5.5, **align,
                    color=self.label_colour if not mirror else 'black', zorder=13,
                    bbox=None if not mirror else {'fc': 'white', 'ec': 'none', 'pad': 0.3, 'alpha': 0.85})

        if self.note and not mirror:
            ax.text(*at(*self.note_at), self.note, ha=self.note_ha, va=self.note_va, fontsize=6.5, zorder=13)


class TwoLead(Part):
    """Axial or radial part with leads in holes a and b."""

    pin_names = ('1', '2')
    default_height = 2.5

    def __init__(self, ref: str, value: str, a: Hole, b: Hole, net_a: str | None, net_b: str | None,
                 height: float | None = None) -> None:
        self.ref = ref
        self.height = self.default_height if height is None else height
        self.value = value
        self.a = a
        self.b = b
        self.pins = [Pin(self.pin_names[0], a, net_a), Pin(self.pin_names[1], b, net_b)]

    def describe(self) -> str:
        return f'{self.value}  ' + super().describe()

    def body(self, board: Board) -> set[Hole]:
        if self.a[0] == self.b[0] or self.a[1] == self.b[1]:
            return set(line(self.a, self.b)[1:-1])
        return set()

    def label(self, ax, x: float, y: float, **kw) -> None:
        ax.text(x, y, f'{self.ref} {self.value}', fontsize=6.5, zorder=13,
                bbox={'fc': 'white', 'ec': 'none', 'pad': 0.5, 'alpha': 0.8}, **kw)


class Resistor(TwoLead):
    def draw(self, ax, board: Board, mirror: bool) -> None:
        fade = 0.25 if mirror else 1.0
        (x1, y1), (x2, y2) = board.xy(*self.a, mirror), board.xy(*self.b, mirror)
        ax.plot([x1, x2], [y1, y2], color='#888888', lw=1, zorder=9, alpha=fade)
        style = {'fc': '#d9b98c', 'ec': '#7a5a30', 'lw': 0.8, 'zorder': 10, 'alpha': fade}
        if y1 == y2:
            box(ax, (min(x1, x2) + 2.2, y1 - 1), (max(x1, x2) - 2.2, y1 + 1), **style)
            self.label(ax, (x1 + x2) / 2, y1 - 2.6, ha='center')
        else:
            box(ax, (x1 - 1, min(y1, y2) + 2.2), (x1 + 1, max(y1, y2) - 2.2), **style)
            self.label(ax, x1 + 1.6, (y1 + y2) / 2, ha='left', va='center')


class Capacitor(TwoLead):
    """Ceramic or electrolytic; for an electrolytic, a is the + lead. Heights assume radial parts standing."""

    def __init__(self, ref: str, value: str, a: Hole, b: Hole, net_a: str | None, net_b: str | None,
                 electrolytic: bool = False, height: float | None = None) -> None:
        if electrolytic:
            self.pin_names = ('+', '-')
        super().__init__(ref, value, a, b, net_a, net_b, height if height is not None else 12.0 if electrolytic else 5.0)
        self.electrolytic = electrolytic

    def draw(self, ax, board: Board, mirror: bool) -> None:
        fade = 0.25 if mirror else 1.0
        (x1, y1), (x2, y2) = board.xy(*self.a, mirror), board.xy(*self.b, mirror)
        fc, ec = ('#2b4f8a', '#1a2f55') if self.electrolytic else ('#e6a23c', '#8a5a10')
        style = {'boxstyle': 'round,pad=0.2', 'fc': fc, 'ec': ec, 'lw': 0.8, 'zorder': 10, 'alpha': fade}
        vertical = x1 == x2
        if vertical:
            ax.add_patch(FancyBboxPatch((x1 - 1.1, min(y1, y2) + 0.8), 2.2, abs(y1 - y2) - 1.6, **style))
            self.label(ax, x1, max(y1, y2) + 1.3, ha='center')
        else:
            ax.add_patch(FancyBboxPatch((min(x1, x2) + 0.8, y1 - 1.1), abs(x1 - x2) - 1.6, 2.2, **style))
            self.label(ax, (x1 + x2) / 2, y1 + 1.6, ha='center')
        if self.electrolytic and not mirror:
            px, py = (x1 - 1.9, y1) if vertical else (x1, y1 + 1.4)
            ax.text(px, py, '+', ha='center', va='center', fontsize=7, fontweight='bold', zorder=13)


class Jumper(TwoLead):
    """Top-side link between two holes of one net: bare wire or a 0 Ω resistor. Crosses runs freely."""

    def __init__(self, ref: str, a: Hole, b: Hole, net: str, zero_ohm: bool = False) -> None:
        super().__init__(ref, '0 Ω' if zero_ohm else 'wire', a, b, net, net, 2.5 if zero_ohm else 1.0)
        self.zero_ohm = zero_ohm
        self.links = [(a, b)]

    def draw(self, ax, board: Board, mirror: bool) -> None:
        if self.zero_ohm:
            Resistor.draw(self, ax, board, mirror)
            return
        fade = 0.25 if mirror else 1.0
        (x1, y1), (x2, y2) = board.xy(*self.a, mirror), board.xy(*self.b, mirror)
        colour = board.colour(self.pins[0].net)
        ax.plot([x1, x2], [y1, y2], color='#555555', lw=2.6, solid_capstyle='round', zorder=9, alpha=fade)
        ax.plot([x1, x2], [y1, y2], color=colour, lw=1.4, solid_capstyle='round', zorder=9, alpha=fade)


class Cable(Part):
    """Jacketed cable entering over the left edge.

    wires: [(hole, net, colour name, base colour, stripe colour or None)].
    """

    def __init__(self, ref: str, wires: list[tuple[Hole, str | None, str, str, str | None]], jacket_row: float,
                 note: str = '', jacket_end_col: float = 3.2, note_at: tuple[float, float] = (-9, 4.2),
                 legend_at: tuple[float, float] = (-20, -12)) -> None:
        self.ref = ref
        self.wires = wires
        self.height = 2.0
        self.pins = [Pin(name, hole, net) for hole, net, name, _, _ in wires]
        self.jacket_row = jacket_row
        self.jacket_end_col = jacket_end_col
        self.note = note
        self.note_at = note_at
        self.legend_at = legend_at

    def draw(self, ax, board: Board, mirror: bool) -> None:
        top = not mirror
        fade = 1.0 if top else 0.25
        jy = board.xy(1, self.jacket_row, mirror)[1]
        jend, _ = board.xy(self.jacket_end_col, self.jacket_row, mirror)
        box(ax, (-board.margin[0] + 4, jy - 3), (jend, jy + 3), fc='#6c8fb8', ec='#3d5a80', lw=1, zorder=7,
            alpha=fade)
        if top:
            if self.note:
                ax.text(self.note_at[0], jy + self.note_at[1], self.note, ha='center', va='bottom', fontsize=6.5)
        for i, (hole, net, name, base, stripe) in enumerate(self.wires):
            px, py = board.xy(*hole, mirror)
            sy = jy - 2.4 + i * 0.68 if top else jy + 2.4 - i * 0.68
            bend = board.xy(hole[0] - 1, hole[1], mirror)[0]
            striped(ax, [(jend, sy), (bend, py), (px, py)], base, stripe, 1.6, 9, fade)
        if top:
            lx, ly = self.legend_at
            for i, (hole, net, name, base, stripe) in enumerate(self.wires):
                x = lx + (i % 4) * 27
                y = ly - (i // 4) * 3.2
                striped(ax, [(x, y), (x + 3, y)], base, stripe, 1.6, 9)
                ax.text(x + 3.8, y, f'{name}  {hole_name(hole)}  {net or "nc"}', va='center', fontsize=7)


class Leads(Part):
    """Loose wires leaving over the bottom edge to an off-board part; leads: [(hole, name, net)]."""

    def __init__(self, ref: str, leads: list[tuple[Hole, str, str | None]], end_y: float = -7,
                 note: str = '', note_xy: tuple[float, float] = (0, 0)) -> None:
        self.ref = ref
        self.pins = [Pin(name, hole, net) for hole, name, net in leads]
        self.height = 2.0
        self.end_y = end_y
        self.note = note
        self.note_xy = note_xy

    def draw(self, ax, board: Board, mirror: bool) -> None:
        fade = 0.25 if mirror else 1.0
        for pin in self.pins:
            x, y = board.xy(*pin.hole, mirror)
            end = board.height - self.end_y if mirror else self.end_y
            ax.plot([x, x], [y, end], color=board.colour(pin.net), lw=1.6, solid_capstyle='round',
                    zorder=9, alpha=fade)
            ax.text(x, end + (0.6 if mirror else -0.6), pin.name, ha='center', va='bottom' if mirror else 'top',
                    fontsize=5.5, rotation=90)
        if self.note and not mirror:
            ax.text(*self.note_xy, self.note, ha='left', va='center', fontsize=6.5)


class Tie(Part):
    """Zip tie through holes drilled out to `drill` mm; every grid hole the drill or its pad clearance
    reaches is lost (a 3.5 mm drill takes the four neighbours)."""

    PAD = 0.85

    def __init__(self, ref: str, holes: list[Hole], label: str = 'zip tie', drill: float = 3.5) -> None:
        self.ref = ref
        self.pins = []
        self.height = 99.0
        self.holes = holes
        self.drill = drill
        self.label = label
        reach = (drill / 2 + self.PAD + 0.2) / P
        span = int(reach) + 1
        self.mechanical = sorted({(c + dc, r + dr) for c, r in holes
                                  for dc in range(-span, span + 1) for dr in range(-span, span + 1)
                                  if dc * dc + dr * dr < reach * reach})

    def describe(self) -> str:
        lost = [h for h in self.mechanical if h not in self.holes]
        return (f'{self.label}, drill {self.drill:g} mm at ' + ', '.join(hole_name(h) for h in self.holes)
                + '; lost: ' + ', '.join(hole_name(h) for h in lost))

    def draw(self, ax, board: Board, mirror: bool) -> None:
        pts = [board.xy(*h, mirror) for h in self.holes]
        for x, y in pts:
            ax.add_patch(Circle((x, y), self.drill / 2, fc='white', ec='#555555', lw=0.8, zorder=7))
        if mirror:
            return
        xs, ys = zip(*pts)
        ax.plot(xs, ys, color='#222222', lw=3, zorder=8)
        ax.text(pts[0][0], pts[0][1] + self.drill / 2 + 0.6, f'{self.label}\n{self.drill:g} mm holes',
                ha='center', va='bottom', fontsize=6, zorder=13)


class Board:
    def __init__(self, title: str, out: Path, cols: int, rows: int, width: float, height: float,
                 nets: dict[str, str], footer: str = '', figsize: tuple[float, float] = (11, 12),
                 margin: tuple[float, float, float, float] = (22, 22, 20, 18)) -> None:
        self.title = title
        self.out = out
        self.cols = cols
        self.rows = rows
        self.width = width
        self.height = height
        self.nets = nets
        self.footer = footer
        self.figsize = figsize
        self.margin = margin
        self.mx = (width - (cols - 1) * P) / 2
        self.my = (height - (rows - 1) * P) / 2
        self.parts: list[Part] = []
        self.runs: list[tuple[str, list[Hole]]] = []

    def add(self, *parts: Part) -> None:
        self.parts.extend(parts)

    def run(self, net: str, *holes: Hole) -> None:
        """Bottom-side run (tinned wire or solder bridge) through the given corner holes."""
        self.runs.append((net, list(holes)))

    def colour(self, net: str | None) -> str:
        return self.nets.get(net, '#888888') if net else '#888888'

    def xy(self, col: float, row: float, mirror: bool) -> tuple[float, float]:
        x = self.mx + (col - 1) * P
        y = self.height - self.my - (row - 1) * P
        return x, (self.height - y if mirror else y)

    def mm(self, col: float, row: float, dx: float, dy: float, mirror: bool) -> tuple[float, float]:
        """Point dx/dy mm away from a hole, dx along the columns, dy down the rows."""
        return self.xy(col + dx / P, row + dy / P, mirror)

    def inside(self, hole: Hole) -> bool:
        return 1 <= hole[0] <= self.cols and 1 <= hole[1] <= self.rows

    def run_holes(self) -> dict[Hole, set[str]]:
        holes: dict[Hole, set[str]] = {}
        for net, pts in self.runs:
            for a, b in zip(pts, pts[1:]):
                if a[0] == b[0] or a[1] == b[1]:
                    for hole in line(a, b):
                        holes.setdefault(hole, set()).add(net)
        return holes

    def check(self) -> tuple[list[str], list[str]]:
        """Compare the soldered connectivity against the nets the pins claim."""
        errors: list[str] = []
        warnings: list[str] = []
        owner: dict[Hole, str] = {}
        mechanical: set[Hole] = set()
        pins: list[tuple[str, Pin]] = []

        def claim(hole: Hole, label: str) -> None:
            if not self.inside(hole):
                errors.append(f'OFF BOARD {label} at {hole}')
            elif hole in owner:
                errors.append(f'COLLISION {owner[hole]} and {label} in {hole_name(hole)}')
            else:
                owner[hole] = label

        for part in self.parts:
            for pin in part.pins:
                label = f'{part.ref}.{pin.name}'
                pins.append((label, pin))
                claim(pin.hole, label)
            for hole in part.mechanical:
                claim(hole, f'{part.ref} (mechanical)')
                mechanical.add(hole)

        errors.extend(self.stacking()[0])

        parent: dict[Hole, Hole] = {}

        def find(hole: Hole) -> Hole:
            parent.setdefault(hole, hole)
            while parent[hole] != hole:
                parent[hole] = parent[parent[hole]]
                hole = parent[hole]
            return hole

        def union(a: Hole, b: Hole) -> None:
            parent[find(a)] = find(b)

        for net, pts in self.runs:
            for a, b in zip(pts, pts[1:]):
                if a[0] != b[0] and a[1] != b[1]:
                    errors.append(f'DIAGONAL {net} run {hole_name(a)}-{hole_name(b)}')
                    continue
                for hole in line(a, b):
                    if not self.inside(hole):
                        errors.append(f'OFF BOARD {net} run at {hole}')
                    elif hole in mechanical:
                        errors.append(f'RUN OVER {owner[hole]} in {hole_name(hole)}')
                    union(hole, a)
        for part in self.parts:
            for a, b in part.links:
                union(a, b)

        groups: dict[Hole, dict[str | None, set[str]]] = {}
        for label, pin in pins:
            groups.setdefault(find(pin.hole), {}).setdefault(pin.net, set()).add(label)
        for hole, nets in self.run_holes().items():
            for net in nets:
                groups.setdefault(find(hole), {}).setdefault(net, set()).add(f'{net} run')

        fragments: dict[str, list[set[str]]] = {}
        for members in groups.values():
            named = {net: m for net, m in members.items() if net is not None}
            if len(named) > 1:
                errors.append('SHORT ' + ' / '.join(f'{net}: {", ".join(sorted(m))}' for net, m in sorted(named.items())))
            if None in members and sum(len(m) for m in members.values()) > 1:
                errors.append(f'NC PIN CONNECTED {", ".join(sorted(members[None]))}')
            if all(label.endswith(' run') for m in members.values() for label in m):
                warnings.append(f'run touches no pin: {", ".join(sorted(named))}')
            for net, m in named.items():
                fragments.setdefault(net, []).append(m)

        for net, parts in sorted(fragments.items()):
            if len(parts) > 1:
                errors.append(f'OPEN {net}: ' + ' | '.join('{' + ', '.join(sorted(m)) + '}' for m in parts))

        counts: dict[str, list[str]] = {}
        for label, pin in pins:
            if pin.net:
                counts.setdefault(pin.net, []).append(label)
        for net, labels in sorted(counts.items()):
            if len(labels) == 1:
                warnings.append(f'net {net} has one pin only ({labels[0]})')
            if net not in self.nets:
                warnings.append(f'net {net} has no colour')
        return errors, warnings

    def stacking(self) -> tuple[list[str], set[Hole]]:
        """Top-side clashes; a part fits under a module when it is at least 1 mm lower than its standoff."""
        errors: list[str] = []
        clashes: set[Hole] = set()
        bodies = {part.ref: part.body(self) for part in self.parts}
        covers = {part.ref: bodies[part.ref] | {p.hole for p in part.pins} | set(part.mechanical)
                  for part in self.parts}
        for i, a in enumerate(self.parts):
            for b in self.parts[i + 1:]:
                shared = (bodies[a.ref] & covers[b.ref]) | (bodies[b.ref] & covers[a.ref])
                if not shared:
                    continue
                modules = [p for p in (a, b) if isinstance(p, Module)]
                if len(modules) == 1:
                    module = modules[0]
                    low = b if module is a else a
                    if low.height + 1 <= module.standoff:
                        continue
                    reason = f'{low.ref} stands {low.height:g} mm, {module.ref} clears {module.standoff:g} mm'
                else:
                    reason = 'bodies clash'
                holes = ' '.join(hole_name(h) for h in sorted(shared))
                errors.append(f'OVERLAP {a.ref} and {b.ref} in {holes}: {reason}')
                clashes |= shared
        return errors, clashes

    def symbols(self) -> dict[str, str]:
        nets = list(self.nets) + sorted({p.net for part in self.parts for p in part.pins if p.net} - set(self.nets))
        taken: dict[str, str] = {}
        for net in nets:
            options = [ch.upper() for ch in net if ch.isalpha()] + [chr(c) for c in range(65, 91)]
            taken[net] = next(ch for ch in options if ch not in taken.values() and ch != 'X')
        return taken

    def ascii(self) -> str:
        """Hole map: UPPER pin, lower bottom run, - | run links, = top jumper, # nc pin, x mechanical."""
        symbols = self.symbols()
        lines = [[' '] * (3 * self.cols) for _ in range(2 * self.rows - 1)]

        def put(col: int, row: int, ch: str) -> None:
            if 0 <= row < len(lines) and 0 <= col < len(lines[0]):
                lines[row][col] = ch

        for c in range(1, self.cols + 1):
            for r in range(1, self.rows + 1):
                put(3 * (c - 1), 2 * (r - 1), '.')
        for hole, nets in self.run_holes().items():
            ch = symbols[next(iter(nets))].lower() if len(nets) == 1 else '!'
            put(3 * (hole[0] - 1), 2 * (hole[1] - 1), ch)
        for net, pts in self.runs:
            for a, b in zip(pts, pts[1:]):
                seg = line(a, b) if a[0] == b[0] or a[1] == b[1] else []
                for (c1, r1), (c2, r2) in zip(seg, seg[1:]):
                    if r1 == r2:
                        put(3 * (min(c1, c2) - 1) + 1, 2 * (r1 - 1), '-')
                        put(3 * (min(c1, c2) - 1) + 2, 2 * (r1 - 1), '-')
                    else:
                        put(3 * (c1 - 1), 2 * (min(r1, r2) - 1) + 1, '|')
        for part in self.parts:
            for a, b in part.links:
                if a[1] == b[1]:
                    for c in range(3 * (min(a[0], b[0]) - 1) + 1, 3 * (max(a[0], b[0]) - 1)):
                        if c % 3:
                            put(c, 2 * (a[1] - 1), '=')
                elif a[0] == b[0]:
                    for r in range(2 * (min(a[1], b[1]) - 1) + 1, 2 * (max(a[1], b[1]) - 1), 2):
                        put(3 * (a[0] - 1), r, '"')
        for part in self.parts:
            for pin in part.pins:
                put(3 * (pin.hole[0] - 1), 2 * (pin.hole[1] - 1), symbols[pin.net] if pin.net else '#')
            for hole in part.mechanical:
                put(3 * (hole[0] - 1), 2 * (hole[1] - 1), 'x')

        header = [' '] * (3 * self.cols)
        for c in range(1, self.cols + 1):
            for i, ch in enumerate(str(c)):
                header[3 * (c - 1) + i] = ch
        width = len(row_name(self.rows)) + 2
        out = [f'{self.title}  ({self.cols}x{self.rows} holes, top view)', ' ' * width + ''.join(header).rstrip()]
        for i, chars in enumerate(lines):
            prefix = row_name(i // 2 + 1) if i % 2 == 0 else ''
            out.append((prefix.ljust(width) + ''.join(chars)).rstrip())
        out.append('')
        out.append('  '.join(f'{s}={net}' for net, s in self.symbols().items()))
        out.append('UPPER pin, lower run, - | run links, = " top jumper, # nc pin, x mechanical, ! crossing runs')
        out.append('')
        out.extend(self.ascii_bodies(width, header))
        out.append('')
        for part in self.parts:
            out.append(f'{part.ref:8} {part.describe()}')
        return '\n'.join(out)

    def ascii_bodies(self, width: int, header: list[str]) -> list[str]:
        """Top side: UPPER pin, lower body; parts under a module show over it; x mechanical, ! clash."""
        marks = {part.ref: chr(65 + i) for i, part in enumerate(self.parts)}
        cells = {(c, r): '.' for c in range(1, self.cols + 1) for r in range(1, self.rows + 1)}
        for part in sorted(self.parts, key=lambda p: not isinstance(p, Module)):
            for hole in part.body(self):
                cells[hole] = marks[part.ref].lower()
            for pin in part.pins:
                if self.inside(pin.hole):
                    cells[pin.hole] = marks[part.ref]
            for hole in part.mechanical:
                if self.inside(hole):
                    cells[hole] = 'x'
        for hole in self.stacking()[1]:
            cells[hole] = '!'
        out = ['Top side, part bodies', ' ' * width + ''.join(header).rstrip()]
        for r in range(1, self.rows + 1):
            out.append((row_name(r).ljust(width) + ''.join(cells[(c, r)].ljust(3) for c in range(1, self.cols + 1))).rstrip())
        out.append('  '.join(f'{m}={ref}' for ref, m in marks.items()) + '   lower = body, ! = clash')
        return out

    def draw_grid(self, ax, mirror: bool) -> None:
        ax.add_patch(FancyBboxPatch((0, 0), self.width, self.height, boxstyle='round,pad=0,rounding_size=1.5',
                                    fc='#e8d6a0', ec='#8a7440', lw=1.2, zorder=1))
        # One scatter per ring: the SVG stores the marker once instead of a path per hole
        ax.apply_aspect()
        (x0, _), (x1, _) = ax.transData.transform([(0, 0), (1, 0)])
        points_per_mm = (x1 - x0) * 72 / ax.figure.dpi
        xs, ys = zip(*[self.xy(c, r, mirror) for c in range(1, self.cols + 1) for r in range(1, self.rows + 1)])
        for diameter, colour, z in ((1.7, '#c98a3a', 2), (0.9, 'white', 3)):
            ax.scatter(xs, ys, s=(diameter * points_per_mm) ** 2, c=colour, linewidths=0, zorder=z)
        for c in range(1, self.cols + 1):
            if c == 1 or c % 5 == 0 or c == self.cols:
                x, _ = self.xy(c, 1, mirror)
                ax.text(x, self.height + 0.8, str(c), ha='center', va='bottom', fontsize=6, color='#666666')
        for r in range(1, self.rows + 1):
            x, y = self.xy(1, r, mirror)
            ax.text(x - 1.8, y, row_name(r), ha='center', va='center',
                    fontsize=6, color='#666666')

    def draw_runs(self, ax, mirror: bool) -> None:
        for net, pts in self.runs:
            xs, ys = zip(*[self.xy(c, r, mirror) for c, r in pts])
            if mirror:
                ax.plot(xs, ys, color='#9a9a9a', lw=4.2, solid_capstyle='round', solid_joinstyle='round', zorder=4)
                ax.plot(xs, ys, color=self.colour(net), lw=2.4, solid_capstyle='round', solid_joinstyle='round',
                        zorder=5)
            else:
                ax.plot(xs, ys, color=self.colour(net), lw=1.4, ls=(0, (2, 1.5)), zorder=4, alpha=0.8)
        solder = {p for _, pts in self.runs for p in pts} | {p.hole for part in self.parts for p in part.pins}
        for hole in solder:
            ax.add_patch(Circle(self.xy(*hole, mirror), 0.9, fc='#b8b8b8', ec='#777777', lw=0.5, zorder=6))

    def panel(self, ax, mirror: bool) -> None:
        left, right, bottom, top = self.margin
        ax.set_aspect('equal')
        ax.axis('off')
        ax.set_xlim(-left, self.width + right)
        ax.set_ylim(-bottom, self.height + top)
        self.draw_grid(ax, mirror)
        self.draw_runs(ax, mirror)
        for part in self.parts:
            part.draw(ax, self, mirror)
        ax.set_title('BOTTOM - solder side (flipped over the long edge, rows upside down)' if mirror else 'TOP - components', fontsize=10, pad=2)

    def render(self) -> None:
        fig, axes = plt.subplots(2, 1, figsize=self.figsize)
        fig.subplots_adjust(left=0.01, right=0.99, top=0.97, bottom=0.04, hspace=0.08)
        self.panel(axes[0], mirror=False)
        self.panel(axes[1], mirror=True)
        if self.footer:
            fig.text(0.5, 0.015, self.footer, ha='center', fontsize=7.5, color='#444444', wrap=True)
        fig.savefig(self.out, metadata={'Date': None})

    def main(self) -> None:
        errors, warnings = self.check()
        print(self.ascii())
        print()
        for warning in warnings:
            print(f'warning: {warning}')
        for error in errors:
            print(f'ERROR: {error}')
        if errors:
            sys.exit(1)
        if '--svg' in sys.argv:
            self.render()
            print(self.out)
        else:
            print('check ok; add --svg to render')
