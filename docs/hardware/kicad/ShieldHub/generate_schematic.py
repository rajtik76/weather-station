"""Build ShieldHub.kicad_sch and symbols/ShieldHub.kicad_sym (plain Python, no KiCad modules).

J1 drives +3V3 and GND, so ERC needs no PWR_FLAG. Net names match generate_board.py.
"""

from pathlib import Path
import uuid


HERE = Path(__file__).resolve().parent
ROOT = uuid.UUID("b76726f0-ed2b-5d4b-9823-60b8b42dfac9")
FONT = "(font (size 1.27 1.27))"


def uid(*parts):
    return str(uuid.uuid5(ROOT, "/".join(str(part) for part in parts)))


def num(value):
    return f"{round(value, 3):g}"


def xy(point):
    return f"(xy {num(point[0])} {num(point[1])})"


def rect(x1, y1, x2, y2, fill="background"):
    return (f"(rectangle (start {num(x1)} {num(y1)}) (end {num(x2)} {num(y2)}) "
            f"(stroke (width 0.254) (type default)) (fill (type {fill})))")


def poly(points, fill="none", width=0.254):
    pts = " ".join(xy(point) for point in points)
    return f"(polyline (pts {pts}) (stroke (width {width}) (type default)) (fill (type {fill})))"


# Symbols: name -> pins [(number, name, type, x, y, angle, length)], graphics, fields.
# Angle points from the connection point into the body.
MODULE_BODY = [rect(-7.62, 7.62, 7.62, -5.08)]
SYMBOLS = {
    "FTP_8_Wires": {
        "pins": [
            ("8", "3V3", "power_out", 5.08, 10.16, 180, 2.54),
            ("2", "SCL", "passive", 5.08, 7.62, 180, 2.54),
            ("1", "SDA", "passive", 5.08, 5.08, 180, 2.54),
            ("5", "SCK", "passive", 5.08, 2.54, 180, 2.54),
            ("6", "WS", "passive", 5.08, 0, 180, 2.54),
            ("4", "SD", "passive", 5.08, -2.54, 180, 2.54),
            ("3", "GND", "power_out", 5.08, -5.08, 180, 2.54),
            ("7", "GND", "passive", 5.08, -7.62, 180, 2.54),
        ],
        "graphics": [rect(-3.81, 12.7, 2.54, -10.16)],
        "ref": (-3.81, 13.97, "left"),
        "value": (-3.81, -11.43, "left"),
    },
    "SHT45_LaskaKit": {
        "pins": [
            ("4", "VCC", "passive", 0, 10.16, 270, 2.54),
            ("2", "SCL", "passive", -10.16, 2.54, 0, 2.54),
            ("1", "SDA", "passive", -10.16, 0, 0, 2.54),
            ("3", "GND", "passive", 0, -7.62, 90, 2.54),
        ],
        "graphics": MODULE_BODY,
        "ref": (8.89, 2.54, "left"),
        "value": (8.89, 0, "left"),
    },
    "VEML7700_Techfun": {
        "pins": [
            ("1", "VIN", "passive", 0, 10.16, 270, 2.54),
            ("2", "3Vo", "passive", 5.08, 10.16, 270, 2.54),
            ("4", "SCL", "passive", -10.16, 2.54, 0, 2.54),
            ("5", "SDA", "passive", -10.16, 0, 0, 2.54),
            ("3", "GND", "passive", 0, -7.62, 90, 2.54),
        ],
        "graphics": MODULE_BODY,
        "ref": (8.89, 2.54, "left"),
        "value": (8.89, 0, "left"),
    },
    "INMP441_Round": {
        "pins": [
            ("2", "VDD", "passive", 0, 10.16, 270, 2.54),
            ("6", "SCK", "passive", -10.16, 2.54, 0, 2.54),
            ("5", "WS", "passive", -10.16, 0, 0, 2.54),
            ("3", "SD", "passive", -10.16, -2.54, 0, 2.54),
            ("1", "GND", "passive", -2.54, -7.62, 90, 2.54),
            ("4", "L/R", "passive", 2.54, -7.62, 90, 2.54),
        ],
        "graphics": MODULE_BODY,
        "ref": (8.89, 2.54, "left"),
        "value": (8.89, 0, "left"),
    },
    "R_Axial_P10_16": {
        "pins": [
            ("1", "1", "passive", 0, 3.81, 270, 1.27),
            ("2", "2", "passive", 0, -3.81, 90, 1.27),
        ],
        "graphics": [rect(-1.016, 2.54, 1.016, -2.54, fill="none")],
        "ref": (2.54, 1.27, "left"),
        "value": (2.54, -1.27, "left"),
        "hide_pins": True,
    },
    "C_Radial_P2_54": {
        "pins": [
            ("1", "+", "passive", 0, 3.81, 270, 3.048),
            ("2", "-", "passive", 0, -3.81, 90, 3.048),
        ],
        "graphics": [
            rect(-2.286, 0.762, 2.286, 0.254, fill="none"),
            rect(-2.286, -0.254, 2.286, -0.762, fill="outline"),
            poly([(-1.778, 2.286), (-0.762, 2.286)], width=0),
            poly([(-1.27, 2.794), (-1.27, 1.778)], width=0),
        ],
        "ref": (3.81, 1.27, "left"),
        "value": (3.81, -1.27, "left"),
        "hide_pins": True,
    },
    "+3V3": {
        "pins": [("1", "+3V3", "power_in", 0, 0, 90, 0)],
        "graphics": [
            poly([(-0.762, 1.27), (0, 2.54), (0.762, 1.27)], width=0),
            poly([(0, 0), (0, 2.54)], width=0),
        ],
        "ref": (0, -3.81, None),
        "value": (0, 3.556, None),
        "power": True,
    },
    "GND": {
        "pins": [("1", "GND", "power_in", 0, 0, 270, 0)],
        "graphics": [poly([(0, 0), (0, -1.27), (1.27, -1.27), (0, -2.54), (-1.27, -1.27), (0, -1.27)], width=0)],
        "ref": (0, -6.35, None),
        "value": (0, -3.81, None),
        "power": True,
    },
}
FOOTPRINTED = {"FTP_8_Wires", "SHT45_LaskaKit", "VEML7700_Techfun", "INMP441_Round", "R_Axial_P10_16", "C_Radial_P2_54"}
# R5 lies diagonally, spanning less than its symbol name says
FOOTPRINT_NAMES = {"R_Axial_P10_16": "R_Axial_P9_16"}


def footprint_of(name):
    return f"ShieldHub:{FOOTPRINT_NAMES.get(name, name)}" if name in FOOTPRINTED else ""


def effects(justify=None, hide=False):
    parts = [FONT]
    if justify:
        parts.append(f"(justify {justify})")
    if hide:
        parts.append("(hide yes)")
    return f"(effects {' '.join(parts)})"


def lib_symbol(name, prefix=""):
    spec = SYMBOLS[name]
    power = spec.get("power", False)
    hide_pins = spec.get("hide_pins", False) or power
    ref_x, ref_y, ref_j = spec["ref"]
    val_x, val_y, val_j = spec["value"]
    reference = "#PWR" if power else ("J" if name == "FTP_8_Wires" else "R" if name.startswith("R_") else "C" if name.startswith("C_") else "U")
    footprint = footprint_of(name)
    head = [f'(symbol "{prefix}{name}"']
    if power:
        head.append("  (power)")
    if hide_pins:
        head.append("  (pin_numbers (hide yes)) (pin_names (offset 0) (hide yes))")
    else:
        head.append("  (pin_names (offset 0.508))")
    head.append(f"  (exclude_from_sim no) (in_bom {'no' if power else 'yes'}) (on_board {'no' if power else 'yes'})")
    head.append(f'  (property "Reference" "{reference}" (at {num(ref_x)} {num(ref_y)} 0) {effects(ref_j, hide=power)})')
    head.append(f'  (property "Value" "{name}" (at {num(val_x)} {num(val_y)} 0) {effects(val_j)})')
    head.append(f'  (property "Footprint" "{footprint}" (at 0 0 0) {effects(hide=True)})')
    head.append(f'  (property "Datasheet" "" (at 0 0 0) {effects(hide=True)})')
    head.append(f'  (symbol "{name}_0_1" {" ".join(spec["graphics"])})')
    pins = []
    for number, pin_name, kind, x, y, angle, length in spec["pins"]:
        hidden = " (hide yes)" if power else ""
        pins.append(f'    (pin {kind} line (at {num(x)} {num(y)} {angle}) (length {num(length)}){hidden} '
                    f'(name "{pin_name}" (effects {FONT})) (number "{number}" (effects {FONT})))')
    return "\n".join(head + [f'  (symbol "{name}_1_1"'] + pins + ["  )", ")"])


def place(x, y, rot, lx, ly):
    if rot == 90:
        lx, ly = -ly, lx
    return x + lx, y - ly


class Sheet:
    def __init__(self):
        self.items = []
        self.symbols = {}
        self.power_count = 0

    def symbol(self, name, ref, value, x, y, rot=0):
        spec = SYMBOLS[name]
        power = spec.get("power", False)
        if power:
            self.power_count += 1
            ref = f"#PWR{self.power_count:02d}"
        self.symbols[ref] = (name, x, y, rot)
        ref_x, ref_y, ref_j = spec["ref"]
        val_x, val_y, val_j = spec["value"]
        if rot == 90:
            ref_pos, val_pos = (x, y + 2.54), (x, y + 5.08)
            ref_j = val_j = None
        else:
            ref_pos, val_pos = place(x, y, 0, ref_x, ref_y), place(x, y, 0, val_x, val_y)
        footprint = footprint_of(name)
        lines = [f'(symbol (lib_id "ShieldHub:{name}") (at {num(x)} {num(y)} {rot}) (unit 1) (exclude_from_sim no) '
                 f'(in_bom {"no" if power else "yes"}) (on_board {"no" if power else "yes"}) (dnp no) (uuid "{uid("symbol", ref)}")',
                 f'  (property "Reference" "{ref}" (at {num(ref_pos[0])} {num(ref_pos[1])} {rot}) {effects(ref_j, hide=power)})',
                 f'  (property "Value" "{value}" (at {num(val_pos[0])} {num(val_pos[1])} {rot}) {effects(val_j)})',
                 f'  (property "Footprint" "{footprint}" (at {num(x)} {num(y)} 0) {effects(hide=True)})',
                 f'  (property "Datasheet" "" (at {num(x)} {num(y)} 0) {effects(hide=True)})']
        for number, *_ in spec["pins"]:
            lines.append(f'  (pin "{number}" (uuid "{uid("pin", ref, number)}"))')
        lines.append(f'  (instances (project "ShieldHub" (path "/{ROOT}" (reference "{ref}") (unit 1))))')
        lines.append(")")
        self.items.append("\n".join(lines))
        return ref

    def pin(self, ref, pin_name):
        name, x, y, rot = self.symbols[ref]
        for number, label, _, px, py, _, _ in SYMBOLS[name]["pins"]:
            if pin_name in (number, label):
                return place(x, y, rot, px, py)
        raise KeyError(f"{ref} has no pin {pin_name}")

    def wire(self, *points):
        for start, end in zip(points, points[1:]):
            self.items.append(f'(wire (pts {xy(start)} {xy(end)}) (stroke (width 0) (type default)) '
                              f'(uuid "{uid("wire", start, end)}"))')

    def junction(self, point):
        self.items.append(f'(junction (at {num(point[0])} {num(point[1])}) (diameter 0) (color 0 0 0 0) '
                          f'(uuid "{uid("junction", point)}"))')

    def no_connect(self, point):
        self.items.append(f'(no_connect (at {num(point[0])} {num(point[1])}) (uuid "{uid("nc", point)}"))')

    def label(self, name, x, y):
        self.items.append(f'(label "{name}" (at {num(x)} {num(y)} 0) '
                          f'(effects {FONT} (justify left bottom)) (uuid "{uid("label", name)}"))')

    def text(self, content, x, y):
        self.items.append(f'(text "{content}" (exclude_from_sim no) (at {num(x)} {num(y)} 0) '
                          f'(effects {FONT} (justify left)) (uuid "{uid("text", content)}"))')


def draw(sheet):
    sheet.symbol("FTP_8_Wires", "J1", "FTP 4 m", 25.4, 99.06)
    j1 = lambda name: sheet.pin("J1", name)

    x_power = j1("3V3")[0] + 2.54
    sheet.wire(j1("3V3"), (x_power, j1("3V3")[1]), (x_power, 86.36))
    sheet.symbol("+3V3", None, "+3V3", x_power, 86.36)
    gnd_a, gnd_b = j1("3"), j1("7")
    sheet.wire(gnd_a, (x_power, gnd_a[1]), (x_power, 109.22))
    sheet.wire(gnd_b, (x_power, gnd_b[1]))
    sheet.junction((x_power, gnd_b[1]))
    sheet.symbol("GND", None, "GND", x_power, 109.22)

    sheet.symbol("SHT45_LaskaKit", "U3", "SHT45", 71.12, 45.72)
    sheet.symbol("VEML7700_Techfun", "U4", "VEML7700", 111.76, 66.04)
    scl_bus, sda_bus = sheet.pin("U4", "SCL")[1], sheet.pin("U4", "SDA")[1]
    sheet.wire(j1("SCL"), (38.1, j1("SCL")[1]), (38.1, scl_bus), sheet.pin("U4", "SCL"))
    sheet.wire(j1("SDA"), (40.64, j1("SDA")[1]), (40.64, sda_bus), sheet.pin("U4", "SDA"))
    u3_scl, u3_sda = sheet.pin("U3", "SCL"), sheet.pin("U3", "SDA")
    sheet.wire(u3_scl, (u3_scl[0] - 5.08, u3_scl[1]), (u3_scl[0] - 5.08, scl_bus))
    sheet.junction((u3_scl[0] - 5.08, scl_bus))
    sheet.wire(u3_sda, (u3_sda[0] - 2.54, u3_sda[1]), (u3_sda[0] - 2.54, sda_bus))
    sheet.junction((u3_sda[0] - 2.54, sda_bus))
    for ref, supply in (("U3", "VCC"), ("U4", "VIN")):
        sheet.symbol("+3V3", None, "+3V3", *sheet.pin(ref, supply))
        sheet.symbol("GND", None, "GND", *sheet.pin(ref, "GND"))
    sheet.no_connect(sheet.pin("U4", "3Vo"))

    sheet.symbol("INMP441_Round", "U5", "INMP441", 111.76, 99.06)
    sheet.wire(j1("SCK"), sheet.pin("U5", "SCK"))
    sheet.wire(j1("WS"), sheet.pin("U5", "WS"))
    sheet.symbol("R_Axial_P10_16", "R5", "47R", 76.2, j1("SD")[1], rot=90)
    left, right = sorted((sheet.pin("R5", "1"), sheet.pin("R5", "2")))
    sheet.wire(j1("SD"), left)
    sheet.wire(right, sheet.pin("U5", "SD"))
    sheet.symbol("+3V3", None, "+3V3", *sheet.pin("U5", "VDD"))
    mic_gnd, mic_lr = sheet.pin("U5", "GND"), sheet.pin("U5", "L/R")
    tee = (111.76, mic_gnd[1] + 2.54)
    sheet.wire(mic_gnd, (mic_gnd[0], tee[1]), tee)
    sheet.wire(mic_lr, (mic_lr[0], tee[1]), tee)
    sheet.wire(tee, (tee[0], tee[1] + 2.54))
    sheet.junction(tee)
    sheet.symbol("GND", None, "GND", tee[0], tee[1] + 2.54)

    sheet.symbol("C_Radial_P2_54", "C2", "10u", 50.8, 124.46)
    sheet.symbol("+3V3", None, "+3V3", *sheet.pin("C2", "1"))
    sheet.symbol("GND", None, "GND", *sheet.pin("C2", "2"))

    for name, x, y in (("SCL", 81.28, scl_bus), ("SDA", 88.9, sda_bus), ("SCK", 50.8, j1("SCK")[1]),
                       ("WS", 50.8, j1("WS")[1]), ("SD", 50.8, j1("SD")[1]), ("SD_MIC", 86.36, j1("SD")[1])):
        sheet.label(name, x, y)

    sheet.text("J1: 4 m FTP cable to the ESP32 board. Shield and drain cut back at this end.", 25.4, 144.78)
    sheet.text("I2C pull-ups sit on the ESP32 board and on the modules.", 25.4, 149.86)
    sheet.text("C1 100 nF omitted: the breakout modules carry local decoupling.", 25.4, 154.94)


def main():
    sheet = Sheet()
    draw(sheet)
    lib = "\n".join(lib_symbol(name, "ShieldHub:") for name in SYMBOLS)
    content = "\n".join([
        '(kicad_sch (version 20250114) (generator "eeschema") (generator_version "9.0")',
        f'  (uuid "{ROOT}") (paper "A4")',
        '  (title_block (title "Shield hub on 30 x 70 mm perfboard") (date "2026-09-29") (rev "2"))',
        "  (lib_symbols",
        lib,
        "  )",
        *sheet.items,
        '  (sheet_instances (path "/" (page "1")))',
        ")",
    ])
    (HERE / "ShieldHub.kicad_sch").write_text(content + "\n")
    library = "\n".join(lib_symbol(name) for name in SYMBOLS)
    (HERE / "symbols" / "ShieldHub.kicad_sym").write_text(
        '(kicad_symbol_lib (version 20241209) (generator "kicad_symbol_editor") (generator_version "9.0")\n'
        + library + "\n)\n")


if __name__ == "__main__":
    main()
