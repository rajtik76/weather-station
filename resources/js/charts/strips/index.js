import { lightStrip } from "./light";
import { noiseStrip } from "./noise";
import { spectrumStrip } from "./spectrum";
import { pressureStrip, temperatureStrip } from "./weather";

/** A strip brings only `option`, `lines`, `rows` and `time`; the frame, tooltip and crosshair are shared. Optional hooks: `mount(chart)` once, `afterResize(chart)` after every repaint and resize. */
export const STRIPS = [temperatureStrip, pressureStrip, noiseStrip, spectrumStrip, lightStrip];
