import { ReplaceHtmlReloader } from "../reloaders/replace_html_reloader.js";
import { MorphHtmlReloader } from "../reloaders/morph_html_reloader.js";
import { StimulusReloader } from "../reloaders/stimulus_reloader.js";
import { CssReloader } from "../reloaders/css_reloader.js";
import { assetNameFromPath } from "../helpers.js";
import { log } from "../logger.js";
import config from "../config.js";

const FALLBACK_URL = "/hotwired-laravel-hotreload/sse";
const DAEMON_URL_ENDPOINT = "/hotwired-laravel-hotreload/daemon";
const MAX_RETRY_DELAY = 5000;
const DAEMON_STARTUP_TIMEOUT = 10000;

class ServerSentEventsChannel {
  static start() {
    if (config.url) {
      return this.connectToDaemon(config.url);
    }

    if (config.daemon === "starting") {
      return this.waitForDaemon();
    }

    this.connect(FALLBACK_URL);
  }

  // The page was rendered before the daemon was up (like right after `php artisan serve`
  // starts). We wait for it instead of falling back to the in-process SSE route, since
  // that one holds up one of the PHP server's workers for as long as the tab is open...
  static async waitForDaemon() {
    const deadline = Date.now() + DAEMON_STARTUP_TIMEOUT;
    let delay = 100;

    log("Waiting for the hotreload daemon to start...");

    while (Date.now() < deadline) {
      await new Promise((resolve) => setTimeout(resolve, delay));

      const url = await this.fetchDaemonUrl();

      if (url) {
        return this.connectToDaemon(url);
      }

      delay = Math.min(delay * 2, 1000);
    }

    log(`The hotreload daemon didn't start in time, falling back to ${FALLBACK_URL}`);

    this.connect(FALLBACK_URL);
  }

  static connectToDaemon(url, { connected = false, attempt = 0 } = {}) {
    const sse = this.connect(url);

    sse.addEventListener("open", () => {
      connected = true;
      attempt = 0;
    });

    // We take over reconnecting from the EventSource, since the daemon may come back
    // on a different port (or not at all)...
    sse.addEventListener(
      "error",
      () => {
        sse.close();
        this.recover(url, { connected, attempt });
      },
      { once: true },
    );
  }

  static async recover(url, { connected, attempt }) {
    const current = await this.fetchDaemonUrl();

    // We never reached the daemon (unpublished port, blocked mixed content, gone daemon...),
    // so we fall back to the in-process SSE route...
    if (!connected) {
      if (current && current !== url) {
        return this.connectToDaemon(current);
      }

      log(`Couldn't connect to the hotreload daemon at ${url}, falling back to ${FALLBACK_URL}`);

      return this.connect(FALLBACK_URL);
    }

    // The daemon worked before, so it's restarting (maybe on another port). We keep
    // asking the app where it is instead of retrying a URL that may be stale...
    const delay = Math.min(250 * 2 ** attempt, MAX_RETRY_DELAY);
    const next = { connected, attempt: attempt + 1 };

    log(`Lost the connection to the hotreload daemon, retrying in ${delay}ms`);

    setTimeout(() => {
      current ? this.connectToDaemon(current, next) : this.recover(url, next);
    }, delay);
  }

  // Returns the daemon's URL, null when it's not running, or undefined when the app can't be reached.
  static async fetchDaemonUrl() {
    try {
      const response = await fetch(DAEMON_URL_ENDPOINT, {
        headers: { Accept: "application/json" },
        cache: "no-store",
      });

      if (!response.ok) return undefined;

      return (await response.json()).url ?? null;
    } catch {
      return undefined;
    }
  }

  static connect(url) {
    const sse = new EventSource(url);

    sse.addEventListener(
      "tick",
      () => {
        document.body.setAttribute("data-hotwire-hotreload-ready", "true");
        document.body.setAttribute("data-hotwire-hotreload-url", url);
      },
      { once: true },
    );

    sse.addEventListener("reload_html", (event) => {
      const data = JSON.parse(event.data);

      const reloader =
        HotwireHotreload.config.htmlReloadMethod === "morph"
          ? MorphHtmlReloader
          : ReplaceHtmlReloader;

      return reloader.reload(data.path);
    });

    sse.addEventListener("reload_stimulus", (event) => {
      if (window.Stimulus !== undefined) {
        const data = JSON.parse(event.data);
        return StimulusReloader.reload(data.path);
      }
    });

    sse.addEventListener("reload_css", (event) => {
      const data = JSON.parse(event.data);
      return CssReloader.reload(new RegExp(assetNameFromPath(data.path)));
    });

    return sse;
  }
}

ServerSentEventsChannel.start();
