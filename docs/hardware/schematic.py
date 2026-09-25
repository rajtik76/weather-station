"""Shared schemdraw helpers for the station schematics: IC boxes, pin lookup, power stubs, the I2C bus."""

import schemdraw
import schemdraw.elements as elm

# Native SVG backend: text stays text and the output is the same on every run.
# Build a Drawing without `with`: in that mode save() draws every element a second time.
schemdraw.use('svg')

PIN = 1.2


def key(name: str) -> str:
    return 'p_' + name.split(' ')[0].replace('/', '')


def chip(label: str, size: tuple[float, float], left=(), right=(), top=(), bottom=()) -> elm.Ic:
    """IC box; left/right pins listed top to bottom, top/bottom pins left to right, None leaves a gap.

    theta(0) pins the orientation: an element otherwise follows the drawing's last direction.
    Top pin names are left blank here and drawn by top_pin_labels(): schemdraw sets them on the edge.
    """
    pins = []
    for side, names in (('left', left), ('right', right), ('top', top), ('bottom', bottom)):
        count = len(names)
        for i, name in enumerate(names):
            if name is None:
                continue
            slot = count - i if side in ('left', 'right') else i + 1
            shown = '' if side == 'top' else name
            pins.append(elm.IcPin(name=shown, side=side, slot=f'{slot}/{count}', anchorname=key(name)))
    ic = elm.Ic(pins=pins, pinspacing=PIN, size=size, label=label, lofst=0.2, lsize=11).theta(0)
    ic.top_names = [name for name in top if name]
    return ic


def top_pin_labels(d: schemdraw.Drawing, ic: elm.Ic) -> None:
    for name in ic.top_names:
        x, y = pin(ic, name)
        d += elm.Label().theta(0).at((x - 0.1, y - 0.955)).label(name, 'center', halign='center', valign='top', fontsize=11)


def pin(element: elm.Element, name: str) -> tuple[float, float]:
    x, y = element.absanchors[key(name)]
    return x, y


def supply(d: schemdraw.Drawing, at: tuple[float, float], direction: str = 'left') -> None:
    d += getattr(elm.Line().at(at), direction)(0.8)
    d += elm.Vdd().theta(0).label('3V3')


def ground(d: schemdraw.Drawing, at: tuple[float, float], direction: str = 'left') -> None:
    d += getattr(elm.Line().at(at), direction)(0.8)
    d += elm.Ground().theta(0)


def net_label(d: schemdraw.Drawing, at: tuple[float, float], text: str, loc: str = 'top') -> None:
    d += elm.Label().theta(0).at(at).label(text, loc)


def side_label(d: schemdraw.Drawing, element: elm.Element, text: str) -> None:
    """Reference and value beside a two-terminal part: right of a vertical one, above a horizontal one.

    Placed by hand, since an element's own label turns with the direction it was drawn in.
    """
    (x0, y0), (x1, y1) = element.absanchors['start'], element.absanchors['end']
    if abs(x1 - x0) < abs(y1 - y0):
        d += elm.Label().theta(0).at(((x0 + x1) / 2 + 0.55, (y0 + y1) / 2)).label(text, 'center', halign='left', valign='center')
    else:
        d += elm.Label().theta(0).at(((x0 + x1) / 2, (y0 + y1) / 2 + 0.4)).label(text, 'center', halign='center', valign='bottom')


def i2c_bus(d: schemdraw.Drawing, sda_from: tuple[float, float], scl_from: tuple[float, float],
            devices: list[tuple[elm.Ic, float]], pullup_x: float | None = None,
            pullup_refs: tuple[str, str] = ('R1', 'R2')) -> None:
    """SCL rail on top, SDA rail below, devices between them (SCL pin on top, SDA pin at the bottom).

    scl_from sits above sda_from; SCL rises just right of it, so no two wires cross.
    devices: (chip, x of its SDA pin). pullup_x puts 4k7 pull-ups on both rails there.
    """
    y_sda = sda_from[1]
    for device, x in devices:
        d += device.anchor(key('SDA')).at((x, y_sda + 1.4))
        top_pin_labels(d, device)
    y_top = max(pin(device, 'SCL')[1] for device, _ in devices) + 1.2
    x_end = max(x for _, x in devices) + 2.4

    d += elm.Line().at(sda_from).to((x_end, y_sda))
    net_label(d, (x_end, y_sda), 'SDA', 'right')
    riser = scl_from[0] + 1.0
    d += elm.Line().at(scl_from).to((riser, scl_from[1]))
    d += elm.Line().to((riser, y_top))
    d += elm.Line().to((x_end, y_top))
    net_label(d, (x_end, y_top), 'SCL', 'right')

    for device, _ in devices:
        x, y = pin(device, 'SDA')
        d += elm.Line().at((x, y)).to((x, y_sda))
        d += elm.Dot()
        x, y = pin(device, 'SCL')
        d += elm.Line().at((x, y)).to((x, y_top))
        d += elm.Dot()

    if pullup_x is not None:
        for x, y, ref in ((pullup_x, y_sda, pullup_refs[0]), (pullup_x + 2.2, y_top, pullup_refs[1])):
            d += elm.Dot().at((x, y))
            resistor = elm.Resistor().at((x, y)).up(2.2)
            d += resistor
            d += elm.Vdd().theta(0).label('3V3')
            side_label(d, resistor, f'{ref}\n4k7')


def mic_power(d: schemdraw.Drawing, mic: elm.Ic) -> None:
    """VDD to 3V3; L/R joins GND on the way down, so the INMP441 talks in the left slot."""
    supply(d, pin(mic, 'VDD'), 'right')
    (x, y_lr), (_, y_gnd) = pin(mic, 'L/R'), pin(mic, 'GND')
    d += elm.Line().at((x, y_lr)).right(0.8)
    d += elm.Line().down(y_lr - y_gnd)
    d += elm.Dot()
    d += elm.Line().at((x, y_gnd)).right(0.8)
    d += elm.Ground().theta(0)


def decoupling(d: schemdraw.Drawing, x: float, y: float) -> None:
    """100n and 10µ from 3V3 to GND, top ends at (x, y); values sit beside each capacitor."""
    for cx, text, polar in ((x, 'C1\n100n', False), (x + 2.4, 'C2\n10µ', True)):
        d += elm.Vdd().theta(0).at((cx, y)).label('3V3')
        cap = elm.Capacitor(polar=polar).at((cx, y)).down(1.8)
        d += cap
        d += elm.Ground().theta(0)
        side_label(d, cap, text)
