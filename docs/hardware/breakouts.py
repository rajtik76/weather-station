"""Footprints of the breakout modules in the drawer, measured once so board files only place them.

Geometry is in mm in the module's own frame, seen from the component side: pin 1 at
the origin, x along the header, y away from it (towards the body). place() puts pin 1
into a hole and turns the module so its body points 'up' (towards row 1), 'right',
'down' or 'left' on the board.
"""

from __future__ import annotations

from dataclasses import dataclass, field

from layout import P, Hole, Module

TURNS = {'up': (1, 0), 'left': (0, 1), 'down': (-1, 0), 'right': (0, -1)}


def turn(x: float, y: float, facing: str) -> tuple[float, float]:
    """Module frame to board mm: dx along the columns, dy down the rows."""
    c, s = TURNS[facing]
    return x * c - y * s, -(x * s + y * c)


@dataclass
class Spec:
    name: str
    source: str
    pins: list[tuple[str, float, float, str | None]]  # name, x, y, default net
    shapes: list[dict]
    note: str
    note_at: tuple[float, float]
    label_dir: dict[str, tuple[float, float]]
    keep_free: list[tuple[float, float]] = field(default_factory=list)
    facts: list[str] = field(default_factory=list)

    def hole(self, pin1: Hole, x: float, y: float, facing: str) -> Hole:
        dx, dy = turn(x, y, facing)
        return pin1[0] + round(dx / P), pin1[1] + round(dy / P)

    def place(self, ref: str, pin1: Hole, facing: str = 'up', nets: dict[str, str | None] | None = None,
              note: str | None = None, note_at: tuple[float, float] | None = None,
              note_va: str = 'center', note_ha: str = 'center', standoff: float = 0.0) -> Module:
        nets = nets or {}
        pins = {self.hole(pin1, x, y, facing): (name, nets.get(name, net)) for name, x, y, net in self.pins}
        shapes = []
        for shape in self.shapes:
            shape = dict(shape)
            if 'rect' in shape:
                x0, x1, y0, y1 = shape.pop('rect')
                (ax, ay), (bx, by) = turn(x0, y0, facing), turn(x1, y1, facing)
                shape['rect'] = (min(ax, bx), max(ax, bx), min(ay, by), max(ay, by))
            elif 'poly' in shape:
                shape['poly'] = [turn(x, y, facing) for x, y in shape['poly']]
            else:
                x, y, r = shape.pop('circle')
                shape['circle'] = (*turn(x, y, facing), r)
            shapes.append(shape)
        return Module(ref, pins, shapes, note=f'{ref}  ' + (self.note if note is None else note),
                      note_at=turn(*(note_at or self.note_at), facing), note_va=note_va,
                      keep_free=[self.hole(pin1, x, y, facing) for x, y in self.keep_free],
                      origin=pin1, standoff=standoff, note_ha=note_ha, label_dirs={n: turn(x, y, facing) for n, (x, y) in self.label_dir.items()})


def _sht45() -> Spec:
    # From LaskaKit's STEP model (Temp-HumSensor_SHT45 v1, header centre at 0,0), shifted so SDA sits at the
    # origin; the v2.1 on sale has the same outline. Pin order from the back silkscreen, mirrored to the front.
    def at(x: float, y: float) -> tuple[float, float]:
        return x + 3.81, y

    outline = [at(x, y) for x, y in [
        (-9.53, -1.27), (9.53, -1.27), (9.53, 15.24), (4.44, 15.24), (4.44, 8.25), (3.44, 8.25),
        (3.44, 15.24), (2.54, 15.24), (2.54, 22.86), (-2.54, 22.86), (-2.54, 15.24), (-3.44, 15.24),
        (-3.44, 8.25), (-4.44, 8.25), (-4.44, 15.24), (-9.53, 15.24),
    ]]
    red = {'fc': '#d8312f', 'ec': '#8a1a18', 'lw': 1.2, 'alpha': 0.92}
    socket = {'fc': '#eeeeee', 'ec': '#999999', 'lw': 0.6}
    hole = {'fc': '#e8d6a0', 'ec': '#bbbbbb', 'lw': 1.5}
    return Spec(
        name='LaskaKit SHT45',
        source='https://www.laskakit.cz/laskakit-sht45-senzor-teploty-a-vlhkosti-vzduchu/ '
               '(STEP: https://github.com/LaskaKit/Temp-HumSensor-SHTxx)',
        pins=[('SDA', 0, 0, 'SDA'), ('SCL', 2.54, 0, 'SCL'), ('GND', 5.08, 0, 'GND'), ('VCC', 7.62, 0, '3V3')],
        shapes=[
            {'poly': outline, **red},
            {'rect': (*at(-8.1, -3.9), 2.08, 8.08), **socket},
            {'rect': (*at(3.9, 8.1), 2.08, 8.08), **socket},
            {'circle': (*at(-6.99, 12.7), 1.25), **hole},
            {'circle': (*at(6.99, 12.7), 1.25), **hole},
            {'rect': (*at(-0.75, 0.75), 20.21, 21.71), 'fc': '#111111', 'ec': 'none'},
        ],
        note='LaskaKit SHT45 (0x44)\ntongue free in the air',
        note_at=at(0, 26),
        label_dir={n: (0, 1.3) for n in ('SDA', 'SCL', 'GND', 'VCC')},
        facts=[
            'board 19.05 x 16.5 mm, tongue +/-2.54 mm to 22.9 mm from the header, chip 21.0 mm out',
            'M2 holes (2.5 mm) 12.7 mm from the header, +/-7.0 mm from its centre',
            'two uSup (JST-SH 4-pin) sockets, 4.3 mm tall, beside the header',
            'VCC 1.1-3.6 V; I2C pull-ups on board, solder jumper I2C PULLUP; 0x44 (0x45 variant)',
            'tongue breaks off at 8.3 mm, the pull-ups stay on the main part',
        ],
    )


def _veml7700() -> Spec:
    # Clone of Adafruit 4162 (non-STEMMA); geometry from Adafruit-VEML7700-PCB, header row at y 2.54 there.
    def at(x: float, y: float) -> tuple[float, float]:
        return x - 3.175, y - 2.54

    blue = {'fc': '#1f4fa0', 'ec': '#1f4fa0', 'lw': 1.2, 'alpha': 0.92}
    hole = {'fc': '#e8d6a0', 'ec': '#9ab0d8', 'lw': 1.2}
    return Spec(
        name='VEML7700 (Adafruit 4162 clone)',
        source='https://techfun.cz/produkt/snimac-intenzity-osvetleni-veml7700/ '
               '(PCB: https://github.com/adafruit/Adafruit-VEML7700-PCB)',
        pins=[('VIN', 0, 0, '3V3'), ('3Vo', 2.54, 0, None), ('GND', 5.08, 0, 'GND'),
              ('SCL', 7.62, 0, 'SCL'), ('SDA', 10.16, 0, 'SDA')],
        shapes=[
            {'rect': (-3.175, 13.335, -2.54, 13.97), **blue},
            {'rect': (4.27, 6.67, 2.32, 9.12), 'fc': '#e8f0ff', 'ec': '#9ab0d8', 'lw': 0.8},
            {'circle': (*at(2.54, 13.97), 1.25), **hole},
            {'circle': (*at(13.97, 13.97), 1.25), **hole},
        ],
        note='VEML7700 (0x10)\n3Vo not connected',
        note_at=at(8.255, 19),
        label_dir={n: (0, 1.3) for n in ('VIN', '3Vo', 'GND', 'SCL', 'SDA')},
        facts=[
            'board 16.5 x 16.5 mm (shop says 17 x 17 x 4), header 2.54 mm from one edge',
            'M2.5 holes (2.5 mm) on the opposite edge, 2.54 mm in from both corners',
            'sensor in the middle, 5.5 mm along and 5.7 mm out from pin 1',
            'VIN 3.3-5 V through an LDO, 3Vo is its output; level shifter and pull-ups on board; 0x10',
        ],
    )


def _inmp441() -> Spec:
    # Rows 7.62 mm apart and a 14 mm round board, as in github.com/barafael/inmp441-breakout-kicad; the photos agree.
    # That footprint has the pin order mirrored against the shop photos; the photos win until checked on the module.
    # Back silkscreen reads SCK WS L/R over SD VDD GND; seen from the front the rows run mirrored.
    black = {'fc': '#1b1b1b', 'ec': '#555555', 'lw': 1.2, 'alpha': 0.95}
    return Spec(
        name='INMP441 module',
        source='shop photos (round black board, 2 x 3 pins)',
        pins=[('GND', 0, 0, 'GND'), ('VDD', 2.54, 0, '3V3'), ('SD', 5.08, 0, 'SD'),
              ('L/R', 0, 7.62, 'GND'), ('WS', 2.54, 7.62, 'WS'), ('SCK', 5.08, 7.62, 'SCK')],
        shapes=[
            {'circle': (2.54, 3.81, 7.0), **black},
            {'rect': (2.5, 6.4, 2.9, 4.7), 'fc': '#c8c8c8', 'ec': '#888888', 'lw': 0.6},
            {'circle': (2.54, 3.81, 0.5), 'fc': 'none', 'ec': '#ffcc00', 'lw': 1, 'ls': ':'},
        ],
        note='INMP441, sound port faces the board:\nkeep it on sockets, no tall parts under the port',
        note_at=(2.54, -5.5),
        label_dir={'GND': (0, -1.3), 'VDD': (0, -1.3), 'SD': (0, -1.3),
                   'L/R': (0, 1.3), 'WS': (0, 1.3), 'SCK': (0, 1.3)},
        facts=[
            'VDD 1.8-3.3 V, 1.4 mA; L/R low = left slot (strap to GND)',
            'mic can on the front, bottom port through the board to the back, centre of the module',
            'round board 14 mm across (the shop lists 14 x 22 mm), check with a caliper',
        ],
    )


SHT45 = _sht45()
VEML7700 = _veml7700()
INMP441 = _inmp441()
