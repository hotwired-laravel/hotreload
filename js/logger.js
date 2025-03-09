import config from "./config";

export function log(...messages) {
  if (config.loggingEnabled) {
    console.log(`[hotwire hotreload]`, ...messages);
  }
}
