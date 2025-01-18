import { ServerSentEventsChannel } from "./channels";
import { getConfigurationProperty } from "./helpers.js";

const HotwireHotreloadDefaultConfigs = {
  loggingEnabled: false,
  htmlReloadMethod: "morph",
};

const HotwireHotreload = {
  config: { ...HotwireHotreloadDefaultConfigs },
};

window.HotwireHotreload = HotwireHotreload;

const configProperties = {
  loggingEnabled: "logging",
  htmlReloadMethod: "html-reload-method",
};

const syncConfigs = async () => {
  Object.entries(configProperties).forEach(([key, property]) => {
    HotwireHotreload.config[key] =
      getConfigurationProperty(property) ?? HotwireHotreloadDefaultConfigs[key];
  });
};

document.addEventListener("DOMContentLoaded", async () => {
  await syncConfigs();

  document.body.setAttribute("data-hotwire-hotreload-ready", "");

  await ServerSentEventsChannel.start();
});

document.addEventListener("turbo:load", async () => {
  await syncConfigs();
});

export default HotwireHotreload;
