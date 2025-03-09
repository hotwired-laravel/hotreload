const config = {
  loggingEnabled: getConfigurationProperty("logging") ?? false,
  htmlReloadMethod: getConfigurationProperty("html-reload-method"),
};

document.addEventListener("turbo:load", () => {
  reloadConfigs();
});

function reloadConfigs() {
  config.loggingEnabled = getConfigurationProperty("logging") ?? false;
  config.htmlReloadMethod = getConfigurationProperty("html-reload-method");
}

function getConfigurationProperty(name) {
  return document.querySelector(`meta[name="hotwire-hotreload:${name}"]`)
    ?.content;
}

export default config;
