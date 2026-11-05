import { ReplaceHtmlReloader } from "../reloaders/replace_html_reloader.js";
import { MorphHtmlReloader } from "../reloaders/morph_html_reloader.js";
import { StimulusReloader } from "../reloaders/stimulus_reloader.js";
import { CssReloader } from "../reloaders/css_reloader.js";
import { assetNameFromPath } from "../helpers.js";
import { log } from "../logger.js";
import config from "../config.js";

const FALLBACK_URL = "/hotwired-laravel-hotreload/sse";

class ServerSentEventsChannel {
  static async start() {
    if (!config.url) {
      return this.connect(FALLBACK_URL);
    }

    const sse = this.connect(config.url);
    let opened = false;

    sse.addEventListener("open", () => (opened = true), { once: true });

    // When the daemon can't be reached (stale hot file, blocked mixed content,
    // unpublished port...) we fall back to the in-process SSE route...
    sse.addEventListener("error", () => {
      if (opened || sse.readyState === EventSource.OPEN) return;

      log(`Couldn't connect to the hotreload daemon at ${config.url}, falling back to ${FALLBACK_URL}`);

      sse.close();
      this.connect(FALLBACK_URL);
    }, { once: true });
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
