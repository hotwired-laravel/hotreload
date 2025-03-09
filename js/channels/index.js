import { ReplaceHtmlReloader } from "../reloaders/replace_html_reloader.js";
import { MorphHtmlReloader } from "../reloaders/morph_html_reloader.js";
import { StimulusReloader } from "../reloaders/stimulus_reloader.js";
import { CssReloader } from "../reloaders/css_reloader.js";
import { assetNameFromPath } from "../helpers.js";

class ServerSentEventsChannel {
  static async start() {
    const sse = new EventSource("/hotwired-laravel-hotreload/sse");

    sse.addEventListener(
      "tick",
      () => {
        document.body.setAttribute("data-hotwire-hotreload-ready", "true");
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
  }
}

ServerSentEventsChannel.start();
