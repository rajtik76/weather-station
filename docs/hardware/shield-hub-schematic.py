"""Schematic of the shield hub: the board in the radiation shield at the far end of the FTP cable.

The FTP wires come in on the left (colour and signal), the other end lands on the
ESP32 board. Soldering layout: shield-hub-board.py. References as in station-schematic.py.

Run with .venv/bin/python docs/hardware/shield-hub-schematic.py; writes docs/hardware/shield-hub-schematic.svg.
"""

from pathlib import Path

import schemdraw
import schemdraw.elements as elm

from schematic import PIN, chip, decoupling, ground, i2c_bus, key, mic_power, pin, side_label, supply

OUT = Path(__file__).with_suffix('.svg')

# (signal, FTP wire colour), top to bottom
WIRES = [
    ('SCL', 'white-green'),
    ('SDA', 'orange'),
    (None, None),
    ('SCK', 'white-brown'),
    ('WS', 'brown'),
    ('SD', 'blue'),
    (None, None),
    ('3V3', 'white-orange'),
    ('GND', 'green'),
    ('GND', 'white-blue'),
]


def draw(d: schemdraw.Drawing) -> None:
    d.config(unit=2, fontsize=11)

    ends = {}
    for i, (signal, colour) in enumerate(WIRES):
        if signal is None:
            continue
        at = (0, -i * PIN)
        d += elm.Dot(open=True).at(at).label(f'{colour}  {signal}', 'left')
        ends.setdefault(signal, []).append(at)

    supply(d, ends['3V3'][0], 'right')
    for at in ends['GND']:
        ground(d, at, 'right')

    # I2C: both sensors on the bus from the cable; pull-ups sit on the ESP32 board and on the modules
    x0 = ends['SDA'][0][0]
    sht = chip('U3\nSHT45\n0x44', (3.6, 3.6), left=['VCC', 'GND'], top=['SCL'], bottom=['SDA'])
    veml = chip('U4\nVEML7700\n0x10', (3.6, 3.6), left=['VIN', '3Vo', 'GND'], top=['SCL'], bottom=['SDA'])
    i2c_bus(d, ends['SDA'][0], ends['SCL'][0], [(sht, x0 + 5.0), (veml, x0 + 12.0)])
    supply(d, pin(sht, 'VCC'))
    ground(d, pin(sht, 'GND'))
    supply(d, pin(veml, 'VIN'))
    d += elm.Line().at(pin(veml, '3Vo')).left(0.6)
    d += elm.Label().theta(0).label('n.c.', 'left')
    ground(d, pin(veml, 'GND'))

    # I2S: SCK and WS straight to the microphone (their 47 Ω sit at the ESP32), SD through 47 Ω here
    x_mic = x0 + 10
    mic = chip('U5\nINMP441', (3.6, 3.6), left=['SCK', 'WS', 'SD'], right=['VDD', 'L/R', 'GND'])
    d += mic.anchor(key('SCK')).at((x_mic, ends['SCK'][0][1]))
    d += elm.Line().at(ends['SCK'][0]).to(pin(mic, 'SCK'))
    d += elm.Line().at(ends['WS'][0]).to(pin(mic, 'WS'))
    d += elm.Line().at(ends['SD'][0]).right(3.0)
    resistor = elm.Resistor().right(2.0)
    d += resistor
    side_label(d, resistor, 'R5 47 Ω')
    d += elm.Line().to(pin(mic, 'SD'))
    mic_power(d, mic)

    # At the cable end: filters what comes down the 4 m; every module has its own 100n at the chip
    xc, yc = x_mic, ends['3V3'][0][1] + 0.4
    decoupling(d, xc, yc)


def main() -> None:
    d = schemdraw.Drawing(show=False)
    draw(d)
    d.save(str(OUT))
    print(OUT)


if __name__ == '__main__':
    main()
