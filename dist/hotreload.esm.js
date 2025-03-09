// js/config.js
var config = {
  loggingEnabled: getConfigurationProperty("logging") ?? false,
  htmlReloadMethod: getConfigurationProperty("html-reload-method")
};
document.addEventListener("turbo:load", () => {
  reloadConfigs();
});
function reloadConfigs() {
  config.loggingEnabled = getConfigurationProperty("logging") ?? false;
  config.htmlReloadMethod = getConfigurationProperty("html-reload-method");
}
function getConfigurationProperty(name) {
  return document.querySelector(`meta[name="hotwire-hotreload:${name}"]`)?.content;
}
var config_default = config;

// js/logger.js
function log(...messages) {
  if (config_default.loggingEnabled) {
    console.log(`[hotwire hotreload]`, ...messages);
  }
}

// js/reloaders/replace_html_reloader.js
var ReplaceHtmlReloader = class {
  static async reload() {
    return new ReplaceHtmlReloader().reload();
  }
  async reload() {
    await this.#reloadHtml();
  }
  async #reloadHtml() {
    log("Reload html with Turbo...");
    this.#keepScrollPosition();
    await this.#visitCurrentPage();
  }
  #keepScrollPosition() {
    document.addEventListener(
      "turbo:before-render",
      () => {
        Turbo.navigator.currentVisit.scrolled = true;
      },
      { once: true }
    );
  }
  #visitCurrentPage() {
    return new Promise((resolve) => {
      document.addEventListener("turbo:load", () => resolve(document), {
        once: true
      });
      window.Turbo.visit(window.location, { action: "replace" });
    });
  }
};

// node_modules/idiomorph/dist/idiomorph.esm.js
var Idiomorph = function() {
  "use strict";
  let EMPTY_SET = /* @__PURE__ */ new Set();
  let defaults = {
    morphStyle: "outerHTML",
    callbacks: {
      beforeNodeAdded: noOp,
      afterNodeAdded: noOp,
      beforeNodeMorphed: noOp,
      afterNodeMorphed: noOp,
      beforeNodeRemoved: noOp,
      afterNodeRemoved: noOp,
      beforeAttributeUpdated: noOp,
      beforeNodePantried: noOp
    },
    head: {
      style: "merge",
      shouldPreserve: function(elt) {
        return elt.getAttribute("im-preserve") === "true";
      },
      shouldReAppend: function(elt) {
        return elt.getAttribute("im-re-append") === "true";
      },
      shouldRemove: noOp,
      afterHeadMorphed: noOp
    }
  };
  function morph(oldNode, newContent, config2 = {}) {
    if (oldNode instanceof Document) {
      oldNode = oldNode.documentElement;
    }
    if (typeof newContent === "string") {
      newContent = parseContent(newContent);
    }
    let normalizedContent = normalizeContent(newContent);
    let ctx = createMorphContext(oldNode, normalizedContent, config2);
    return morphNormalizedContent(oldNode, normalizedContent, ctx);
  }
  function morphNormalizedContent(oldNode, normalizedNewContent, ctx) {
    if (ctx.head.block) {
      let oldHead = oldNode.querySelector("head");
      let newHead = normalizedNewContent.querySelector("head");
      if (oldHead && newHead) {
        let promises = handleHeadElement(newHead, oldHead, ctx);
        Promise.all(promises).then(function() {
          morphNormalizedContent(
            oldNode,
            normalizedNewContent,
            Object.assign(ctx, {
              head: {
                block: false,
                ignore: true
              }
            })
          );
        });
        return;
      }
    }
    if (ctx.morphStyle === "innerHTML") {
      morphChildren(normalizedNewContent, oldNode, ctx);
      if (ctx.config.twoPass) {
        restoreFromPantry(oldNode, ctx);
      }
      return Array.from(oldNode.children);
    } else if (ctx.morphStyle === "outerHTML" || ctx.morphStyle == null) {
      let bestMatch = findBestNodeMatch(normalizedNewContent, oldNode, ctx);
      let previousSibling = bestMatch?.previousSibling ?? null;
      let nextSibling = bestMatch?.nextSibling ?? null;
      let morphedNode = morphOldNodeTo(oldNode, bestMatch, ctx);
      if (bestMatch) {
        if (morphedNode) {
          const elements = insertSiblings(
            previousSibling,
            morphedNode,
            nextSibling
          );
          if (ctx.config.twoPass) {
            restoreFromPantry(morphedNode.parentNode, ctx);
          }
          return elements;
        }
      } else {
        return [];
      }
    } else {
      throw "Do not understand how to morph style " + ctx.morphStyle;
    }
  }
  function ignoreValueOfActiveElement(possibleActiveElement, ctx) {
    return !!ctx.ignoreActiveValue && possibleActiveElement === document.activeElement && possibleActiveElement !== document.body;
  }
  function morphOldNodeTo(oldNode, newContent, ctx) {
    if (ctx.ignoreActive && oldNode === document.activeElement) {
    } else if (newContent == null) {
      if (ctx.callbacks.beforeNodeRemoved(oldNode) === false)
        return oldNode;
      oldNode.parentNode?.removeChild(oldNode);
      ctx.callbacks.afterNodeRemoved(oldNode);
      return null;
    } else if (!isSoftMatch(oldNode, newContent)) {
      if (ctx.callbacks.beforeNodeRemoved(oldNode) === false)
        return oldNode;
      if (ctx.callbacks.beforeNodeAdded(newContent) === false)
        return oldNode;
      oldNode.parentNode?.replaceChild(newContent, oldNode);
      ctx.callbacks.afterNodeAdded(newContent);
      ctx.callbacks.afterNodeRemoved(oldNode);
      return newContent;
    } else {
      if (ctx.callbacks.beforeNodeMorphed(oldNode, newContent) === false)
        return oldNode;
      if (oldNode instanceof HTMLHeadElement && ctx.head.ignore) {
      } else if (oldNode instanceof HTMLHeadElement && ctx.head.style !== "morph") {
        handleHeadElement(
          newContent,
          oldNode,
          ctx
        );
      } else {
        syncNodeFrom(newContent, oldNode, ctx);
        if (!ignoreValueOfActiveElement(oldNode, ctx)) {
          morphChildren(newContent, oldNode, ctx);
        }
      }
      ctx.callbacks.afterNodeMorphed(oldNode, newContent);
      return oldNode;
    }
    return null;
  }
  function morphChildren(newParent, oldParent, ctx) {
    if (newParent instanceof HTMLTemplateElement && oldParent instanceof HTMLTemplateElement) {
      newParent = newParent.content;
      oldParent = oldParent.content;
    }
    let nextNewChild = newParent.firstChild;
    let insertionPoint = oldParent.firstChild;
    let newChild;
    while (nextNewChild) {
      newChild = nextNewChild;
      nextNewChild = newChild.nextSibling;
      if (insertionPoint == null) {
        if (ctx.config.twoPass && ctx.persistentIds.has(newChild.id)) {
          oldParent.appendChild(newChild);
        } else {
          if (ctx.callbacks.beforeNodeAdded(newChild) === false)
            continue;
          oldParent.appendChild(newChild);
          ctx.callbacks.afterNodeAdded(newChild);
        }
        removeIdsFromConsideration(ctx, newChild);
        continue;
      }
      if (isIdSetMatch(newChild, insertionPoint, ctx)) {
        morphOldNodeTo(insertionPoint, newChild, ctx);
        insertionPoint = insertionPoint.nextSibling;
        removeIdsFromConsideration(ctx, newChild);
        continue;
      }
      let idSetMatch = findIdSetMatch(
        newParent,
        oldParent,
        newChild,
        insertionPoint,
        ctx
      );
      if (idSetMatch) {
        insertionPoint = removeNodesBetween(insertionPoint, idSetMatch, ctx);
        morphOldNodeTo(idSetMatch, newChild, ctx);
        removeIdsFromConsideration(ctx, newChild);
        continue;
      }
      let softMatch = findSoftMatch(
        newParent,
        oldParent,
        newChild,
        insertionPoint,
        ctx
      );
      if (softMatch) {
        insertionPoint = removeNodesBetween(insertionPoint, softMatch, ctx);
        morphOldNodeTo(softMatch, newChild, ctx);
        removeIdsFromConsideration(ctx, newChild);
        continue;
      }
      if (ctx.config.twoPass && ctx.persistentIds.has(newChild.id)) {
        oldParent.insertBefore(newChild, insertionPoint);
      } else {
        if (ctx.callbacks.beforeNodeAdded(newChild) === false)
          continue;
        oldParent.insertBefore(newChild, insertionPoint);
        ctx.callbacks.afterNodeAdded(newChild);
      }
      removeIdsFromConsideration(ctx, newChild);
    }
    while (insertionPoint !== null) {
      let tempNode = insertionPoint;
      insertionPoint = insertionPoint.nextSibling;
      removeNode(tempNode, ctx);
    }
  }
  function ignoreAttribute(attr, to, updateType, ctx) {
    if (attr === "value" && ctx.ignoreActiveValue && to === document.activeElement) {
      return true;
    }
    return ctx.callbacks.beforeAttributeUpdated(attr, to, updateType) === false;
  }
  function syncNodeFrom(from, to, ctx) {
    let type = from.nodeType;
    if (type === 1) {
      const fromEl = from;
      const toEl = to;
      const fromAttributes = fromEl.attributes;
      const toAttributes = toEl.attributes;
      for (const fromAttribute of fromAttributes) {
        if (ignoreAttribute(fromAttribute.name, toEl, "update", ctx)) {
          continue;
        }
        if (toEl.getAttribute(fromAttribute.name) !== fromAttribute.value) {
          toEl.setAttribute(fromAttribute.name, fromAttribute.value);
        }
      }
      for (let i = toAttributes.length - 1; 0 <= i; i--) {
        const toAttribute = toAttributes[i];
        if (!toAttribute)
          continue;
        if (!fromEl.hasAttribute(toAttribute.name)) {
          if (ignoreAttribute(toAttribute.name, toEl, "remove", ctx)) {
            continue;
          }
          toEl.removeAttribute(toAttribute.name);
        }
      }
    }
    if (type === 8 || type === 3) {
      if (to.nodeValue !== from.nodeValue) {
        to.nodeValue = from.nodeValue;
      }
    }
    if (!ignoreValueOfActiveElement(to, ctx)) {
      syncInputValue(from, to, ctx);
    }
  }
  function syncBooleanAttribute(from, to, attributeName, ctx) {
    if (!(from instanceof Element && to instanceof Element))
      return;
    const fromLiveValue = from[attributeName], toLiveValue = to[attributeName];
    if (fromLiveValue !== toLiveValue) {
      let ignoreUpdate = ignoreAttribute(attributeName, to, "update", ctx);
      if (!ignoreUpdate) {
        to[attributeName] = from[attributeName];
      }
      if (fromLiveValue) {
        if (!ignoreUpdate) {
          to.setAttribute(attributeName, fromLiveValue);
        }
      } else {
        if (!ignoreAttribute(attributeName, to, "remove", ctx)) {
          to.removeAttribute(attributeName);
        }
      }
    }
  }
  function syncInputValue(from, to, ctx) {
    if (from instanceof HTMLInputElement && to instanceof HTMLInputElement && from.type !== "file") {
      let fromValue = from.value;
      let toValue = to.value;
      syncBooleanAttribute(from, to, "checked", ctx);
      syncBooleanAttribute(from, to, "disabled", ctx);
      if (!from.hasAttribute("value")) {
        if (!ignoreAttribute("value", to, "remove", ctx)) {
          to.value = "";
          to.removeAttribute("value");
        }
      } else if (fromValue !== toValue) {
        if (!ignoreAttribute("value", to, "update", ctx)) {
          to.setAttribute("value", fromValue);
          to.value = fromValue;
        }
      }
    } else if (from instanceof HTMLOptionElement && to instanceof HTMLOptionElement) {
      syncBooleanAttribute(from, to, "selected", ctx);
    } else if (from instanceof HTMLTextAreaElement && to instanceof HTMLTextAreaElement) {
      let fromValue = from.value;
      let toValue = to.value;
      if (ignoreAttribute("value", to, "update", ctx)) {
        return;
      }
      if (fromValue !== toValue) {
        to.value = fromValue;
      }
      if (to.firstChild && to.firstChild.nodeValue !== fromValue) {
        to.firstChild.nodeValue = fromValue;
      }
    }
  }
  function handleHeadElement(newHeadTag, currentHead, ctx) {
    let added = [];
    let removed = [];
    let preserved = [];
    let nodesToAppend = [];
    let headMergeStyle = ctx.head.style;
    let srcToNewHeadNodes = /* @__PURE__ */ new Map();
    for (const newHeadChild of newHeadTag.children) {
      srcToNewHeadNodes.set(newHeadChild.outerHTML, newHeadChild);
    }
    for (const currentHeadElt of currentHead.children) {
      let inNewContent = srcToNewHeadNodes.has(currentHeadElt.outerHTML);
      let isReAppended = ctx.head.shouldReAppend(currentHeadElt);
      let isPreserved = ctx.head.shouldPreserve(currentHeadElt);
      if (inNewContent || isPreserved) {
        if (isReAppended) {
          removed.push(currentHeadElt);
        } else {
          srcToNewHeadNodes.delete(currentHeadElt.outerHTML);
          preserved.push(currentHeadElt);
        }
      } else {
        if (headMergeStyle === "append") {
          if (isReAppended) {
            removed.push(currentHeadElt);
            nodesToAppend.push(currentHeadElt);
          }
        } else {
          if (ctx.head.shouldRemove(currentHeadElt) !== false) {
            removed.push(currentHeadElt);
          }
        }
      }
    }
    nodesToAppend.push(...srcToNewHeadNodes.values());
    log2("to append: ", nodesToAppend);
    let promises = [];
    for (const newNode of nodesToAppend) {
      log2("adding: ", newNode);
      let newElt = document.createRange().createContextualFragment(newNode.outerHTML).firstChild;
      log2(newElt);
      if (ctx.callbacks.beforeNodeAdded(newElt) !== false) {
        if ("href" in newElt && newElt.href || "src" in newElt && newElt.src) {
          let resolve;
          let promise = new Promise(function(_resolve) {
            resolve = _resolve;
          });
          newElt.addEventListener("load", function() {
            resolve();
          });
          promises.push(promise);
        }
        currentHead.appendChild(newElt);
        ctx.callbacks.afterNodeAdded(newElt);
        added.push(newElt);
      }
    }
    for (const removedElement of removed) {
      if (ctx.callbacks.beforeNodeRemoved(removedElement) !== false) {
        currentHead.removeChild(removedElement);
        ctx.callbacks.afterNodeRemoved(removedElement);
      }
    }
    ctx.head.afterHeadMorphed(currentHead, {
      added,
      kept: preserved,
      removed
    });
    return promises;
  }
  function log2(..._args) {
  }
  function noOp() {
  }
  function mergeDefaults(config2) {
    let finalConfig = Object.assign({}, defaults);
    Object.assign(finalConfig, config2);
    finalConfig.callbacks = Object.assign(
      {},
      defaults.callbacks,
      config2.callbacks
    );
    finalConfig.head = Object.assign({}, defaults.head, config2.head);
    return finalConfig;
  }
  function createMorphContext(oldNode, newContent, config2) {
    const mergedConfig = mergeDefaults(config2);
    return {
      target: oldNode,
      newContent,
      config: mergedConfig,
      morphStyle: mergedConfig.morphStyle,
      ignoreActive: mergedConfig.ignoreActive,
      ignoreActiveValue: mergedConfig.ignoreActiveValue,
      idMap: createIdMap(oldNode, newContent),
      deadIds: /* @__PURE__ */ new Set(),
      persistentIds: mergedConfig.twoPass ? createPersistentIds(oldNode, newContent) : /* @__PURE__ */ new Set(),
      pantry: mergedConfig.twoPass ? createPantry() : document.createElement("div"),
      callbacks: mergedConfig.callbacks,
      head: mergedConfig.head
    };
  }
  function createPantry() {
    const pantry = document.createElement("div");
    pantry.hidden = true;
    document.body.insertAdjacentElement("afterend", pantry);
    return pantry;
  }
  function isIdSetMatch(node1, node2, ctx) {
    if (node1 == null || node2 == null) {
      return false;
    }
    if (node1 instanceof Element && node2 instanceof Element && node1.tagName === node2.tagName) {
      if (node1.id !== "" && node1.id === node2.id) {
        return true;
      } else {
        return getIdIntersectionCount(ctx, node1, node2) > 0;
      }
    }
    return false;
  }
  function isSoftMatch(oldNode, newNode) {
    if (oldNode == null || newNode == null) {
      return false;
    }
    if (oldNode.id && oldNode.id !== newNode.id) {
      return false;
    }
    return oldNode.nodeType === newNode.nodeType && oldNode.tagName === newNode.tagName;
  }
  function removeNodesBetween(startInclusive, endExclusive, ctx) {
    let cursor = startInclusive;
    while (cursor !== endExclusive) {
      let tempNode = cursor;
      cursor = tempNode.nextSibling;
      removeNode(tempNode, ctx);
    }
    removeIdsFromConsideration(ctx, endExclusive);
    return endExclusive.nextSibling;
  }
  function findIdSetMatch(newContent, oldParent, newChild, insertionPoint, ctx) {
    let newChildPotentialIdCount = getIdIntersectionCount(
      ctx,
      newChild,
      oldParent
    );
    let potentialMatch = null;
    if (newChildPotentialIdCount > 0) {
      potentialMatch = insertionPoint;
      let otherMatchCount = 0;
      while (potentialMatch != null) {
        if (isIdSetMatch(newChild, potentialMatch, ctx)) {
          return potentialMatch;
        }
        otherMatchCount += getIdIntersectionCount(
          ctx,
          potentialMatch,
          newContent
        );
        if (otherMatchCount > newChildPotentialIdCount) {
          return null;
        }
        potentialMatch = potentialMatch.nextSibling;
      }
    }
    return potentialMatch;
  }
  function findSoftMatch(newContent, oldParent, newChild, insertionPoint, ctx) {
    let potentialSoftMatch = insertionPoint;
    let nextSibling = newChild.nextSibling;
    let siblingSoftMatchCount = 0;
    while (potentialSoftMatch != null) {
      if (getIdIntersectionCount(ctx, potentialSoftMatch, newContent) > 0) {
        return null;
      }
      if (isSoftMatch(potentialSoftMatch, newChild)) {
        return potentialSoftMatch;
      }
      if (isSoftMatch(potentialSoftMatch, nextSibling)) {
        siblingSoftMatchCount++;
        nextSibling = nextSibling.nextSibling;
        if (siblingSoftMatchCount >= 2) {
          return null;
        }
      }
      potentialSoftMatch = potentialSoftMatch.nextSibling;
    }
    return potentialSoftMatch;
  }
  const generatedByIdiomorph = /* @__PURE__ */ new WeakSet();
  function parseContent(newContent) {
    let parser = new DOMParser();
    let contentWithSvgsRemoved = newContent.replace(
      /<svg(\s[^>]*>|>)([\s\S]*?)<\/svg>/gim,
      ""
    );
    if (contentWithSvgsRemoved.match(/<\/html>/) || contentWithSvgsRemoved.match(/<\/head>/) || contentWithSvgsRemoved.match(/<\/body>/)) {
      let content = parser.parseFromString(newContent, "text/html");
      if (contentWithSvgsRemoved.match(/<\/html>/)) {
        generatedByIdiomorph.add(content);
        return content;
      } else {
        let htmlElement = content.firstChild;
        if (htmlElement) {
          generatedByIdiomorph.add(htmlElement);
          return htmlElement;
        } else {
          return null;
        }
      }
    } else {
      let responseDoc = parser.parseFromString(
        "<body><template>" + newContent + "</template></body>",
        "text/html"
      );
      let content = responseDoc.body.querySelector("template").content;
      generatedByIdiomorph.add(content);
      return content;
    }
  }
  function normalizeContent(newContent) {
    if (newContent == null) {
      const dummyParent = document.createElement("div");
      return dummyParent;
    } else if (generatedByIdiomorph.has(newContent)) {
      return newContent;
    } else if (newContent instanceof Node) {
      const dummyParent = document.createElement("div");
      dummyParent.append(newContent);
      return dummyParent;
    } else {
      const dummyParent = document.createElement("div");
      for (const elt of [...newContent]) {
        dummyParent.append(elt);
      }
      return dummyParent;
    }
  }
  function insertSiblings(previousSibling, morphedNode, nextSibling) {
    let stack = [];
    let added = [];
    while (previousSibling != null) {
      stack.push(previousSibling);
      previousSibling = previousSibling.previousSibling;
    }
    let node = stack.pop();
    while (node !== void 0) {
      added.push(node);
      morphedNode.parentElement?.insertBefore(node, morphedNode);
      node = stack.pop();
    }
    added.push(morphedNode);
    while (nextSibling != null) {
      stack.push(nextSibling);
      added.push(nextSibling);
      nextSibling = nextSibling.nextSibling;
    }
    while (stack.length > 0) {
      const node2 = stack.pop();
      morphedNode.parentElement?.insertBefore(node2, morphedNode.nextSibling);
    }
    return added;
  }
  function findBestNodeMatch(newContent, oldNode, ctx) {
    let currentElement;
    currentElement = newContent.firstChild;
    let bestElement = currentElement;
    let score = 0;
    while (currentElement) {
      let newScore = scoreElement(currentElement, oldNode, ctx);
      if (newScore > score) {
        bestElement = currentElement;
        score = newScore;
      }
      currentElement = currentElement.nextSibling;
    }
    return bestElement;
  }
  function scoreElement(node1, node2, ctx) {
    if (isSoftMatch(node2, node1)) {
      return 0.5 + getIdIntersectionCount(ctx, node1, node2);
    }
    return 0;
  }
  function removeNode(tempNode, ctx) {
    removeIdsFromConsideration(ctx, tempNode);
    if (ctx.config.twoPass && hasPersistentIdNodes(ctx, tempNode) && tempNode instanceof Element) {
      moveToPantry(tempNode, ctx);
    } else {
      if (ctx.callbacks.beforeNodeRemoved(tempNode) === false)
        return;
      tempNode.parentNode?.removeChild(tempNode);
      ctx.callbacks.afterNodeRemoved(tempNode);
    }
  }
  function moveToPantry(node, ctx) {
    if (ctx.callbacks.beforeNodePantried(node) === false)
      return;
    Array.from(node.childNodes).forEach((child) => {
      moveToPantry(child, ctx);
    });
    if (ctx.persistentIds.has(node.id)) {
      if (ctx.pantry.moveBefore) {
        ctx.pantry.moveBefore(node, null);
      } else {
        ctx.pantry.insertBefore(node, null);
      }
    } else {
      if (ctx.callbacks.beforeNodeRemoved(node) === false)
        return;
      node.parentNode?.removeChild(node);
      ctx.callbacks.afterNodeRemoved(node);
    }
  }
  function restoreFromPantry(root, ctx) {
    if (root instanceof Element) {
      Array.from(ctx.pantry.children).reverse().forEach((element) => {
        const matchElement = root.querySelector(`#${element.id}`);
        if (matchElement) {
          if (matchElement.parentElement?.moveBefore) {
            matchElement.parentElement.moveBefore(element, matchElement);
            while (matchElement.hasChildNodes()) {
              element.moveBefore(matchElement.firstChild, null);
            }
          } else {
            matchElement.before(element);
            while (matchElement.firstChild) {
              element.insertBefore(matchElement.firstChild, null);
            }
          }
          if (ctx.callbacks.beforeNodeMorphed(element, matchElement) !== false) {
            syncNodeFrom(matchElement, element, ctx);
            ctx.callbacks.afterNodeMorphed(element, matchElement);
          }
          matchElement.remove();
        }
      });
      ctx.pantry.remove();
    }
  }
  function isIdInConsideration(ctx, id) {
    return !ctx.deadIds.has(id);
  }
  function idIsWithinNode(ctx, id, targetNode) {
    let idSet = ctx.idMap.get(targetNode) || EMPTY_SET;
    return idSet.has(id);
  }
  function removeIdsFromConsideration(ctx, node) {
    let idSet = ctx.idMap.get(node) || EMPTY_SET;
    for (const id of idSet) {
      ctx.deadIds.add(id);
    }
  }
  function hasPersistentIdNodes(ctx, node) {
    for (const id of ctx.idMap.get(node) || EMPTY_SET) {
      if (ctx.persistentIds.has(id)) {
        return true;
      }
    }
    return false;
  }
  function getIdIntersectionCount(ctx, node1, node2) {
    let sourceSet = ctx.idMap.get(node1) || EMPTY_SET;
    let matchCount = 0;
    for (const id of sourceSet) {
      if (isIdInConsideration(ctx, id) && idIsWithinNode(ctx, id, node2)) {
        ++matchCount;
      }
    }
    return matchCount;
  }
  function nodesWithIds(content) {
    let nodes = Array.from(content.querySelectorAll("[id]"));
    if (content.id) {
      nodes.push(content);
    }
    return nodes;
  }
  function populateIdMapForNode(node, idMap) {
    let nodeParent = node.parentElement;
    for (const elt of nodesWithIds(node)) {
      let current = elt;
      while (current !== nodeParent && current != null) {
        let idSet = idMap.get(current);
        if (idSet == null) {
          idSet = /* @__PURE__ */ new Set();
          idMap.set(current, idSet);
        }
        idSet.add(elt.id);
        current = current.parentElement;
      }
    }
  }
  function createIdMap(oldContent, newContent) {
    let idMap = /* @__PURE__ */ new Map();
    populateIdMapForNode(oldContent, idMap);
    populateIdMapForNode(newContent, idMap);
    return idMap;
  }
  function createPersistentIds(oldContent, newContent) {
    const toIdTagName = (node) => node.tagName + "#" + node.id;
    const oldIdSet = new Set(nodesWithIds(oldContent).map(toIdTagName));
    let matchIdSet = /* @__PURE__ */ new Set();
    for (const newNode of nodesWithIds(newContent)) {
      if (oldIdSet.has(toIdTagName(newNode))) {
        matchIdSet.add(newNode.id);
      }
    }
    return matchIdSet;
  }
  return {
    morph,
    defaults
  };
}();

// js/helpers.js
function assetNameFromPath(path) {
  return path.split("/").pop().split(".")[0];
}
function pathWithoutAssetDigest(path) {
  return path.replace(/\?.*$/, "");
}
function urlWithParams(urlString, params) {
  const url = new URL(urlString, window.location.origin);
  Object.entries(params).forEach(([key, value]) => {
    url.searchParams.set(key, value);
  });
  return url.toString();
}
function cacheBustedUrl(urlString) {
  return urlWithParams(urlString, { reload: Date.now() });
}
async function reloadHtmlDocument() {
  let currentUrl = cacheBustedUrl(
    urlWithParams(window.location.href, { hotwire_spark: "true" })
  );
  const response = await fetch(currentUrl, {
    headers: { Accept: "text/html" }
  });
  if (!response.ok) {
    throw new Error(`${response.status} when fetching ${currentUrl}`);
  }
  const fetchedHTML = await response.text();
  const parser = new DOMParser();
  return parser.parseFromString(fetchedHTML, "text/html");
}

// js/reloaders/stimulus_reloader.js
var StimulusReloader = class {
  static async reload(changedFilePath) {
    const document2 = await reloadHtmlDocument();
    return new StimulusReloader(document2, changedFilePath).reload();
  }
  static async reloadAll() {
    Stimulus.controllers.forEach((controller) => {
      Stimulus.unload(controller.identifier);
      Stimulus.register(controller.identifier, controller.constructor);
    });
    return Promise.resolve();
  }
  constructor(document2, changedFilePath) {
    this.document = document2;
    this.changedFilePath = changedFilePath;
    this.application = window.Stimulus;
  }
  async reload() {
    log("Reload Stimulus controllers...");
    this.application.stop();
    await this.#reloadChangedStimulusControllers();
    this.#unloadDeletedStimulusControllers();
    this.application.start();
  }
  async #reloadChangedStimulusControllers() {
    await Promise.all(
      this.#stimulusControllerPathsToReload.map(
        async (moduleName) => this.#reloadStimulusController(moduleName)
      )
    );
  }
  get #stimulusControllerPathsToReload() {
    this.controllerPathsToReload = this.controllerPathsToReload || this.#stimulusControllerPaths.filter(
      (path) => this.#shouldReloadController(path)
    );
    return this.controllerPathsToReload;
  }
  get #stimulusControllerPaths() {
    return Object.keys(this.#stimulusPathsByModule).filter(
      (path) => path.endsWith("_controller")
    );
  }
  #shouldReloadController(path) {
    return this.#extractControllerName(path) === this.#changedControllerIdentifier;
  }
  get #changedControllerIdentifier() {
    this.changedControllerIdentifier = this.changedControllerIdentifier || this.#extractControllerName(this.changedFilePath);
    return this.changedControllerIdentifier;
  }
  get #stimulusPathsByModule() {
    this.pathsByModule = this.pathsByModule || this.#parseImportmapJson();
    return this.pathsByModule;
  }
  #parseImportmapJson() {
    const importmapScript = this.document.querySelector(
      "script[type=importmap]"
    );
    return JSON.parse(importmapScript.text).imports;
  }
  async #reloadStimulusController(moduleName) {
    log(`	${moduleName}`);
    const controllerName = this.#extractControllerName(moduleName);
    const path = cacheBustedUrl(this.#pathForModuleName(moduleName));
    const module = await import(path);
    this.#registerController(controllerName, module);
  }
  #unloadDeletedStimulusControllers() {
    this.#controllersToUnload.forEach(
      (controller) => this.#deregisterController(controller.identifier)
    );
  }
  get #controllersToUnload() {
    if (this.#didChangeTriggerAReload) {
      return [];
    } else {
      return this.application.controllers.filter(
        (controller) => this.#changedControllerIdentifier === controller.identifier
      );
    }
  }
  get #didChangeTriggerAReload() {
    return this.#stimulusControllerPathsToReload.length > 0;
  }
  #pathForModuleName(moduleName) {
    return this.#stimulusPathsByModule[moduleName];
  }
  #extractControllerName(path) {
    return path.replace(/^\/+/, "").replace(/^controllers\//, "").replace("_controller", "").replace(/\//g, "--").replace(/_/g, "-").replace(/\.js$/, "");
  }
  #registerController(name, module) {
    this.application.unload(name);
    this.application.register(name, module.default);
  }
  #deregisterController(name) {
    log(`	Removing controller ${name}`);
    this.application.unload(name);
  }
};

// js/reloaders/morph_html_reloader.js
var MorphHtmlReloader = class {
  static async reload() {
    return new MorphHtmlReloader().reload();
  }
  async reload() {
    await this.#reloadHtml();
    await this.#reloadStimulus();
  }
  async #reloadHtml() {
    log("Reload html with morph...");
    const reloadedDocument = await reloadHtmlDocument();
    this.#updateBody(reloadedDocument.body);
    return reloadedDocument;
  }
  #updateBody(newBody) {
    Idiomorph.morph(document.body, newBody);
  }
  async #reloadStimulus() {
    await StimulusReloader.reloadAll();
  }
};

// js/reloaders/css_reloader.js
var CssReloader = class {
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
      reloadedDocument.head.querySelectorAll("link[rel='stylesheet']")
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
      const newLink = this.#findExistingLinkFor(link) || this.#appendNewLink(link);
      newLink.setAttribute("href", cacheBustedUrl(link.getAttribute("href")));
      newLink.onload = () => {
        log(`	${href}`);
        resolve();
      };
    });
  }
  #findExistingLinkFor(link) {
    return this.#cssLinks.find(
      (newLink) => pathWithoutAssetDigest(link.href) === pathWithoutAssetDigest(newLink.href)
    );
  }
  get #cssLinks() {
    return Array.from(document.querySelectorAll("link[rel='stylesheet']"));
  }
  #appendNewLink(link) {
    document.head.append(link);
    return link;
  }
};

// js/channels/index.js
var ServerSentEventsChannel = class {
  static async start() {
    const sse = new EventSource("/hotwired-laravel-hotreload/sse");
    sse.addEventListener(
      "tick",
      () => {
        document.body.setAttribute("data-hotwire-hotreload-ready", "true");
      },
      { once: true }
    );
    sse.addEventListener("reload_html", (event) => {
      const data = JSON.parse(event.data);
      const reloader = HotwireHotreload.config.htmlReloadMethod === "morph" ? MorphHtmlReloader : ReplaceHtmlReloader;
      return reloader.reload(data.path);
    });
    sse.addEventListener("reload_stimulus", (event) => {
      if (window.Stimulus !== void 0) {
        const data = JSON.parse(event.data);
        return StimulusReloader.reload(data.path);
      }
    });
    sse.addEventListener("reload_css", (event) => {
      const data = JSON.parse(event.data);
      return CssReloader.reload(new RegExp(assetNameFromPath(data.path)));
    });
  }
};
ServerSentEventsChannel.start();

// js/index.js
var HotwireHotreload2 = {
  config: config_default
};
window.HotwireHotreload = HotwireHotreload2;
//# sourceMappingURL=hotreload.esm.js.map
