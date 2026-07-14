(() => {
  "use strict";

  if (window.__XBOARD_NODE_ADMIN_METADATA_INSTALLED__) {
    return;
  }
  window.__XBOARD_NODE_ADMIN_METADATA_INSTALLED__ = true;

  const state = {
    nodesById: new Map(),
    pendingNodeId: null,
    scanTimer: null,
    decorateTimer: null,
  };

  const locale = (() => {
    const stored = String(
      localStorage.getItem("i18nextLng") ||
      localStorage.getItem("language") ||
      ""
    ).toLowerCase();
    if (stored.startsWith("zh")) return "zh";
    if (stored.startsWith("en")) return "en";

    const browser = String(navigator.language || "").toLowerCase();
    if (browser.startsWith("zh")) return "zh";

    const pageText = String(document.body?.textContent || "");
    return pageText.includes("节点") ? "zh" : "en";
  })();

  const copy = locale === "zh" ? {
    resetDayLabel: "每月重置日",
    resetDayPlaceholder: "1-31，留空不自动重置",
    remarkLabel: "节点备注",
    remarkPlaceholder: "仅管理员可见，不会进入用户订阅",
    unlimited: "不限",
    notSet: "未设置",
    monthLimit: "月限额",
    resetDay: "重置日",
    remark: "备注",
    everyMonthDay: (day) => `每月 ${day} 日`,
    tagLabels: ["节点标签", "Node Tags", "Tags"],
    nodeNameLabels: ["节点名称", "Node Name", "Name"],
    hostLabels: ["节点地址", "Node Address", "Address", "Host"],
    trafficLabels: ["流量限制", "流控", "Traffic Limit", "Traffic limit"],
    addWords: ["添加节点", "新建节点", "Add Node", "New Node"],
  } : {
    resetDayLabel: "Monthly reset day",
    resetDayPlaceholder: "1-31; blank disables reset",
    remarkLabel: "Node remark",
    remarkPlaceholder: "Administrator-only; never included in subscriptions",
    unlimited: "Unlimited",
    notSet: "Not set",
    monthLimit: "Monthly limit",
    resetDay: "Reset day",
    remark: "Remark",
    everyMonthDay: (day) => `Day ${day} monthly`,
    tagLabels: ["Node Tags", "Tags", "节点标签"],
    nodeNameLabels: ["Node Name", "Name", "节点名称"],
    hostLabels: ["Node Address", "Address", "Host", "节点地址"],
    trafficLabels: ["Traffic Limit", "Traffic limit", "流量限制", "流控"],
    addWords: ["Add Node", "New Node", "添加节点", "新建节点"],
  };

  function normalizeText(value) {
    return String(value || "").replace(/\s+/g, " ").trim();
  }

  function isVisible(element) {
    return Boolean(
      element &&
      element.isConnected &&
      element.getClientRects().length &&
      getComputedStyle(element).visibility !== "hidden"
    );
  }

  function isManagedUrl(url, action) {
    return String(url || "").includes(`/server/manage/${action}`);
  }

  function pickNodeList(payload) {
    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;
    if (Array.isArray(payload?.data?.data)) return payload.data.data;
    return null;
  }

  function captureNodes(payload) {
    const nodes = pickNodeList(payload);
    if (!nodes) return;

    state.nodesById.clear();
    for (const node of nodes) {
      if (node && node.id !== undefined && node.id !== null) {
        state.nodesById.set(String(node.id), node);
      }
    }

    scheduleScan();
    scheduleDecorate();
  }

  function getVisibleMetadataSection() {
    return [...document.querySelectorAll("[data-xboard-node-admin-fields]")]
      .find(isVisible) || null;
  }

  function collectMetadataValues() {
    const section = getVisibleMetadataSection();
    if (!section) return null;

    const resetInput = section.querySelector('[data-field="traffic-reset-day"]');
    const remarkInput = section.querySelector('[data-field="remark"]');
    const resetDay = Number.parseInt(resetInput?.value || "", 10);
    const remark = String(remarkInput?.value || "").trim();

    return {
      traffic_reset_day: Number.isInteger(resetDay) && resetDay >= 1 && resetDay <= 31
        ? resetDay
        : null,
      remark: remark || null,
    };
  }

  function mergeObjectMetadata(payload) {
    const metadata = collectMetadataValues();
    if (!metadata || !payload || typeof payload !== "object") return payload;
    return Object.assign(payload, metadata);
  }

  function mergeRequestBody(body) {
    const metadata = collectMetadataValues();
    if (!metadata || body === undefined || body === null) return body;

    if (body instanceof FormData) {
      body.set("traffic_reset_day", metadata.traffic_reset_day === null ? "" : String(metadata.traffic_reset_day));
      body.set("remark", metadata.remark || "");
      return body;
    }

    if (body instanceof URLSearchParams) {
      body.set("traffic_reset_day", metadata.traffic_reset_day === null ? "" : String(metadata.traffic_reset_day));
      body.set("remark", metadata.remark || "");
      return body;
    }

    if (typeof body === "string") {
      try {
        return JSON.stringify(mergeObjectMetadata(JSON.parse(body)));
      } catch (_) {
        try {
          const params = new URLSearchParams(body);
          params.set("traffic_reset_day", metadata.traffic_reset_day === null ? "" : String(metadata.traffic_reset_day));
          params.set("remark", metadata.remark || "");
          return params.toString();
        } catch (_) {
          return body;
        }
      }
    }

    return typeof body === "object" ? mergeObjectMetadata(body) : body;
  }

  function installFetchInterceptor() {
    if (typeof window.fetch !== "function") return;

    const originalFetch = window.fetch.bind(window);
    window.fetch = async (input, init = undefined) => {
      let requestInput = input;
      const requestInit = init ? { ...init } : {};
      const url = typeof input === "string" || input instanceof URL
        ? String(input)
        : input?.url || "";

      if (isManagedUrl(url, "save")) {
        if (requestInit.body !== undefined) {
          requestInit.body = mergeRequestBody(requestInit.body);
        } else if (input instanceof Request && !["GET", "HEAD"].includes(input.method.toUpperCase())) {
          try {
            const body = await input.clone().text();
            requestInput = new Request(input, { body: mergeRequestBody(body) });
          } catch (_) {
            // Do not interfere with unusual Request bodies.
          }
        }
      }

      const response = await originalFetch(requestInput, requestInit);
      if (isManagedUrl(url, "getNodes")) {
        response.clone().json().then(captureNodes).catch(() => {});
      }
      return response;
    };
  }

  function installXhrInterceptor() {
    if (typeof XMLHttpRequest === "undefined") return;

    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
      this.__xboardAdminUrl = String(url || "");
      return originalOpen.call(this, method, url, ...rest);
    };

    XMLHttpRequest.prototype.send = function (body) {
      const url = this.__xboardAdminUrl || "";
      let nextBody = body;

      if (isManagedUrl(url, "save")) {
        nextBody = mergeRequestBody(body);
      }

      if (isManagedUrl(url, "getNodes")) {
        this.addEventListener("load", () => {
          try {
            captureNodes(JSON.parse(this.responseText));
          } catch (_) {
            // Ignore non-JSON responses.
          }
        }, { once: true });
      }

      return originalSend.call(this, nextBody);
    };
  }

  function labelMatches(value, variants) {
    const text = normalizeText(value);
    return variants.some((variant) => {
      const target = normalizeText(variant);
      return text === target ||
        text.startsWith(`${target} `) ||
        text.startsWith(`${target}(`) ||
        text.startsWith(`${target}（`);
    });
  }

  function findLabelElement(root, variants) {
    const elements = root.querySelectorAll("label, [data-slot='label'], span, p");
    for (const element of elements) {
      if (!labelMatches(element.textContent, variants)) continue;

      const childOwnsText = [...element.children].some((child) =>
        labelMatches(child.textContent, variants)
      );
      if (!childOwnsText || element.tagName === "LABEL") return element;
    }
    return null;
  }

  function findInputByLabel(root, variants) {
    const label = findLabelElement(root, variants);
    if (!label) return null;

    if (label.htmlFor) {
      const target = root.querySelector(`#${CSS.escape(label.htmlFor)}`);
      if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
        return target;
      }
    }

    let current = label;
    for (let depth = 0; depth < 6 && current && current !== root; depth += 1) {
      const target = current.querySelector("input, textarea");
      if (target) return target;
      current = current.parentElement;
    }
    return null;
  }

  function findFieldContainer(label, editor) {
    if (!label) return null;
    let current = label;

    for (let depth = 0; depth < 7 && current?.parentElement && current !== editor; depth += 1) {
      current = current.parentElement;
      const controls = current.querySelectorAll("input, textarea, button, [role='combobox']").length;
      const labels = current.querySelectorAll("label, [data-slot='label']").length;
      if (controls >= 1 && controls <= 6 && labels <= 3) return current;
    }

    return label.parentElement;
  }

  function findEditorRoot(tagLabel) {
    let current = tagLabel.parentElement;
    let fallback = null;

    for (let depth = 0; depth < 18 && current; depth += 1) {
      const controls = current.querySelectorAll("input, textarea, select, [role='combobox']").length;
      const hasNodeName = Boolean(findLabelElement(current, copy.nodeNameLabels));
      const text = normalizeText(current.textContent);
      const hasHeading = [
        "编辑节点", "添加节点", "新建节点",
        "Edit Node", "Add Node", "New Node",
      ].some((word) => text.includes(word));

      if (controls >= 2 && (hasNodeName || hasHeading)) {
        fallback = current;
        if (current.matches("[role='dialog'], form, [data-state='open']")) return current;
      }
      current = current.parentElement;
    }

    return fallback;
  }

  function findVisibleTagLabels() {
    const elements = document.querySelectorAll("label, [data-slot='label'], span, p");
    return [...elements].filter((element) => {
      if (!isVisible(element) || !labelMatches(element.textContent, copy.tagLabels)) return false;
      return ![...element.children].some((child) =>
        labelMatches(child.textContent, copy.tagLabels)
      );
    });
  }

  function isAddEditor(editor) {
    const heading = normalizeText(
      editor.querySelector("h1, h2, h3, [role='heading']")?.textContent || ""
    );
    return copy.addWords.some((word) => heading.includes(word));
  }

  function findNodeForEditor(editor) {
    if (isAddEditor(editor)) return null;

    if (state.pendingNodeId && state.nodesById.has(String(state.pendingNodeId))) {
      return state.nodesById.get(String(state.pendingNodeId));
    }

    const name = normalizeText(findInputByLabel(editor, copy.nodeNameLabels)?.value || "");
    const host = normalizeText(findInputByLabel(editor, copy.hostLabels)?.value || "");
    const nodes = [...state.nodesById.values()];

    if (name && host) {
      const exact = nodes.find((node) =>
        normalizeText(node.name) === name && normalizeText(node.host) === host
      );
      if (exact) return exact;
    }

    return name
      ? nodes.find((node) => normalizeText(node.name) === name) || null
      : null;
  }

  function copyComputedControlStyle(source, target) {
    if (!source || !target) return;

    target.className = source.className || "";
    const style = getComputedStyle(source);
    const properties = [
      "boxSizing", "width", "height", "minHeight", "paddingTop", "paddingRight",
      "paddingBottom", "paddingLeft", "borderTopWidth", "borderRightWidth",
      "borderBottomWidth", "borderLeftWidth", "borderTopStyle", "borderRightStyle",
      "borderBottomStyle", "borderLeftStyle", "borderTopColor", "borderRightColor",
      "borderBottomColor", "borderLeftColor", "borderRadius", "backgroundColor",
      "color", "fontFamily", "fontSize", "fontWeight", "lineHeight", "letterSpacing",
      "boxShadow", "outline", "transition",
    ];

    for (const property of properties) {
      target.style[property] = style[property];
    }
    target.style.pointerEvents = "auto";
    target.style.position = "relative";
    target.style.zIndex = "0";
  }

  function copyComputedLabelStyle(source, target) {
    if (!source || !target) return;

    target.className = source.className || "";
    const style = getComputedStyle(source);
    for (const property of [
      "color", "fontFamily", "fontSize", "fontWeight", "lineHeight", "letterSpacing",
      "display", "marginBottom",
    ]) {
      target.style[property] = style[property];
    }
  }

  function createSafeField(labelText, input, sourceLabel, sourceInput) {
    const field = document.createElement("div");
    field.className = "xboard-node-admin-field";

    const label = document.createElement("label");
    label.className = "xboard-node-admin-label";
    label.textContent = labelText;

    copyComputedLabelStyle(sourceLabel, label);
    copyComputedControlStyle(sourceInput, input);

    field.append(label, input);
    return field;
  }

  function createMetadataSection(editor, node) {
    const section = document.createElement("div");
    section.dataset.xboardNodeAdminFields = "1";
    section.dataset.nodeId = node?.id !== undefined ? String(node.id) : "";
    section.dataset.dirty = "0";
    section.className = "xboard-node-admin-section";

    const trafficLabel = findLabelElement(editor, copy.trafficLabels);
    const trafficInput = findInputByLabel(editor, copy.trafficLabels);
    const hostLabel = findLabelElement(editor, copy.hostLabels);
    const hostInput = findInputByLabel(editor, copy.hostLabels);

    const resetInput = document.createElement("input");
    resetInput.type = "number";
    resetInput.min = "1";
    resetInput.max = "31";
    resetInput.step = "1";
    resetInput.inputMode = "numeric";
    resetInput.placeholder = copy.resetDayPlaceholder;
    resetInput.value = node?.traffic_reset_day ? String(node.traffic_reset_day) : "";
    resetInput.dataset.field = "traffic-reset-day";
    resetInput.autocomplete = "off";

    const remarkInput = document.createElement("input");
    remarkInput.type = "text";
    remarkInput.maxLength = 2000;
    remarkInput.placeholder = copy.remarkPlaceholder;
    remarkInput.value = node?.remark || "";
    remarkInput.dataset.field = "remark";
    remarkInput.autocomplete = "off";

    const resetField = createSafeField(
      copy.resetDayLabel,
      resetInput,
      trafficLabel,
      trafficInput
    );
    const remarkField = createSafeField(
      copy.remarkLabel,
      remarkInput,
      hostLabel,
      hostInput
    );

    section.append(resetField, remarkField);
    section.addEventListener("input", () => { section.dataset.dirty = "1"; });
    section.addEventListener("change", () => { section.dataset.dirty = "1"; });
    return section;
  }

  function hydrateSection(section, node) {
    if (!section || section.dataset.dirty === "1") return;

    const expectedId = node?.id !== undefined ? String(node.id) : "";
    if (section.dataset.nodeId === expectedId) return;

    section.dataset.nodeId = expectedId;
    const resetInput = section.querySelector('[data-field="traffic-reset-day"]');
    const remarkInput = section.querySelector('[data-field="remark"]');
    if (resetInput) resetInput.value = node?.traffic_reset_day ? String(node.traffic_reset_day) : "";
    if (remarkInput) remarkInput.value = node?.remark || "";
  }

  function injectMetadataFields() {
    for (const tagLabel of findVisibleTagLabels()) {
      const editor = findEditorRoot(tagLabel);
      if (!editor) continue;

      const tagField = findFieldContainer(tagLabel, editor);
      if (!tagField?.parentElement) continue;

      const node = findNodeForEditor(editor);
      let section = editor.querySelector("[data-xboard-node-admin-fields]");
      if (!section) {
        section = createMetadataSection(editor, node);
      }

      // Keep the custom fields immediately above Node Tags without touching,
      // cloning, wrapping, or overlaying any native React form control.
      tagField.parentElement.insertBefore(section, tagField);
      hydrateSection(section, node);
    }
  }

  function formatGb(bytes) {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) return "0";
    const gb = value / (1024 ** 3);
    return Number.isInteger(gb) ? String(gb) : String(Number(gb.toFixed(3)));
  }

  function findNodeForRow(row) {
    const text = normalizeText(row.textContent);
    if (!text) return null;

    const nodes = [...state.nodesById.values()]
      .sort((a, b) => normalizeText(b.name).length - normalizeText(a.name).length);
    const matches = nodes.filter((node) => text.includes(normalizeText(node.name)));
    if (matches.length === 1) return matches[0];

    if (matches.length > 1) {
      return matches.find((node) => {
        const id = String(node.id);
        return new RegExp(`(^|\\D)${id}(\\D|$)`).test(text);
      }) || matches[0];
    }

    return null;
  }

  function findDeepestExactTextElement(root, text) {
    const matches = [...root.querySelectorAll("span, p, a, div")]
      .filter((element) => isVisible(element) && normalizeText(element.textContent) === text);

    return matches.find((element) =>
      ![...element.children].some((child) => normalizeText(child.textContent) === text)
    ) || matches.at(-1) || null;
  }

  function findStableNodeColumn(cell, nodeName) {
    const nameElement = findDeepestExactTextElement(cell, nodeName);
    if (!nameElement) return null;

    let current = nameElement.parentElement;
    let fallback = nameElement.parentElement;

    for (let depth = 0; depth < 7 && current && current !== cell; depth += 1) {
      const style = getComputedStyle(current);
      if ((style.display === "flex" || style.display === "inline-flex") &&
          style.flexDirection.startsWith("column")) {
        return current;
      }
      fallback = current;
      current = current.parentElement;
    }

    return fallback;
  }

  function metadataText(node) {
    const limit = Number(node?.transfer_enable || 0) > 0
      ? `${formatGb(node.transfer_enable)} GB`
      : copy.unlimited;
    const day = node?.traffic_reset_day
      ? copy.everyMonthDay(node.traffic_reset_day)
      : copy.notSet;
    const remark = normalizeText(node?.remark || "") || copy.notSet;

    return `${copy.monthLimit} ${limit} · ${copy.resetDay} ${day} · ${copy.remark} ${remark}`;
  }

  function decorateNodeRows() {
    if (state.nodesById.size === 0) return;

    for (const row of document.querySelectorAll("tr, [role='row']")) {
      if (!isVisible(row)) continue;

      const node = findNodeForRow(row);
      if (!node) continue;

      const name = normalizeText(node.name);
      const cells = [...row.querySelectorAll("td, [role='cell']")];
      const cell = cells.find((candidate) => findDeepestExactTextElement(candidate, name));
      if (!cell) continue;

      const column = findStableNodeColumn(cell, name);
      if (!column) continue;

      let meta = row.querySelector(`[data-xboard-node-admin-row-meta="${CSS.escape(String(node.id))}"]`);
      if (!meta) {
        meta = document.createElement("div");
        meta.dataset.xboardNodeAdminRowMeta = String(node.id);
        meta.className = "text-xs text-muted-foreground xboard-node-admin-row-meta";
      }

      const text = metadataText(node);
      if (meta.textContent !== text) meta.textContent = text;
      meta.title = text;

      // React may reorder foreign nodes. Appending on every reconciliation and
      // forcing the largest flex order keeps this line below the node title.
      column.append(meta);
    }
  }

  function scheduleScan() {
    clearTimeout(state.scanTimer);
    state.scanTimer = setTimeout(injectMetadataFields, 40);
  }

  function scheduleDecorate() {
    clearTimeout(state.decorateTimer);
    state.decorateTimer = setTimeout(decorateNodeRows, 60);
  }

  function installClickTracking() {
    document.addEventListener("pointerdown", (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;

      const buttonText = normalizeText(target.closest("button")?.textContent || "");
      if (copy.addWords.some((word) => buttonText.includes(word))) {
        state.pendingNodeId = null;
        return;
      }

      const row = target.closest("tr, [role='row']");
      if (!row) return;
      const node = findNodeForRow(row);
      if (node) state.pendingNodeId = String(node.id);
    }, true);
  }

  function installStyles() {
    const style = document.createElement("style");
    style.textContent = `
      .xboard-node-admin-section {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        width: 100%;
        min-width: 0;
        margin: 0;
        padding: 0;
        position: relative;
        z-index: 0;
        pointer-events: auto;
      }
      .xboard-node-admin-field {
        display: flex;
        min-width: 0;
        flex-direction: column;
        gap: 0.5rem;
        position: relative;
        z-index: 0;
        pointer-events: auto;
      }
      .xboard-node-admin-label {
        pointer-events: auto;
      }
      [data-field="traffic-reset-day"],
      [data-field="remark"] {
        display: block;
        width: 100%;
        pointer-events: auto !important;
        position: relative !important;
        z-index: 0 !important;
      }
      .xboard-node-admin-row-meta {
        order: 2147483647 !important;
        display: block;
        width: 100%;
        min-width: 0;
        margin-top: 0.25rem;
        overflow: hidden;
        color: hsl(var(--muted-foreground));
        font-family: inherit;
        font-size: 0.75rem;
        font-weight: 400;
        line-height: 1rem;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      @media (max-width: 720px) {
        .xboard-node-admin-section {
          grid-template-columns: 1fr;
        }
      }
    `;
    document.head.append(style);
  }

  function startObserver() {
    const observer = new MutationObserver(() => {
      scheduleScan();
      scheduleDecorate();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    scheduleScan();
    scheduleDecorate();
  }

  installFetchInterceptor();
  installXhrInterceptor();
  installStyles();
  installClickTracking();

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startObserver, { once: true });
  } else {
    startObserver();
  }
})();
