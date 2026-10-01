"""Build STEP models (BMP280 breakout, 0 ohm links, perfboard rings, solder bridges); needs CadQuery, run before generate_board.py.

The DevKit model is a downloaded file (models/DOIT_ESP32_DevKit_V1-SOURCE.md). 3D Y is opposite 2D footprint Y.
"""

import gzip
from pathlib import Path

import cadquery as cq

from layout import LINK_SPANS, PITCH, free_holes, wiring


HERE = Path(__file__).resolve().parent
MODELS = HERE / "models"
MODELS.mkdir(exist_ok=True)
SOCKET = 8.5  # height of the female header the breakout plugs into
SPACER = 2.54  # plastic of the male header under the breakout
BOARD = 1.2

PURPLE = cq.Color(0.33, 0.12, 0.48)
BLACK = cq.Color(0.025, 0.027, 0.035)
GOLD = cq.Color(0.83, 0.68, 0.3)
SILVER = cq.Color(0.72, 0.74, 0.76)
DARK = cq.Color(0.12, 0.12, 0.13)


def box(x, y, z, sx, sy, sz):
    return cq.Workplane("XY").box(sx, sy, sz).translate((x, y, z))


def cylinder(x, y, z, radius, height):
    return cq.Workplane("XY").circle(radius).extrude(height).translate((x, y, z))


def drilled(shape, points, diameter):
    for x, y in points:
        shape = shape.cut(cylinder(x, y, -5, diameter / 2, 40))
    return shape


# BMP280: pin 1 (VCC) is the origin, pins run along 3D -Y, the breakout reaches 10 mm to +X.
pins = [(0, -step * PITCH) for step in range(6)]
middle = -2.5 * PITCH
bottom = SOCKET + SPACER

bmp = cq.Assembly(name="BMP280_module_approx")
breakout = box(3.73, middle, bottom + BOARD / 2, 10.0, 15.0, BOARD)
breakout = drilled(breakout, pins, 1.0)
breakout = drilled(breakout, [(6.5, middle + 5.2), (6.5, middle - 5.2)], 3.0)
bmp.add(breakout, name="purple_breakout_pcb", color=PURPLE)
bmp.add(box(0, middle, SOCKET + SPACER / 2, 2.54, 15.24, SPACER),
        name="header_spacer", color=BLACK)
top = bottom + BOARD
pin_low, pin_high = SOCKET - 6.0, top + 0.6
for index, (x, y) in enumerate(pins):
    bmp.add(box(x, y, (pin_low + pin_high) / 2, 0.64, 0.64, pin_high - pin_low),
            name=f"header_pin_{index}", color=GOLD)
bmp.add(box(3.6, middle, top + 0.47, 2.0, 2.5, 0.94), name="bmp280_lid", color=SILVER)
bmp.add(cylinder(3.6 - 0.4, middle + 0.6, top + 0.94, 0.2, 0.02), name="bmp280_vent", color=DARK)
for index, (x, y) in enumerate(((2.4, middle + 4.0), (2.4, middle - 4.0),
                                (4.8, middle + 3.0), (4.8, middle - 3.0))):
    bmp.add(box(x, y, top + 0.25, 0.8, 1.6, 0.5), name=f"passive_{index}", color=DARK)
bmp.save(str(MODELS / "BMP280_Module_approx.step"), exportType="STEP")


# 0 ohm links: pin 1 is the origin, pin 2 lies span holes along +X.
BEIGE = cq.Color(0.83, 0.72, 0.52)
LEAD, BODY_LENGTH, BODY_RADIUS = 0.3, 6.3, 1.25
for span, name in LINK_SPANS.items():
    length = span * PITCH
    link = cq.Assembly(name=name)
    body = cq.Solid.makeCylinder(BODY_RADIUS, BODY_LENGTH, cq.Vector((length - BODY_LENGTH) / 2, 0, BODY_RADIUS),
                                 cq.Vector(1, 0, 0))
    link.add(body, name="body", color=BEIGE)
    band = cq.Solid.makeCylinder(BODY_RADIUS + 0.02, 0.8, cq.Vector(length / 2 - 0.4, 0, BODY_RADIUS), cq.Vector(1, 0, 0))
    link.add(band, name="zero_ohm_band", color=BLACK)
    leads = [cq.Solid.makeCylinder(LEAD, length, cq.Vector(0, 0, BODY_RADIUS), cq.Vector(1, 0, 0))]
    for x in (0, length):
        leads.append(cq.Solid.makeCylinder(LEAD, BODY_RADIUS + 2.6, cq.Vector(x, 0, -2.6)))
    link.add(cq.Compound.makeCompound(leads), name="leads", color=SILVER)
    link.save(str(MODELS / f"{name}.step"), exportType="STEP")


# Perfboard: origin at hole A01; rings on free holes only (KiCad draws the pads).
TIN = cq.Color(0.78, 0.79, 0.8)
HOLE = cq.Color(0.02, 0.02, 0.02)
THICKNESS, COPPER = 1.6, 0.035


def at(col, row):
    return (col - 1) * PITCH, -(row - 1) * PITCH


rings, bores = [], []
for col, row in free_holes():
    x, y = at(col, row)
    for z in (0, -THICKNESS - COPPER):
        outer = cq.Solid.makeCylinder(0.9, COPPER, cq.Vector(x, y, z))
        rings.append(outer.cut(cq.Solid.makeCylinder(0.5, COPPER, cq.Vector(x, y, z))))
        bores.append(cq.Solid.makeCylinder(0.5, COPPER / 2, cq.Vector(x, y, z)))
beads = []
for _, a, b in wiring()[1]:
    (x1, y1), (x2, y2) = at(*a), at(*b)
    bead = cq.Workplane("XY").box(abs(x2 - x1) + 1.2, abs(y2 - y1) + 1.2, 0.5)
    beads.append(bead.edges("|Z").fillet(0.55).val().translate(
        cq.Vector((x1 + x2) / 2, (y1 + y2) / 2, -THICKNESS - COPPER - 0.25)))
perfboard = cq.Assembly(name="Perfboard_rings_and_bridges")
perfboard.add(cq.Compound.makeCompound(rings), name="tinned_rings", color=TIN)
perfboard.add(cq.Compound.makeCompound(bores), name="holes", color=HOLE)
perfboard.add(cq.Compound.makeCompound(beads), name="solder_bridges", color=TIN)
step = MODELS / "Perfboard_rings_and_bridges.step"
perfboard.save(str(step), exportType="STEP")
(MODELS / "Perfboard_rings_and_bridges.step.gz").write_bytes(gzip.compress(step.read_bytes(), 9, mtime=0))
step.unlink()
