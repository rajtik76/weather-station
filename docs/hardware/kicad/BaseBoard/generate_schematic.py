"""Build BaseBoard.kicad_sch and symbols/BaseBoard.kicad_sym (plain Python, no KiCad modules).

J2 drives +5V and GND, the DevKit drives +3V3, so ERC needs no PWR_FLAG. Net names match generate_board.py.
"""

from pathlib import Path
import uuid


HERE = Path(__file__).resolve().parent
ROOT = uuid.UUID("3f0c6d52-8a41-5c1e-9d37-5b2f0e4a7c19")
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
# Angle points from the connection point into the body. Only the DevKit pins in use are drawn.
SYMBOLS = {
    "DevKit_V1_BaseBoard": {
        "pins": [
            ("1", "VIN", "power_in", -15.24, 15.24, 0, 2.54),
            ("2", "GND", "power_in", -15.24, 12.7, 0, 2.54),
            ("16", "3V3", "power_out", -15.24, 2.54, 0, 2.54),
            ("17", "GND", "power_in", -15.24, -12.7, 0, 2.54),
            ("29", "D22/SCL", "bidirectional", 15.24, 12.7, 180, 2.54),
            ("26", "D21/SDA", "bidirectional", 15.24, 7.62, 180, 2.54),
            ("7", "D26/SCK", "output", 15.24, -2.54, 180, 2.54),
            ("8", "D25/WS", "output", 15.24, -7.62, 180, 2.54),
            ("9", "D33/SD", "input", 15.24, -12.7, 180, 2.54),
        ],
        "graphics": [rect(-12.7, 17.78, 12.7, -15.24)],
        "ref": (0, 20.32, None),
        "value": (0, -17.78, None),
        "prefix": "U",
        "footprint": "DOIT_ESP32_DevKit_V1_Socketed",
    },
    "BMP280_Socketed": {
        "pins": [
            ("1", "VCC", "passive", -15.24, 5.08, 0, 2.54),
            ("2", "GND", "passive", -15.24, -5.08, 0, 2.54),
            ("3", "SCL", "passive", -3.81, 12.7, 270, 2.54),
            ("4", "SDA", "passive", 3.81, -12.7, 90, 2.54),
            ("5", "CSB", "passive", 15.24, 5.08, 180, 2.54),
            ("6", "SDO", "passive", 15.24, -5.08, 180, 2.54),
        ],
        "graphics": [rect(-12.7, 10.16, 12.7, -10.16)],
        "ref": (0, 2.54, None),
        "value": (0, -2.54, None),
        "prefix": "U",
        "footprint": "BMP280_Module_Socketed",
    },
    "FTP_9_Pins": {
        "pins": [
            ("5", "3V3", "passive", -5.08, 10.16, 0, 2.54),
            ("9", "SCL", "passive", -5.08, 7.62, 0, 2.54),
            ("8", "SDA", "passive", -5.08, 5.08, 0, 2.54),
            ("2", "SCK", "passive", -5.08, 2.54, 0, 2.54),
            ("3", "WS", "passive", -5.08, 0, 0, 2.54),
            ("4", "SD", "passive", -5.08, -2.54, 0, 2.54),
            ("1", "GND", "passive", -5.08, -5.08, 0, 2.54),
            ("6", "GND", "passive", -5.08, -7.62, 0, 2.54),
            ("7", "DRAIN", "passive", -5.08, -10.16, 0, 2.54),
        ],
        "graphics": [rect(-2.54, 12.7, 5.08, -12.7)],
        "ref": (-2.54, 13.97, "left"),
        "value": (-2.54, -13.97, "left"),
        "prefix": "J",
        "footprint": "PinHeader_1x09",
    },
    "Power_In_5V": {
        "pins": [
            ("1", "5V", "power_out", 5.08, 1.27, 180, 2.54),
            ("2", "GND", "power_out", 5.08, -1.27, 180, 2.54),
        ],
        "graphics": [rect(-2.54, 3.81, 2.54, -3.81)],
        "ref": (-2.54, 5.08, "left"),
        "value": (-2.54, -5.08, "left"),
        "prefix": "J",
        "footprint": "PinHeader_1x02",
    },
    "R": {
        "pins": [
            ("1", "1", "passive", 0, 3.81, 270, 1.27),
            ("2", "2", "passive", 0, -3.81, 90, 1.27),
        ],
        "graphics": [rect(-1.016, 2.54, 1.016, -2.54, fill="none")],
        "ref": (2.54, 1.27, "left"),
        "value": (2.54, -1.27, "left"),
        "hide_pins": True,
        "prefix": "R",
    },
    "C": {
        "pins": [
            ("1", "1", "passive", 0, 3.81, 270, 3.302),
            ("2", "2", "passive", 0, -3.81, 90, 3.302),
        ],
        "graphics": [poly([(-2.032, 0.508), (2.032, 0.508)], width=0.508),
                     poly([(-2.032, -0.508), (2.032, -0.508)], width=0.508)],
        "ref": (3.81, 1.27, "left"),
        "value": (3.81, -1.27, "left"),
        "hide_pins": True,
        "prefix": "C",
        "footprint": "C_Disc_P2.54",
    },
    "C_Polarized": {
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
        "prefix": "C",
        "footprint": "CP_Radial_D5.0mm_P2.54",
    },
    "+5V": {
        "pins": [("1", "+5V", "power_in", 0, 0, 90, 0)],
        "graphics": [
            poly([(-0.762, 1.27), (0, 2.54), (0.762, 1.27)], width=0),
            poly([(0, 0), (0, 2.54)], width=0),
        ],
        "ref": (0, -3.81, None),
        "value": (0, 3.556, None),
        "power": True,
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
    reference = "#PWR" if power else spec["prefix"]
    footprint = f"BaseBoard:{spec['footprint']}" if spec.get("footprint") else ""
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

    def symbol(self, name, ref, value, x, y, rot=0, footprint=None):
        spec = SYMBOLS[name]
        power = spec.get("power", False)
        if power:
            self.power_count += 1
            ref = f"#PWR{self.power_count:02d}"
        self.symbols[ref] = (name, x, y, rot)
        ref_x, ref_y, ref_j = spec["ref"]
        val_x, val_y, val_j = spec["value"]
        if rot == 90:
            ref_pos, val_pos = (x, y - 2.54), (x, y + 2.54)
            ref_j = val_j = None
        else:
            ref_pos, val_pos = place(x, y, 0, ref_x, ref_y), place(x, y, 0, val_x, val_y)
        footprint = footprint or spec.get("footprint")
        footprint = f"BaseBoard:{footprint}" if footprint else ""
        lines = [f'(symbol (lib_id "BaseBoard:{name}") (at {num(x)} {num(y)} {rot}) (unit 1) (exclude_from_sim no) '
                 f'(in_bom {"no" if power else "yes"}) (on_board {"no" if power else "yes"}) (dnp no) (uuid "{uid("symbol", ref)}")',
                 f'  (property "Reference" "{ref}" (at {num(ref_pos[0])} {num(ref_pos[1])} {rot}) {effects(ref_j, hide=power)})',
                 f'  (property "Value" "{value}" (at {num(val_pos[0])} {num(val_pos[1])} {rot}) {effects(val_j)})',
                 f'  (property "Footprint" "{footprint}" (at {num(x)} {num(y)} 0) {effects(hide=True)})',
                 f'  (property "Datasheet" "" (at {num(x)} {num(y)} 0) {effects(hide=True)})']
        for number, *_ in spec["pins"]:
            lines.append(f'  (pin "{number}" (uuid "{uid("pin", ref, number)}"))')
        lines.append(f'  (instances (project "BaseBoard" (path "/{ROOT}" (reference "{ref}") (unit 1))))')
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

    def label(self, name, x, y):
        self.items.append(f'(label "{name}" (at {num(x)} {num(y)} 0) '
                          f'(effects {FONT} (justify left bottom)) (uuid "{uid("label", name, x, y)}"))')

    def text(self, content, x, y):
        self.items.append(f'(text "{content}" (exclude_from_sim no) (at {num(x)} {num(y)} 0) '
                          f'(effects {FONT} (justify left)) (uuid "{uid("text", content)}"))')


def draw(sheet):
    sheet.symbol("DevKit_V1_BaseBoard", "U1", "ESP32 DevKit V1", 63.5, 101.6)
    u1 = lambda name: sheet.pin("U1", name)

    # J2: 5 V straight onto VIN and the top GND pin
    sheet.symbol("Power_In_5V", "J2", "5V in", 33.02, 87.63)
    vin, gnd_top = u1("VIN"), u1("2")
    sheet.wire(sheet.pin("J2", "5V"), vin)
    sheet.wire(sheet.pin("J2", "GND"), gnd_top)
    sheet.junction((43.18, vin[1]))
    sheet.symbol("+5V", None, "+5V", 43.18, vin[1])
    sheet.junction((45.72, gnd_top[1]))
    sheet.symbol("GND", None, "GND", 45.72, gnd_top[1])
    sheet.wire(u1("3V3"), (43.18, u1("3V3")[1]))
    sheet.symbol("+3V3", None, "+3V3", 43.18, u1("3V3")[1])
    sheet.wire(u1("17"), (45.72, u1("17")[1]))
    sheet.symbol("GND", None, "GND", 45.72, u1("17")[1])

    sheet.symbol("FTP_9_Pins", "J1", "FTP cable", 200.66, 99.06)
    j1 = lambda name: sheet.pin("J1", name)

    scl_bus, scl_drop = 58.42, 190.5
    sheet.wire(u1("D22/SCL"), (86.36, u1("D22/SCL")[1]), (86.36, scl_bus), (scl_drop, scl_bus),
               (scl_drop, j1("SCL")[1]), j1("SCL"))
    sda_bus = u1("D21/SDA")[1]
    sheet.wire(u1("D21/SDA"), j1("SDA"))

    sheet.symbol("R", "R2", "4k7", 91.44, 86.36, footprint="R_Socketed_1x02")
    sheet.symbol("+3V3", None, "+3V3", *sheet.pin("R2", "1"))
    sheet.wire(sheet.pin("R2", "2"), (91.44, sda_bus))
    sheet.junction((91.44, sda_bus))

    sheet.symbol("BMP280_Socketed", "U2", "BMP280 0x76", 132.08, 76.2)
    u2 = lambda name: sheet.pin("U2", name)
    sheet.wire(u2("SCL"), (u2("SCL")[0], scl_bus))
    sheet.junction((u2("SCL")[0], scl_bus))
    sheet.wire(u2("SDA"), (u2("SDA")[0], sda_bus))
    sheet.junction((u2("SDA")[0], sda_bus))

    sheet.wire(u2("VCC"), (114.3, u2("VCC")[1]))
    sheet.symbol("+3V3", None, "+3V3", 114.3, u2("VCC")[1])
    sheet.symbol("R", "R1", "4k7", 113.03, 52.07, footprint="R_Socketed_1x02")
    sheet.symbol("+3V3", None, "+3V3", *sheet.pin("R1", "1"))
    sheet.wire(sheet.pin("R1", "2"), (113.03, scl_bus))
    sheet.junction((113.03, scl_bus))

    sheet.wire(u2("GND"), (114.3, u2("GND")[1]))
    sheet.symbol("GND", None, "GND", 114.3, u2("GND")[1])
    sheet.wire(u2("CSB"), (149.86, u2("CSB")[1]))
    sheet.symbol("+3V3", None, "+3V3", 149.86, u2("CSB")[1])
    sheet.wire(u2("SDO"), (149.86, u2("SDO")[1]))
    sheet.symbol("GND", None, "GND", 149.86, u2("SDO")[1])

    sheet.symbol("R", "R3", "47R", 99.06, u1("D26/SCK")[1], rot=90, footprint="R_Axial_P10.16")
    sheet.symbol("R", "R4", "47R", 119.38, u1("D25/WS")[1], rot=90, footprint="R_Axial_P10.16")
    for pin, signal, resistor, step_x in (("D26/SCK", "SCK", "R3", 180.34), ("D25/WS", "WS", "R4", 182.88),
                                          ("D33/SD", "SD", None, 185.42)):
        start, end = u1(pin), j1(signal)
        if resistor:
            sheet.wire(start, sheet.pin(resistor, "1"))
            start = sheet.pin(resistor, "2")
        sheet.wire(start, (step_x, start[1]), (step_x, end[1]), end)

    # Cable drain grounded at this end only
    sheet.wire(j1("3V3"), (193.04, j1("3V3")[1]))
    sheet.symbol("+3V3", None, "+3V3", 193.04, j1("3V3")[1])
    gnd_a, gnd_b, drain = j1("1"), j1("6"), j1("DRAIN")
    sheet.wire(gnd_a, (193.04, gnd_a[1]), (193.04, drain[1]), drain)
    sheet.wire(gnd_b, (193.04, gnd_b[1]))
    sheet.junction((193.04, gnd_b[1]))
    sheet.junction((193.04, drain[1]))
    sheet.symbol("GND", None, "GND", 193.04, drain[1])

    for ref, name, value, x in (("C1", "C", "100n", 78.74), ("C2", "C_Polarized", "10u", 93.98)):
        sheet.symbol(name, ref, value, x, 132.08)
        sheet.symbol("+3V3", None, "+3V3", *sheet.pin(ref, "1"))
        sheet.symbol("GND", None, "GND", *sheet.pin(ref, "2"))

    for name, x, y in (("SCL", 88.9, scl_bus), ("SDA", 152.4, sda_bus),
                       ("SCK", 81.28, u1("D26/SCK")[1]), ("SCK_CABLE", 104.14, u1("D26/SCK")[1]),
                       ("WS", 81.28, u1("D25/WS")[1]), ("WS_CABLE", 124.46, u1("D25/WS")[1]),
                       ("SD", 81.28, u1("D33/SD")[1])):
        sheet.label(name, x, y)

    notes = [
        "Board: 60 x 80 mm perfboard, 22 x 27 holes; layout in BaseBoard.kicad_pcb.",
        "U1 sits in two 1x15 female headers. Its GND pins 2 and 17 meet on the DevKit.",
        "U2 plugs into a 1x6 female header. R1 and R2 plug into one 1x4 female header.",
        "Runs on the board are 0R links and solder bridges; they are wiring, not parts, and stay off this sheet.",
        "J1: 4 m FTP cable to the shield hub. Pin order on the board: GND SCK WS SD 3V3 GND drain SDA SCL.",
        "Drain grounded here only; the shield hub end is cut back.",
    ]
    for index, note in enumerate(notes):
        sheet.text(note, 25.4, 152.4 + index * 5.08)


def main():
    sheet = Sheet()
    draw(sheet)
    lib = "\n".join(lib_symbol(name, "BaseBoard:") for name in SYMBOLS)
    content = "\n".join([
        '(kicad_sch (version 20250114) (generator "eeschema") (generator_version "9.0")',
        f'  (uuid "{ROOT}") (paper "A4")',
        '  (title_block (title "ESP32 base board on 60 x 80 mm perfboard") (date "2026-09-30") (rev "1"))',
        "  (lib_symbols",
        lib,
        "  )",
        *sheet.items,
        '  (sheet_instances (path "/" (page "1")))',
        ")",
    ])
    (HERE / "BaseBoard.kicad_sch").write_text(content + "\n")
    library = "\n".join(lib_symbol(name) for name in SYMBOLS)
    (HERE / "symbols").mkdir(exist_ok=True)
    (HERE / "symbols" / "BaseBoard.kicad_sym").write_text(
        '(kicad_symbol_lib (version 20241209) (generator "kicad_symbol_editor") (generator_version "9.0")\n'
        + library + "\n)\n")
    (HERE / "sym-lib-table").write_text(
        '(sym_lib_table (lib (name "BaseBoard") (type "KiCad") '
        '(uri "${KIPRJMOD}/symbols/BaseBoard.kicad_sym") (options "") (descr "Base board parts")))\n')


if __name__ == "__main__":
    main()
