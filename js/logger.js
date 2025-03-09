import config from "./config";

export function log(...messages) {
  console.log(config, messages);
  if (config.loggingEnabled) {
    console.log(`[hotwire hotreload]`, ...messages);
  }
}
