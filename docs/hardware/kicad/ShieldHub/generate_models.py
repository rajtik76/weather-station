"""Build approximate STEP models of the sensor breakouts and pin headers (needs CadQuery).

The SHT45 STEP is the unmodified LaskaKit model. 3D Y is opposite 2D footprint Y.
"""

from pathlib import Path

import cadquery as cq


HERE = Path(__file__).resolve().parent
MODELS = HERE / "models"
MODELS.mkdir(exist_ok=True)
MODULE_BASE = 4.0

BLUE = cq.Color(0.08, 0.23, 0.62)
BLACK = cq.Color(0.025, 0.027, 0.035)
DARK = cq.Color(0.12, 0.12, 0.13)
GOLD = cq.Color(0.83, 0.68, 0.3)
SILVER = cq.Color(0.72, 0.74, 0.76)
WHITE = cq.Color(0.92, 0.92, 0.87)


def box(x, y, z, sx, sy, sz):
    return cq.Workplane("XY").box(sx, sy, sz).translate((x, y, z))


def cylinder(x, y, z, radius, height):
    return cq.Workplane("XY").circle(radius).extrude(height).translate((x, y, z))


def drilled(shape, points, diameter):
    for x, y in points:
        tool = cylinder(x, y, -5, diameter / 2, 40)
        shape = shape.cut(tool)
    return shape


def pin_header(assembly, points, strips):
    for index, (x, y, sx, sy) in enumerate(strips):
        assembly.add(box(x, y, 1.25, sx, sy, 2.5),
                     name=f"header_spacer_{index}", color=BLACK)
    for index, (x, y) in enumerate(points):
        assembly.add(cylinder(x, y, -6.0, 0.32, 11.6),
                     name=f"header_pin_{index}", color=GOLD)


veml_pins = [(0, step * 2.54) for step in range(5)]
veml = cq.Assembly(name="VEML7700_Techfun_approx")
veml_board = box(-5.715, 5.08, MODULE_BASE + 0.8, 17.0, 17.0, 1.6)
veml_board = drilled(veml_board, veml_pins, 0.9)
veml_board = drilled(veml_board, [(-11.43, -0.635), (-11.43, 10.795)], 2.5)
veml.add(veml_board, name="blue_breakout_pcb", color=BLUE)
pin_header(veml, veml_pins, [(0, 5.08, 2.54, 12.7)])
veml.add(box(-5.7, 5.5, MODULE_BASE + 2.05, 2.4, 2.4, 0.9),
         name="light_sensor_housing", color=WHITE)
veml.add(box(-5.7, 5.5, MODULE_BASE + 2.53, 1.0, 0.8, 0.08),
         name="light_sensor_window", color=cq.Color(0.53, 0.45, 0.67))
for index, x in enumerate((-2.0, -8.8)):
    veml.add(box(x, 1.0, MODULE_BASE + 2.8, 1.4, 2.1, 2.4),
             name=f"support_ic_{index}", color=DARK)
veml.save(str(MODELS / "VEML7700_Techfun_approx.step"), exportType="STEP")


mic_pins = [(x, y) for x in (0, -7.62) for y in (0, 2.54, 5.08)]
mic = cq.Assembly(name="INMP441_round_approx")
mic_board = cylinder(-3.81, 2.54, MODULE_BASE, 7, 1.6)
mic_board = drilled(mic_board, mic_pins, 0.9)
mic_board = drilled(mic_board, [(-3.81, 2.54)], 0.8)
mic_board = drilled(mic_board, [(-3.81, -4.46), (-3.81, 9.54)], 1.8)
mic.add(mic_board, name="round_black_pcb", color=BLACK)
pin_header(mic, mic_pins, [(0, 2.54, 2.54, 7.62),
                           (-7.62, 2.54, 2.54, 7.62)])
mic.add(cylinder(-3.81, 2.54, MODULE_BASE - 1.3, 2.7, 1.3),
        name="microphone_can", color=SILVER)
port_rim = cylinder(-3.81, 2.54, MODULE_BASE + 1.6, 1.0, 0.04)
port_rim = drilled(port_rim, [(-3.81, 2.54)], 0.8)
mic.add(port_rim, name="outward_sound_port", color=GOLD)
for index, (x, y) in enumerate(mic_pins):
    ring = cylinder(x, y, MODULE_BASE + 1.6, 0.88, 0.04)
    ring = drilled(ring, [(x, y)], 0.9)
    mic.add(ring, name=f"pad_ring_{index}", color=GOLD)
mic.save(str(MODELS / "INMP441_Round_approx.step"), exportType="STEP")


sht_header = cq.Assembly(name="SHT45_pin_header")
pin_header(sht_header, [(0, -step * 2.54) for step in range(4)],
           [(0, -3.81, 2.54, 10.16)])
sht_header.save(str(MODELS / "SHT45_pin_header.step"), exportType="STEP")


# R5 lies diagonally over 3 x 2 holes
R5_SPAN = (3 ** 2 + 2 ** 2) ** 0.5 * 2.54
R5_AXIS = 1.3  # body lies on the board, clear of the mic can at 2.7 mm
R5_BODY = 6.3
resistor = cq.Assembly(name="R5_47R")
resistor.add(box(0, -R5_SPAN / 2, R5_AXIS, 2.5, R5_BODY, 2.5),
             name="resistor_body", color=cq.Color(0.77, 0.62, 0.4))
for index, y in enumerate((0, -R5_SPAN)):
    resistor.add(cylinder(0, y, -1.6, 0.32, R5_AXIS + 1.6),
                 name=f"resistor_lead_{index}", color=SILVER)
    reach = (R5_SPAN - R5_BODY) / 2 + 0.5
    centre = y - reach / 2 if y == 0 else y + reach / 2
    resistor.add(box(0, centre, R5_AXIS, 0.64, reach, 0.64),
                 name=f"resistor_lead_bend_{index}", color=SILVER)
resistor.save(str(MODELS / "R5_47R_approx.step"), exportType="STEP")


capacitor = cq.Assembly(name="C2_10u")
capacitor.add(cylinder(0, 1.27, 1.5, 2.5, 10),
              name="electrolytic_can", color=cq.Color(0.04, 0.13, 0.31))
capacitor.add(cylinder(0, 1.27, 11.5, 2.3, 0.12),
              name="electrolytic_top", color=SILVER)
for index, y in enumerate((0, 2.54)):
    capacitor.add(cylinder(0, y, 0, 0.28, 2),
                  name=f"capacitor_lead_{index}", color=SILVER)
capacitor.save(str(MODELS / "C2_10u_approx.step"), exportType="STEP")
