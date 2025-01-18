import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  initialize() {
    this.version = 1;
  }

  connect() {
    console.debug("Dummy controller connected ", this.version);
    this.element.querySelector("#replace").innerText = "__REPLACED_STIMULUS__";
    this.element.setAttribute("data-dummy-version", this.version);
  }

  disconnect() {
    console.debug("Dummy controller disconnected ", this.version);
    this.element.removeAttribute("data-dummy-version");
  }
}
