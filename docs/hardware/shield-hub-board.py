"""Shield hub on a 30x70 mm perfboard (10x24 holes): FTP, SHT4x, VEML7700 and INMP441 on one board.

Mounted with column 1 up: the FTP enters from the top, the SHT4x tongue points
down, the VEML7700 faces the east louvers. Module footprints live in breakouts.py.

Run with .venv/bin/python docs/hardware/shield-hub-board.py [--svg]; writes docs/hardware/shield-hub-board.svg.
"""

from pathlib import Path

from layout import Board, Cable, Capacitor, Jumper, Resistor, Tie
from breakouts import INMP441, SHT4X, VEML7700

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
    footer='Bottom runs: tinned wire along the holes; top links: insulated wire. '
           'Both FTP grounds tied through column 4. '
           'I2C pull-ups on the indoor base, module pull-ups stay. '
           'Mask the SHT4x tongue, the VEML window and the mic port before Plastik 70.',
)

# Male header long tails are soldered through the perfboard; short tails are
# soldered to the modules. Check the actual plastic spacer height before assembly.
STANDOFF = 4.0

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
    SHT4X.place('U3', (20, 2), 'right', standoff=STANDOFF, note_at=(8.8, 23), note_va='top', note_ha='left'),
    VEML7700.place('U4', (18, 6), 'left', note='VEML7700 (0x10), sensor up,\nfaces the east louvers, 3Vo not connected',
                   note_at=(19, 5), note_va='bottom', standoff=STANDOFF),
    INMP441.place('U5', (10, 9), 'left', nets={'SD': 'SD_MIC'}, note_at=(-7.5, 3.81), note_va='top',
                  standoff=STANDOFF),
    # Lies diagonally, partly under the mic; a standard 1/4 W body fits the 9.2 mm span.
    Resistor('R5', '47 Ω', (11, 5), (8, 7), 'SD', 'SD_MIC'),
    # 3V3 to the mic VDD: over the GND run, between the mic header rows.
    Jumper('W1', (8, 10), (8, 8), '3V3'),
    # At the cable end, next to the FTP 3V3 and GND pads; the modules have
    # their own local decoupling capacitors.
    Capacitor('C2', '10µ', (3, 10), (3, 9), '3V3', 'GND', electrolytic=True),
)

board.run('SDA', (5, 2), (20, 2))
board.run('SCL', (5, 3), (20, 3))
board.run('GND', (5, 4), (20, 4))
board.run('GND', (5, 4), (4, 4), (4, 9), (5, 9))
board.run('GND', (5, 9), (10, 9))
board.run('GND', (4, 9), (3, 9))
board.run('3V3', (3, 10), (19, 10), (19, 5), (20, 5))
board.run('3V3', (19, 6), (18, 6))
board.run('SD', (5, 5), (11, 5))
board.run('SD_MIC', (7, 7), (8, 7))
board.run('3V3', (7, 8), (8, 8))
board.run('SCK', (5, 7), (5, 6), (10, 6), (10, 7))
# Top wire ends sit in free holes; bridges on the bottom reach the FTP pad and the mic pin.
board.run('WS', (5, 8), (6, 8))
board.top_wire('WS', (6, 8), (6, 5.89), (11.31, 5.89), (11.31, 8), (11, 8))
board.run('WS', (10, 8), (11, 8))

if __name__ == '__main__':
    board.main()
