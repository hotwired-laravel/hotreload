import { log } from "../logger.js";
import {
  cacheBustedUrl,
  reloadHtmlDocument,
  pathWithoutAssetDigest,
} from "../helpers.js";

export class CssReloader {
  static async reload(...params) {
    return new CssReloader(...params).reload();
  }

  constructor(filePattern = /./) {
    this.filePattern = filePattern;
  }

  async reload() {
    log("Reload css...");
    await Promise.all(await this.#reloadAllLinks());
  }

  async #reloadAllLinks() {
    const cssLinks = await this.#loadNewCssLinks();
    return cssLinks.map((link) => this.#reloadLinkIfNeeded(link));
  }

  async #loadNewCssLinks() {
    const reloadedDocument = await reloadHtmlDocument();
    return Array.from(
      reloadedDocument.head.querySelectorAll("link[rel='stylesheet']"),
    );
  }

  #reloadLinkIfNeeded(link) {
    if (this.#shouldReloadLink(link)) {
      return this.#reloadLink(link);
    } else {
      return Promise.resolve();
    }
  }

  #shouldReloadLink(link) {
    return this.filePattern.test(link.getAttribute("href"));
  }

  async #reloadLink(link) {
    return new Promise((resolve) => {
      const href = link.getAttribute("href");
      const newLink =
        this.#findExistingLinkFor(link) || this.#appendNewLink(link);

      newLink.setAttribute("href", cacheBustedUrl(link.getAttribute("href")));
      newLink.onload = () => {
        log(`\t${href}`);
        resolve();
      };
    });
  }

  #findExistingLinkFor(link) {
    return this.#cssLinks.find(
      (newLink) =>
        pathWithoutAssetDigest(link.href) ===
        pathWithoutAssetDigest(newLink.href),
    );
  }

  get #cssLinks() {
    return Array.from(document.querySelectorAll("link[rel='stylesheet']"));
  }

  #appendNewLink(link) {
    document.head.append(link);
    return link;
  }
}
