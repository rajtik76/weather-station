"""Shield hub on a 30x70 mm perfboard (10x24 holes): FTP, SHT45, VEML7700 and INMP441 on one board.

Mounted with column 1 up: the FTP enters from the top, the SHT45 tongue points
down, the VEML7700 faces the east louvers. Module footprints live in breakouts.py.

Run with .venv/bin/python docs/hardware/shield-hub-board.py [--svg]; writes docs/hardware/shield-hub-board.svg.
"""

from pathlib import Path

from layout import Board, Cable, Capacitor, Resistor, Tie
from breakouts import INMP441, SHT45, VEML7700

board = Board(
    title='Shield hub, 30x70 mm',
    out=Path(__file__).with_suffix('.svg'),
    cols=24,
    rows=10,
    width=70.0,
    height=30.0,
    nets={
        '3V3': '#d62728',
        'GND': '#333333',
        'SDA': '#1f77b4',
        'SCL': '#bcbd22',
        'SD': '#9467bd',
        'SD_MIC': '#9467bd',
        'SCK': '#2ca02c',
        'WS': '#ff7f0e',
    },
    footer='Bottom runs: tinned wire along the holes. Both FTP grounds tied through column 4. '
           'I2C pull-ups on the indoor base, module pull-ups stay. '
           'Mask the SHT45 tongue, the VEML window and the mic port before Plastik 70.',
)

# Every module plugs into Dupont sockets: 8.5 mm female header + 2.5 mm spacer of the male pins.
# Check with the real parts; parts under a module must stand at least 1 mm lower.
STANDOFF = 11.0

# FTP wire colours; the other end lands on Dupont pins on the ESP32 board
ftp = Cable('FTP', [
    ((5, 2), 'SDA', 'orange', '#f28c28', None),
    ((5, 3), 'SCL', 'white-green', 'white', '#3a9d3a'),
    ((5, 4), 'GND', 'green', '#3a9d3a', None),
    ((5, 5), 'SD', 'blue', '#2f6fd6', None),
    ((5, 7), 'SCK', 'white-brown', 'white', '#8b5a2b'),
    ((5, 8), 'WS', 'brown', '#8b5a2b', None),
    ((5, 9), 'GND', 'white-blue', 'white', '#2f6fd6'),
    ((5, 10), '3V3', 'white-orange', 'white', '#f28c28'),
], jacket_row=5, note='FTP 4 m\nfoil + drain cut back,\nnot connected here')

board.add(
    ftp,
    Tie('TIE', [(2, 3), (2, 7)]),
    SHT45.place('U3', (20, 2), 'right', standoff=STANDOFF, note_at=(8.8, 23), note_va='top', note_ha='left'),
    VEML7700.place('U4', (18, 6), 'left', note='VEML7700 (0x10), sensor up,\nfaces the east louvers, 3Vo not connected',
                   note_at=(19, 5), note_va='bottom', standoff=STANDOFF),
    INMP441.place('U5', (10, 9), 'left', nets={'SD': 'SD_MIC'}, note_at=(-7.5, 3.81), note_va='top',
                  standoff=STANDOFF),
    Resistor('R5', '47 Ω', (12, 5), (12, 9), 'SD', 'SD_MIC'),
    # At the cable end, next to the FTP 3V3 and GND pads: they filter what comes down the 4 m;
    # each module carries its own 100n at the chip. C1 sits under the rim of the mic, C2 is too tall for that.
    Capacitor('C1', '100n', (6, 10), (6, 9), '3V3', 'GND'),
    Capacitor('C2', '10µ', (2, 10), (2, 9), '3V3', 'GND', electrolytic=True),
)

board.run('SDA', (5, 2), (20, 2))
board.run('SCL', (5, 3), (20, 3))
board.run('GND', (5, 4), (20, 4))
board.run('GND', (5, 4), (4, 4), (4, 9), (5, 9))
board.run('GND', (5, 9), (10, 9))
board.run('GND', (4, 9), (2, 9))
board.run('3V3', (2, 10), (19, 10), (19, 5), (20, 5))
board.run('3V3', (19, 6), (18, 6))
board.run('3V3', (10, 8), (11, 8), (11, 10))
board.run('SD', (5, 5), (12, 5))
board.run('SD_MIC', (10, 7), (12, 7), (12, 9))
board.run('SCK', (5, 7), (7, 7))
board.run('WS', (5, 8), (7, 8))

if __name__ == '__main__':
    board.main()
