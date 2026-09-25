"""Electrical schematic of the whole station: ESP32 DevKit, BMP280, SHT45, VEML7700 and INMP441.

The circuit only, no cable and no connectors. ESP32 pin names as printed on the
DOIT ESP32 DevKit V1 (esp32-devkit-v1-pinout.png, playelek.com, CC BY).

Run with .venv/bin/python docs/hardware/station-schematic.py; writes docs/hardware/station-schematic.svg.
"""

from pathlib import Path

import schemdraw
import schemdraw.elements as elm

from schematic import chip, decoupling, ground, i2c_bus, key, mic_power, net_label, pin, side_label, supply

OUT = Path(__file__).with_suffix('.svg')


def draw(d: schemdraw.Drawing) -> None:
    d.config(unit=2, fontsize=11)

    esp = chip('U1\nESP32-WROOM-32\nDOIT DevKit V1', (4.2, 7.2),
               left=['5V (USB)', '3V3', None, None, None, 'GND'],
               right=['D22', 'D21', None, 'D26', 'D25', 'D33'])
    d += esp.at((0, 0))

    # Power: a 5 V USB charger into the DevKit's micro-USB; its regulator gives 3V3 for everything
    d += elm.Line().at(pin(esp, '5V')).left(2.4)
    d += elm.Dot(open=True).label('USB charger\n5 V', 'left')
    supply(d, pin(esp, '3V3'))
    ground(d, pin(esp, 'GND'))

    # I2C: one bus, pull-ups at the ESP32, three sensors on it
    x0 = pin(esp, 'D21')[0]
    bmp = chip('U2\nBMP280\n0x76', (3.6, 3.6), left=['VCC', 'GND'], right=['CSB', 'SDO'], top=['SCL'], bottom=['SDA'])
    sht = chip('U3\nSHT45\n0x44', (3.6, 3.6), left=['VCC', 'GND'], top=['SCL'], bottom=['SDA'])
    veml = chip('U4\nVEML7700\n0x10', (3.6, 3.6), left=['VIN', '3Vo', 'GND'], top=['SCL'], bottom=['SDA'])
    i2c_bus(d, pin(esp, 'D21'), pin(esp, 'D22'), [(bmp, x0 + 7.4), (sht, x0 + 15.4), (veml, x0 + 22.4)],
            pullup_x=x0 + 2.4)
    supply(d, pin(bmp, 'VCC'))
    ground(d, pin(bmp, 'GND'))
    supply(d, pin(bmp, 'CSB'), 'right')
    ground(d, pin(bmp, 'SDO'), 'right')
    supply(d, pin(sht, 'VCC'))
    ground(d, pin(sht, 'GND'))
    supply(d, pin(veml, 'VIN'))
    d += elm.Line().at(pin(veml, '3Vo')).left(0.6)
    d += elm.Label().theta(0).label('n.c.', 'left')
    ground(d, pin(veml, 'GND'))

    # I2S: 47 Ω at each source - SCK and WS at the ESP32, SD at the microphone
    x_mic = x0 + 19
    mic = chip('U5\nINMP441', (3.6, 3.6), left=['SCK', 'WS', 'SD'], right=['VDD', 'L/R', 'GND'])
    d += mic.anchor(key('SCK')).at((x_mic, pin(esp, 'D26')[1]))
    for esp_pin, net, ref in (('D26', 'SCK', 'R3'), ('D25', 'WS', 'R4')):
        x, y = pin(esp, esp_pin)
        d += elm.Line().at((x, y)).right(1.0)
        resistor = elm.Resistor().right(2.0)
        d += resistor
        side_label(d, resistor, f'{ref} 47 Ω')
        d += elm.Line().to(pin(mic, net))
        net_label(d, (x + 6.0, y), net)
    x, y = pin(esp, 'D33')
    d += elm.Line().at((x, y)).to((x_mic - 4.0, y))
    net_label(d, (x + 6.0, y), 'SD')
    resistor = elm.Resistor().right(2.0)
    d += resistor
    side_label(d, resistor, 'R5 47 Ω')
    d += elm.Line().to(pin(mic, 'SD'))
    mic_power(d, mic)

    # Outdoors at the cable end: filters what comes down the 4 m; every module has its own 100n at the chip
    xc, yc = x_mic - 1.2, pin(esp, 'D33')[1] - 2.2
    decoupling(d, xc, yc)


def main() -> None:
    d = schemdraw.Drawing(show=False)
    draw(d)
    d.save(str(OUT))
    print(OUT)


if __name__ == '__main__':
    main()
