"""Build the KiCad layout of the 30 x 70 mm isolated-pad perfboard.

Run with KiCad's bundled Python 3.9. Tracks on B.Cu represent soldered tinned
wire on the back of an already manufactured perfboard. Tracks on F.Cu represent
insulated top-side wire links. Neither layer is intended for etched fabrication.
"""

from pathlib import Path
import pcbnew as pcb


HERE = Path(__file__).resolve().parent
PRETTY = HERE / "ShieldHub.pretty"
PRETTY.mkdir(exist_ok=True)
PITCH = 2.54
BOARD_X = 100.0
BOARD_Y = 100.0
HOLE_X = BOARD_X + (70.0 - 23 * PITCH) / 2
HOLE_Y = BOARD_Y + (30.0 - 9 * PITCH) / 2


def mm(value):
    return pcb.FromMM(value)


def point(x, y):
    return pcb.VECTOR2I(mm(x), mm(y))


def hole(col, row):
    return point(HOLE_X + (col - 1) * PITCH, HOLE_Y + (row - 1) * PITCH)


def footprint(name, pads, lines, value, circles=(), models=()):
    """Write a through-hole module footprint with pin 1 at its local origin."""
    content = [f'(footprint "{name}" (version 20240108) (generator "pcbnew")',
               '  (layer "F.Cu")',
               f'  (property "Reference" "REF**" (at 0 -2 0) (layer "F.SilkS") (effects (font (size 1 1) (thickness 0.15))))',
               f'  (property "Value" "{value}" (at 0 2 0) (layer "F.Fab") (effects (font (size 1 1) (thickness 0.15))))',
               '  (attr through_hole)']
    for number, x, y, signal in pads:
        content.append(f'  (pad "{number}" thru_hole circle (at {x} {y}) (size 1.7 1.7) '
                       f'(drill 0.9) (layers "*.Cu" "*.Mask") '
                       f'(pinfunction "{signal}") (pintype "passive"))')
    for x1, y1, x2, y2, layer in lines:
        content.append(f'  (fp_line (start {x1} {y1}) (end {x2} {y2}) '
                       f'(stroke (width 0.12) (type solid)) (layer "{layer}"))')
    for cx, cy, radius, layer in circles:
        content.append(f'  (fp_circle (center {cx} {cy}) (end {cx + radius} {cy}) '
                       f'(stroke (width 0.12) (type solid)) (fill none) (layer "{layer}"))')
    for model in models:
        filename, offset, rotation, *scale_override = model
        ox, oy, oz = offset
        rx, ry, rz = rotation
        sx, sy, sz = scale_override[0] if scale_override else (1, 1, 1)
        content.append(f'  (model "${{KIPRJMOD}}/models/{filename}" '
                       f'(offset (xyz {ox} {oy} {oz})) '
                       f'(scale (xyz {sx} {sy} {sz})) '
                       f'(rotate (xyz {rx} {ry} {rz})))')
    content.append(')')
    (PRETTY / f'{name}.kicad_mod').write_text('\n'.join(content) + '\n')


def rectangle(x1, y1, x2, y2, layer="F.Fab"):
    return [(x1, y1, x2, y1, layer), (x2, y1, x2, y2, layer),
            (x2, y2, x1, y2, layer), (x1, y2, x1, y1, layer)]


footprint("FTP_8_Wires",
          [(1, 0, 0, "SDA"), (2, 0, 2.54, "SCL"),
           (3, 0, 5.08, "GND"), (4, 0, 7.62, "SD"),
           (5, 0, 12.7, "SCK"), (6, 0, 15.24, "WS"),
           (7, 0, 17.78, "GND"), (8, 0, 20.32, "3V3")],
          rectangle(-1.25, -1.25, 1.25, 21.57), "FTP cable")
SHT_OUTLINE = [
    (y, x + 3.81) for x, y in [
        (-9.53, -1.27), (9.53, -1.27), (9.53, 15.24),
        (4.44, 15.24), (4.44, 8.25), (3.44, 8.25),
        (3.44, 15.24), (2.54, 15.24), (2.54, 22.86),
        (-2.54, 22.86), (-2.54, 15.24), (-3.44, 15.24),
        (-3.44, 8.25), (-4.44, 8.25), (-4.44, 15.24),
        (-9.53, 15.24),
    ]
]
footprint("SHT45_LaskaKit",
          [(1, 0, 0, "SDA"), (2, 0, 2.54, "SCL"),
           (3, 0, 5.08, "GND"), (4, 0, 7.62, "VCC")],
          [(x1, y1, x2, y2, "F.Fab")
           for (x1, y1), (x2, y2) in zip(SHT_OUTLINE,
                                         SHT_OUTLINE[1:] + SHT_OUTLINE[:1])],
          "SHT45", models=[
              ("Temp-HumSensor_SHT45.step", (0, -3.81, 4), (0, 0, -90), (1, -1, 1)),
              ("SHT45_pin_header.step", (0, 0, 0), (0, 0, 0)),
          ])
footprint("VEML7700_Techfun",
          [(1, 0, 0, "VIN"), (2, 0, -2.54, "3Vo"),
           (3, 0, -5.08, "GND"), (4, 0, -7.62, "SCL"),
           (5, 0, -10.16, "SDA")],
          rectangle(-13.97, -13.335, 2.54, 3.175), "VEML7700",
          models=[("VEML7700_Techfun_approx.step", (0, 0, 0), (0, 0, 0))])
footprint("INMP441_Round",
          [(1, -7.62, 0, "GND"), (2, -7.62, -2.54, "VDD"),
           (3, -7.62, -5.08, "SD"), (4, 0, 0, "L/R"),
           (5, 0, -2.54, "WS"), (6, 0, -5.08, "SCK")],
          [(-3.81, -9.54, -3.81, 4.46, "F.Fab"),
           (-10.81, -2.54, 3.19, -2.54, "F.Fab")], "INMP441",
          [(-3.81, -2.54, 7.0, "F.Fab")],
          [("INMP441_Round_approx.step", (0, 0, 0), (0, 0, 0))])
# R5 lies diagonally over 3 x 2 holes
R5_SPAN = (3 ** 2 + 2 ** 2) ** 0.5 * PITCH
footprint("R_Axial_P9_16",
          [(1, 0, 0, "1"), (2, 0, round(R5_SPAN, 4), "2")],
          rectangle(-1.25, 1.43, 1.25, round(R5_SPAN - 1.43, 4)), "47R",
          models=[("R5_47R_approx.step", (0, 0, 0), (0, 0, 0))])
footprint("C_Radial_P2_54",
          [(1, 0, 0, "+"), (2, 0, -2.54, "-")],
          rectangle(-1.5, -3.8, 1.5, 1.25), "capacitor",
          models=[("C2_10u_approx.step", (0, 0, 0), (0, 0, 0))])

board = pcb.BOARD()
board.SetTitleBlock(pcb.TITLE_BLOCK())
board.GetTitleBlock().SetTitle("Shield hub, 30 x 70 mm perfboard")
board.GetTitleBlock().SetRevision("1")

nets = {}
for name in ("3V3", "GND", "SDA", "SCL", "SD", "SD_MIC", "SCK", "WS"):
    # Power nets carry the global names of the schematic's power symbols
    net_name = {"3V3": "+3V3", "GND": "GND"}.get(name, f"/{name}")
    net = pcb.NETINFO_ITEM(board, net_name)
    board.Add(net)
    nets[name] = net
unconnected_3vo = pcb.NETINFO_ITEM(board, "unconnected-(U4-3Vo-Pad2)")
board.Add(unconnected_3vo)
nets["3Vo_NC"] = unconnected_3vo


def add_footprint(name, ref, origin, value, pad_nets):
    item = pcb.FootprintLoad(str(PRETTY), name)
    if item is None:
        raise RuntimeError(name)
    item.SetFPID(pcb.LIB_ID("ShieldHub", name))
    item.SetReference(ref)
    item.SetValue(value)
    item.SetPosition(hole(*origin))
    board.Add(item)
    for pad in item.Pads():
        signal = pad_nets.get(pad.GetNumber())
        if signal:
            pad.SetNet(nets[signal])
    return item


ftp = add_footprint("FTP_8_Wires", "J1", (5, 2), "FTP 4 m", {
    "1": "SDA", "2": "SCL", "3": "GND", "4": "SD", "5": "SCK",
    "6": "WS", "7": "GND", "8": "3V3"})
sht = add_footprint("SHT45_LaskaKit", "U3", (20, 2), "SHT45", {
    "1": "SDA", "2": "SCL", "3": "GND", "4": "3V3"})
veml = add_footprint("VEML7700_Techfun", "U4", (18, 6), "VEML7700", {
    "1": "3V3", "2": "3Vo_NC", "3": "GND", "4": "SCL", "5": "SDA"})
mic = add_footprint("INMP441_Round", "U5", (10, 9), "INMP441", {
    "1": "GND", "2": "3V3", "3": "SD_MIC", "4": "GND",
    "5": "WS", "6": "SCK"})
resistor = add_footprint("R_Axial_P9_16", "R5", (11, 5), "47R", {
    "1": "SD", "2": "SD_MIC"})
# Pad 2 points down by default; turn it towards G8
resistor.SetOrientationDegrees(-56.31)
offset = resistor.FindPadByNumber("2").GetPosition() - hole(8, 7)
if max(abs(offset.x), abs(offset.y)) > mm(0.01):
    raise RuntimeError(f"R5 pad 2 is {pcb.ToMM(offset.x)}, {pcb.ToMM(offset.y)} mm off G8")
capacitor = add_footprint("C_Radial_P2_54", "C2", (3, 10), "10u", {
    "1": "3V3", "2": "GND"})
# Label positions placed by hand in pcbnew: (reference, value), None keeps the footprint default
for item, reference, value in (
        (capacitor, (109.5, 121.5), None),
        (ftp, None, (116.5, 102.0)),
        (sht, (160.5, 107.5), (160.5, 110.0)),
        (veml, (141.5, 110.0), (142.0, 112.5)),
        (mic, (126.5, 122.5), (127.5, 125.5))):
    if reference:
        item.Reference().SetPosition(point(*reference))
    if value:
        item.Value().SetPosition(point(*value))

(HERE / "fp-lib-table").write_text(
    '(fp_lib_table (lib (name "ShieldHub") (type "KiCad") '
    '(uri "${KIPRJMOD}/ShieldHub.pretty") (options "") (descr "Shield hub parts")))\n'
)


def line(start, end, layer, width=0.12):
    shape = pcb.PCB_SHAPE(board)
    shape.SetShape(pcb.S_SEGMENT)
    shape.SetStart(start)
    shape.SetEnd(end)
    shape.SetLayer(layer)
    shape.SetWidth(mm(width))
    board.Add(shape)


for a, b in [((BOARD_X, BOARD_Y), (BOARD_X + 70, BOARD_Y)),
             ((BOARD_X + 70, BOARD_Y), (BOARD_X + 70, BOARD_Y + 30)),
             ((BOARD_X + 70, BOARD_Y + 30), (BOARD_X, BOARD_Y + 30)),
             ((BOARD_X, BOARD_Y + 30), (BOARD_X, BOARD_Y))]:
    line(point(*a), point(*b), pcb.Edge_Cuts, 0.05)

occupied = {(5, 2), (5, 3), (5, 4), (5, 5), (5, 7), (5, 8), (5, 9), (5, 10),
            *((20, r) for r in range(2, 6)), *((18, r) for r in range(2, 7)),
            *((10, r) for r in range(7, 10)), *((7, r) for r in range(7, 10)),
            (11, 5), (8, 7), (3, 9), (3, 10)}
zip_tie = {(2, 3), (2, 7)}
for col in range(1, 25):
    for row in range(1, 11):
        if (col, row) in occupied or (col, row) in zip_tie:
            continue
        ring = pcb.PCB_SHAPE(board)
        ring.SetShape(pcb.S_CIRCLE)
        ring.SetCenter(hole(col, row))
        ring.SetEnd(point(HOLE_X + (col - 1) * PITCH + 0.5,
                          HOLE_Y + (row - 1) * PITCH))
        ring.SetLayer(pcb.Dwgs_User)
        ring.SetWidth(mm(0.08))
        board.Add(ring)

for col, row in zip_tie:
    ring = pcb.PCB_SHAPE(board)
    ring.SetShape(pcb.S_CIRCLE)
    ring.SetCenter(hole(col, row))
    ring.SetEnd(point(HOLE_X + (col - 1) * PITCH + 1.75,
                      HOLE_Y + (row - 1) * PITCH))
    ring.SetLayer(pcb.Dwgs_User)
    ring.SetWidth(mm(0.15))
    board.Add(ring)


def route(signal, *nodes):
    for a, b in zip(nodes, nodes[1:]):
        track = pcb.PCB_TRACK(board)
        track.SetStart(hole(*a))
        track.SetEnd(hole(*b))
        track.SetWidth(mm(0.45))
        track.SetLayer(pcb.B_Cu)
        track.SetNet(nets[signal])
        board.Add(track)


route("SDA", (5, 2), (20, 2))
route("SCL", (5, 3), (20, 3))
route("GND", (5, 4), (20, 4))
route("GND", (5, 4), (4, 4), (4, 9), (5, 9))
route("GND", (5, 9), (10, 9))
route("GND", (4, 9), (3, 9))
route("3V3", (3, 10), (19, 10), (19, 5), (20, 5))
route("3V3", (19, 6), (18, 6))
route("SD", (5, 5), (11, 5))
route("SD_MIC", (7, 7), (8, 7))
route("3V3", (7, 8), (8, 8))
route("SCK", (5, 7), (5, 6), (10, 6), (10, 7))
route("WS", (5, 8), (6, 8))
route("WS", (10, 8), (11, 8))


def top_link(signal, *coordinates):
    for a, b in zip(coordinates, coordinates[1:]):
        track = pcb.PCB_TRACK(board)
        track.SetStart(a)
        track.SetEnd(b)
        track.SetWidth(mm(0.45))
        track.SetLayer(pcb.F_Cu)
        track.SetNet(nets[signal])
        board.Add(track)


def wire_end(signal, col, row):
    """Perfboard hole taking a top wire end, soldered to the bottom run."""
    via = pcb.PCB_VIA(board)
    via.SetPosition(hole(col, row))
    via.SetWidth(mm(1.7))
    via.SetDrill(mm(0.9))
    via.SetNet(nets[signal])
    board.Add(via)


for signal, col, row in (("3V3", 8, 10), ("3V3", 8, 8), ("WS", 6, 8), ("WS", 11, 8)):
    wire_end(signal, col, row)
top_link("3V3", hole(8, 10), hole(8, 8))
top_link("WS", hole(6, 8), hole(6, 5.89), hole(11.31, 5.89), hole(11.31, 8), hole(11, 8))

def note(text, x, y, layer=pcb.Cmts_User, size=0.9):
    label = pcb.PCB_TEXT(board)
    label.SetText(text)
    label.SetPosition(point(x, y))
    label.SetLayer(layer)
    label.SetTextSize(point(size, size))
    label.SetTextThickness(mm(0.12))
    board.Add(label)


note("TOP VIEW / CABLE LEFT / SHT45 TONGUE RIGHT", 135, 97)
note("B.Cu tracks = hand-soldered tinned wire, not etched traces", 135, 133)
note("J1: orange SDA; white-green SCL; green GND; blue SD", 135, 136)
note("white-brown SCK; brown WS; white-blue GND; white-orange 3V3", 135, 139)
note("C2 + at J3; U5 port faces out", 135, 142)
note("F.Cu tracks = insulated top-side wires, ends soldered from the bottom", 135, 151)
note("Enlarge C2/G2 holes to 3.5 mm for cable tie", 135, 145)
note("U4 pin 2 (3Vo) unconnected", 135, 148)

pcb.SaveBoard(str(HERE / "ShieldHub.kicad_pcb"), board)
