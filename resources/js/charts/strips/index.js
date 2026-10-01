import { lightStrip } from "./light";
import { noiseStrip } from "./noise";
import { spectrumStrip } from "./spectrum";
import { pressureStrip, temperatureStrip } from "./weather";

/**
 * A strip brings its own axes and series (`option`) and what its tooltip
 * lists for a row (`lines`); the frame, tooltip and crosshair are shared.
 * `rows` and `time` say where the tooltip finds the row under the pointer.
 * Pressure has its own strip: on a shared axis its 40 hPa range is a flat line.
 *
 * Optional hooks, called for any strip that has them: `mount(chart)` once when
 * the chart is created, `afterResize(chart)` after every repaint and resize.
 */
export const STRIPS = [temperatureStrip, pressureStrip, noiseStrip, spectrumStrip, lightStrip];
