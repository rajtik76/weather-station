"""Build the base board layout (60 x 80 mm perfboard) from layout.py.

Run with KiCad's bundled Python 3.9 after generate_models.py. Runs are 0 ohm links (JP*, board-only,
absent from the schematic) on the component side and solder bridges (B.Cu tracks) on the solder side.
"""

from pathlib import Path
import pcbnew as pcb

from layout import (BOARD_H, BOARD_W, COLS, HOLE_X, HOLE_Y, LINK_SPANS, NETS, PARTS, PITCH,
                    ROWS, FOOTPRINT_PADS, check, free_holes, col_number, hole_name, occupied, row_letter, wiring)


HERE = Path(__file__).resolve().parent
PRETTY = HERE / "BaseBoard.pretty"
PRETTY.mkdir(exist_ok=True)
STOCK = "${KICAD10_3DMODEL_DIR}"
OWN = "${KIPRJMOD}/models"
BOARD_X, BOARD_Y = 100.0, 100.0
PAD, DRILL = 1.8, 1.0
TRACK = 1.0


def mm(value):
    return pcb.FromMM(value)


def point(x, y):
    return pcb.VECTOR2I(mm(x), mm(y))


def hole(col, row):
    return point(BOARD_X + HOLE_X + (col - 1) * PITCH, BOARD_Y + HOLE_Y + (row - 1) * PITCH)


def footprint(name, lines=(), circles=(), models=(), attributes="through_hole", value=None, extra=()):
    content = [f'(footprint "{name}" (version 20240108) (generator "pcbnew")',
               '  (layer "F.Cu")',
               '  (property "Reference" "REF**" (at 0 -2.5 0) (layer "F.SilkS") '
               '(effects (font (size 1 1) (thickness 0.15))))',
               f'  (property "Value" "{value or name}" (at 0 2.5 0) (layer "F.Fab") '
               '(effects (font (size 1 1) (thickness 0.15))))',
               f'  (attr {attributes})',
               *extra]
    for number, x, y in FOOTPRINT_PADS.get(name, []):
        content.append(f'  (pad "{number}" thru_hole circle (at {round(x, 4)} {round(y, 4)}) '
                       f'(size {PAD} {PAD}) (drill {DRILL}) (layers "*.Cu" "*.Mask"))')
    for x1, y1, x2, y2, layer in lines:
        width = 0.05 if layer == "F.CrtYd" else 0.12
        content.append(f'  (fp_line (start {round(x1, 4)} {round(y1, 4)}) (end {round(x2, 4)} {round(y2, 4)}) '
                       f'(stroke (width {width}) (type solid)) (layer "{layer}"))')
    for cx, cy, radius, layer in circles:
        content.append(f'  (fp_circle (center {cx} {cy}) (end {cx + radius} {cy}) '
                       f'(stroke (width 0.12) (type solid)) (fill none) (layer "{layer}"))')
    for path, offset, rotation in models:
        content.append(f'  (model "{path}" (offset (xyz {offset[0]} {offset[1]} {offset[2]})) '
                       f'(scale (xyz 1 1 1)) (rotate (xyz {rotation[0]} {rotation[1]} {rotation[2]})))')
    content.append(")")
    (PRETTY / f"{name}.kicad_mod").write_text("\n".join(content) + "\n")


def rectangle(x1, y1, x2, y2, layer):
    return [(x1, y1, x2, y1, layer), (x2, y1, x2, y2, layer),
            (x2, y2, x1, y2, layer), (x1, y2, x1, y1, layer)]


def outline(x1, y1, x2, y2):
    return (rectangle(x1, y1, x2, y2, "F.SilkS") + rectangle(x1, y1, x2, y2, "F.Fab")
            + rectangle(x1, y1, x2, y2, "F.CrtYd"))


footprint(
    "DOIT_ESP32_DevKit_V1_Socketed",
    # Courtyard is the two headers only: low parts may sit under the raised DevKit
    lines=(rectangle(8.64, 40.73, 16.64, 46.53, "F.Fab")
           + rectangle(-1.76, -6.57, 27.24, 45.43, "F.Fab")
           + rectangle(3.7, -6.47, 21.7, -0.5, "F.Fab")
           + outline(-1.27, -1.27, 1.27, 36.83)
           + outline(24.13, -1.27, 26.67, 36.83)),
    models=[
        (f"{STOCK}/Connector_PinSocket_2.54mm.3dshapes/PinSocket_1x15_P2.54mm_Vertical.step", (0, 0, 0), (0, 0, 0)),
        (f"{STOCK}/Connector_PinSocket_2.54mm.3dshapes/PinSocket_1x15_P2.54mm_Vertical.step", (25.4, 0, 0), (0, 0, 0)),
        (f"{OWN}/DOIT_ESP32_DevKit_V1_30pin_microUSB.step.gz", (0.64, -42.83, 12.67), (0, 0, 0)),
    ],
    value="ESP32 DevKit V1",
    extra=['  (jumper_pad_groups ("2" "17"))'])
footprint(
    "BMP280_Module_Socketed",
    lines=(outline(-1.27, -1.27, 1.27, 13.97)
           + rectangle(-1.27, -1.15, 8.73, 13.85, "F.Fab")),
    models=[
        (f"{STOCK}/Connector_PinSocket_2.54mm.3dshapes/PinSocket_1x06_P2.54mm_Vertical.step", (0, 0, 0), (0, 0, 0)),
        (f"{OWN}/BMP280_Module_approx.step", (0, 0, 0), (0, 0, 0)),
    ],
    value="BMP280")
footprint(
    "R_Socketed_1x02",
    lines=([(-1.27, -1.1, -1.27, 3.64, "F.SilkS"), (1.27, -1.1, 1.27, 3.64, "F.SilkS")]
           + rectangle(-1.27, -1.27, 1.27, 3.81, "F.Fab")
           + rectangle(-1.2, -1.2, 1.2, 3.74, "F.CrtYd")),
    models=[
        (f"{STOCK}/Connector_PinSocket_2.54mm.3dshapes/PinSocket_1x02_P2.54mm_Vertical.step", (0, 0, 0), (0, 0, 0)),
        (f"{STOCK}/Resistor_THT.3dshapes/R_Axial_DIN0207_L6.3mm_D2.5mm_P2.54mm_Vertical.step", (0, 0, 8.5), (0, 0, 90)),
    ],
    value="R in socket")
footprint(
    "R_Axial_P10.16",
    lines=outline(1.93, -1.25, 8.23, 1.25),
    models=[(f"{STOCK}/Resistor_THT.3dshapes/R_Axial_DIN0207_L6.3mm_D2.5mm_P10.16mm_Horizontal.step", (0, 0, 0), (0, 0, 0))],
    value="R")
footprint(
    "C_Disc_P2.54",
    lines=rectangle(-0.25, -0.8, 2.79, 0.8, "F.Fab") + rectangle(-0.25, -0.8, 2.79, 0.8, "F.CrtYd"),
    models=[(f"{STOCK}/Capacitor_THT.3dshapes/C_Disc_D3.0mm_W1.6mm_P2.50mm.step", (0.02, 0, 0), (0, 0, 0))],
    value="C")
footprint(
    "CP_Radial_D5.0mm_P2.54",
    lines=[(-1.9, -2.3, -1.9, -1.3, "F.SilkS"), (-2.4, -1.8, -1.4, -1.8, "F.SilkS")],
    circles=[(1.27, 0, 2.5, "F.SilkS"), (1.27, 0, 2.5, "F.Fab"), (1.27, 0, 2.5, "F.CrtYd")],
    models=[(f"{STOCK}/Capacitor_THT.3dshapes/CP_Radial_D5.0mm_P2.00mm.step", (0.27, 0, 0), (0, 0, 0))],
    value="CP")
for count in (2, 9):
    footprint(
        f"PinHeader_1x{count:02d}",
        lines=outline(-1.27, -1.27, 1.27, count * PITCH - 1.27),
        models=[(f"{STOCK}/Connector_PinHeader_2.54mm.3dshapes/PinHeader_1x{count:02d}_P2.54mm_Vertical.step",
                 (0, 0, 0), (0, 0, 0))],
        value=f"1x{count:02d} pin header")
for span, name in LINK_SPANS.items():
    footprint(
        name,
        lines=(rectangle(1.4, -0.7, span * PITCH - 1.4, 0.7, "F.SilkS")
               + rectangle(0.0, -1.25, span * PITCH, 1.25, "F.Fab")),
        models=[(f"{OWN}/{name}.step", (0, 0, 0), (0, 0, 0))],
        attributes="through_hole board_only exclude_from_pos_files exclude_from_bom",
        value="0R",
        extra=['  (jumper_pad_groups ("1" "2"))'])
# PERF1: no pads, only the 3D model of tinned rings and bridges
footprint(
    "Perfboard_22x27",
    models=[(f"{OWN}/Perfboard_rings_and_bridges.step.gz", (0, 0, 0), (0, 0, 0))],
    attributes="board_only exclude_from_pos_files exclude_from_bom",
    value="perfboard 60 x 80 mm, 22 x 27 holes")

hole_net = check()

board = pcb.BOARD()
block = pcb.TITLE_BLOCK()
block.SetTitle("ESP32 base board, 60 x 80 mm perfboard")
block.SetRevision("1")
block.SetDate("2026-09-30")
board.SetTitleBlock(block)
board.GetDesignSettings().SetCopperLayerCount(2)

nets = {}
for key, name in NETS.items():
    net = pcb.NETINFO_ITEM(board, name)
    board.Add(net)
    nets[key] = net


def add(ref, name, value, origin, angle):
    item = pcb.FootprintLoad(str(PRETTY), name)
    if item is None:
        raise RuntimeError(name)
    item.SetFPID(pcb.LIB_ID("BaseBoard", name))
    item.SetReference(ref)
    item.SetValue(value)
    item.SetPosition(hole(*origin))
    item.SetOrientationDegrees(angle)
    board.Add(item)
    return item


# pcbnew pads must agree with layout.py to 0.01 mm
spots = occupied()
for ref, name, value, origin, angle, pad_nets in PARTS:
    item = add(ref, name, value, origin, angle)
    for pad in item.Pads():
        signal = pad_nets.get(pad.GetNumber())
        if signal:
            pad.SetNet(nets[signal])
        spot = next(key for key, entry in spots.items() if entry[:2] == (ref, pad.GetNumber()))
        offset = pad.GetPosition() - hole(*spot)
        if max(abs(offset.x), abs(offset.y)) > mm(0.01):
            raise RuntimeError(f"{ref} pad {pad.GetNumber()} is off its hole")
perf = add("PERF1", "Perfboard_22x27", "perfboard 22 x 27", (1, 1), 0)
perf.Reference().SetVisible(False)

links, bridges = wiring()
for index, (signal, a, b) in enumerate(links, start=1):
    span = max(abs(b[0] - a[0]), abs(b[1] - a[1]))
    angle = {(1, 0): 0, (-1, 0): 180, (0, 1): -90, (0, -1): 90}[
        ((b[0] > a[0]) - (b[0] < a[0]), (b[1] > a[1]) - (b[1] < a[1]))]
    link = add(f"JP{index}", LINK_SPANS[span], "0R", a, angle)
    link.Value().SetVisible(False)
    link.Reference().SetTextSize(point(0.8, 0.8))
    link.Reference().SetTextThickness(mm(0.1))
    link.Reference().SetPosition(point(BOARD_X + HOLE_X + ((a[0] + b[0]) / 2 - 1) * PITCH,
                                       BOARD_Y + HOLE_Y + ((a[1] + b[1]) / 2 - 1) * PITCH))
    link.Reference().SetTextAngleDegrees(0 if a[1] == b[1] else 90)
    for pad in link.Pads():
        pad.SetNet(nets[signal])
        offset = pad.GetPosition() - hole(*(a if pad.GetNumber() == "1" else b))
        if max(abs(offset.x), abs(offset.y)) > mm(0.01):
            raise RuntimeError(f"JP{index} pad {pad.GetNumber()} is off its hole")

# Labels moved off pads onto free holes (column, row)
LABELS = {"J1": (11.6, 17), "C1": (6.5, 15.3), "C2": (8.9, 18.6), "R2": (8.5, 19.8), "R1": (10.5, 19.8)}
for item in board.GetFootprints():
    spot = LABELS.get(item.GetReference())
    if spot:
        col, row = spot
        item.Reference().SetPosition(point(BOARD_X + HOLE_X + (col - 1) * PITCH, BOARD_Y + HOLE_Y + (row - 1) * PITCH))
        item.Reference().SetTextAngleDegrees(0)

(HERE / "fp-lib-table").write_text(
    '(fp_lib_table (lib (name "BaseBoard") (type "KiCad") '
    '(uri "${KIPRJMOD}/BaseBoard.pretty") (options "") (descr "Base board parts")))\n')


def shape(kind, layer, width):
    item = pcb.PCB_SHAPE(board)
    item.SetShape(kind)
    item.SetLayer(layer)
    item.SetWidth(mm(width))
    return item


corners = [(BOARD_X, BOARD_Y), (BOARD_X + BOARD_W, BOARD_Y),
           (BOARD_X + BOARD_W, BOARD_Y + BOARD_H), (BOARD_X, BOARD_Y + BOARD_H)]
for a, b in zip(corners, corners[1:] + corners[:1]):
    edge = shape(pcb.S_SEGMENT, pcb.Edge_Cuts, 0.05)
    edge.SetStart(point(*a))
    edge.SetEnd(point(*b))
    board.Add(edge)

# Free holes as rings on User.Drawings (keeps copper readable)
for col, row in free_holes():
    ring = shape(pcb.S_CIRCLE, pcb.Dwgs_User, 0.08)
    ring.SetCenter(hole(col, row))
    ring.SetEnd(hole(col, row) + point(0.5, 0))
    board.Add(ring)

for signal, a, b in bridges:
    track = pcb.PCB_TRACK(board)
    track.SetStart(hole(*a))
    track.SetEnd(hole(*b))
    track.SetWidth(mm(TRACK))
    track.SetLayer(pcb.B_Cu)
    track.SetNet(nets[signal])
    board.Add(track)


def text(content, x, y, layer, size=0.9, justify=None):
    label = pcb.PCB_TEXT(board)
    label.SetText(content)
    label.SetPosition(point(x, y))
    label.SetLayer(layer)
    label.SetTextSize(point(size, size))
    label.SetTextThickness(mm(0.12))
    if justify == "left":
        label.SetHorizJustify(pcb.GR_TEXT_H_ALIGN_LEFT)
    if layer == pcb.B_SilkS:
        label.SetMirrored(True)
    board.Add(label)


# Hole labels in the margins, as printed on the perfboard
for layer in (pcb.F_SilkS, pcb.B_SilkS):
    for col in range(1, COLS + 1):
        x = BOARD_X + HOLE_X + (col - 1) * PITCH
        text(col_number(col), x, BOARD_Y + 2.2, layer, 0.8)
        text(col_number(col), x, BOARD_Y + BOARD_H - 2.2, layer, 0.8)
    for row in range(1, ROWS + 1):
        y = BOARD_Y + HOLE_Y + (row - 1) * PITCH
        text(row_letter(row), BOARD_X + 1.4, y, layer, 0.8)
        text(row_letter(row), BOARD_X + BOARD_W - 1.4, y, layer, 0.8)

where = {entry[:2]: spot for spot, entry in spots.items()}


def at(ref, number):
    return hole_name(*where[(ref, number)])


jp1 = links[0]
NOTES = [
    "COMPONENT SIDE, upright. Rows A-Z(+A) from the bottom up, columns 22-01 left to right.",
    "JP* = 0R links on the component side, body flat on the board.",
    "B.Cu = solder bridges between neighbouring pads on the solder side.",
    f"U1: two 1x15 female headers {at('U1', '1')}-{at('U1', '15')} and {at('U1', '16')}-{at('U1', '30')}, USB to the left.",
    f"U2: 1x6 female header {at('U2', '6')}-{at('U2', '1')}; BMP280 pins SDO..VCC left to right,",
    "    chip side up, breakout towards the bottom edge.",
    f"R2 + R1: one 1x4 female header {at('R2', '1')}-{at('R1', '1')} (3V3 SDA | SCL 3V3).",
    f"JP1 {hole_name(*jp1[1])}-{hole_name(*jp1[2])} lies under the breakout: fit it before U2.",
    f"R3 {at('R3', '1')}-{at('R3', '2')}, R4 {at('R4', '1')}-{at('R4', '2')}: lie flat under the DevKit.",
    f"J1 {at('J1', '1')}-{at('J1', '9')}: GND SCK WS SD 3V3 GND drain SDA SCL.",
    f"J2 {at('J2', '1')} = 5V, {at('J2', '2')} = GND.",
    f"Drain on GND through the {at('J1', '6')}-{at('J1', '7')} bridge; leave it open to float.",
]
for index, note in enumerate(NOTES):
    text(note, BOARD_X + BOARD_W + 6, BOARD_Y + 2 + index * 1.8, pcb.Cmts_User, 1.0, "left")

pcb.SaveBoard(str(HERE / "BaseBoard.kicad_pcb"), board)
print(f"{len(links)} links, {len(bridges)} bridges, {len(free_holes())} holes unused")
