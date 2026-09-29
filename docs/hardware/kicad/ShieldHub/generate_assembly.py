"""Export a perforated 3D assembly of the shield hub as a STEP file.

The KiCad board uses guide graphics for unoccupied perfboard holes so that its
electrical DRC stays meaningful. This separate mechanical assembly contains
all 240 physical bores and annular rings.
"""

from pathlib import Path

import cadquery as cq


HERE = Path(__file__).resolve().parent
MODELS = HERE / "models"
PITCH = 2.54
HOLE_X = 100 + (70 - 23 * PITCH) / 2
HOLE_Y = -100 - (30 - 9 * PITCH) / 2


def hole(col, row):
    return HOLE_X + (col - 1) * PITCH, HOLE_Y - (row - 1) * PITCH


board = cq.Workplane("XY").box(70, 30, 1.6).translate((135, -115, -0.8))
tie_holes = {(2, 3), (2, 7)}
drills = []
for col in range(1, 25):
    for row in range(1, 11):
        x, y = hole(col, row)
        radius = 1.75 if (col, row) in tie_holes else 0.5
        drills.append(cq.Solid.makeCylinder(radius, 2.0, cq.Vector(x, y, -1.8)))
board = board.cut(cq.Compound.makeCompound(drills))

assembly = cq.Assembly(name="ShieldHub_30x70_perfboard")
assembly.add(board, name="perforated_base", color=cq.Color(0.08, 0.28, 0.17))

damaged_rings = {(2 + dc, row + dr)
                 for row in (3, 7) for dc, dr in ((0, 0), (1, 0), (-1, 0),
                                                   (0, 1), (0, -1))}
rings_top = []
rings_bottom = []
for col in range(1, 25):
    for row in range(1, 11):
        if (col, row) in damaged_rings:
            continue
        x, y = hole(col, row)
        for z, target in ((0, rings_top), (-1.635, rings_bottom)):
            outer = cq.Solid.makeCylinder(0.9, 0.035, cq.Vector(x, y, z))
            bore = cq.Solid.makeCylinder(0.5, 0.035, cq.Vector(x, y, z))
            target.append(outer.cut(bore))
assembly.add(cq.Compound.makeCompound(rings_top), name="top_tinned_rings",
             color=cq.Color(0.73, 0.75, 0.76))
assembly.add(cq.Compound.makeCompound(rings_bottom), name="bottom_tinned_rings",
             color=cq.Color(0.73, 0.75, 0.76))


def place_model(filename, col, row, name, angle=0):
    x, y = hole(col, row)
    model = cq.Assembly.importStep(str(MODELS / filename))
    assembly.add(model, name=name, loc=cq.Location(cq.Vector(x, y, 0), cq.Vector(0, 0, 1), angle))


place_model("VEML7700_Techfun_approx.step", 18, 6, "U4_VEML7700")
place_model("INMP441_Round_approx.step", 10, 9, "U5_INMP441")
# R5 runs diagonally from E11 to G8, as in the KiCad footprint
place_model("R5_47R_approx.step", 11, 5, "R5_47R", -56.31)
place_model("C2_10u_approx.step", 3, 10, "C2_10u")
place_model("SHT45_pin_header.step", 20, 2, "U3_pin_header")

# LaskaKit STEP uses the centre of its four-pin header as the origin. Rotating
# its positive Y (tongue) into positive board X also aligns its pin row with
# the 2D footprint when the SDA pin is used as the footprint origin.
sht = cq.importers.importStep(str(MODELS / "Temp-HumSensor_SHT45.step")).val()
sht = sht.rotate((0, 0, 0), (0, 0, 1), -90)
x, y = hole(20, 2)
sht = sht.translate((x, y - 3.81, 4.0))
assembly.add(sht, name="U3_LaskaKit_SHT45",
             color=cq.Color(0.65, 0.18, 0.16))

assembly.save(str(HERE / "ShieldHub_assembly.step"), exportType="STEP")
