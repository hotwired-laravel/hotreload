import HotwireHotreload from "./index.js";

export function log(...messages) {
  if (HotwireHotreload.config.loggingEnabled) {
    console.log(`[hotwire hotreload]`, ...messages);
  }
}
