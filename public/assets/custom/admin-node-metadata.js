(() => {
  "use strict";

  if (window.__XBOARD_NODE_ADMIN_METADATA_INSTALLED__) {
    return;
  }
  window.__XBOARD_NODE_ADMIN_METADATA_INSTALLED__ = true;

  const state = {
    nodesById: new Map(),
    pendingNodeId: null,
    scanFrame: 0,
    decorateFrame: 0,
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
    if (pageText.includes("节点管理") || pageText.includes("节点标签")) return "zh";

    return "en";
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
    trafficLimitLabels: ["流量限制", "流控", "Traffic Limit", "Traffic limit"],
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
    trafficLimitLabels: ["Traffic Limit", "Traffic limit", "流量限制", "流控"],
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

  function visibleElement(selector) {
    return [...document.querySelectorAll(selector)].find(isVisible) || null;
  }

  function collectMetadataValues() {
    const resetInput = visibleElement('[data-field="traffic-reset-day"]');
    const remarkInput = visibleElement('[data-field="remark"]');
    if (!resetInput || !remarkInput) return null;

    const resetDay = Number.parseInt(resetInput.value || "", 10);
    const remark = String(remarkInput.value || "").trim();

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

    if (typeof body === "object") return mergeObjectMetadata(body);
    return body;
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
            // Leave unusual Request bodies untouched.
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

  function labelMatches(text, variants) {
    const normalized = normalizeText(text);
    return variants.some((variant) => {
      const target = normalizeText(variant);
      return normalized === target ||
        normalized.startsWith(`${target} `) ||
        normalized.startsWith(`${target}(`) ||
        normalized.startsWith(`${target}（`);
    });
  }

  function findLabelElement(root, variants) {
    const elements = root.querySelectorAll("label, [data-slot='label'], span, p");
    for (const element of elements) {
      if (!labelMatches(element.textContent, variants)) continue;

      const childOwnsLabel = [...element.children].some((child) =>
        labelMatches(child.textContent, variants)
      );
      if (!childOwnsLabel || element.tagName === "LABEL") return element;
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

  function findFieldContainer(label, editor, sameLevelParent = null) {
    if (!label) return null;

    if (sameLevelParent) {
      let current = label;
      for (let depth = 0; depth < 12 && current?.parentElement; depth += 1) {
        if (current.parentElement === sameLevelParent) return current;
        if (current === editor) break;
        current = current.parentElement;
      }
    }

    let current = label;
    for (let depth = 0; depth < 8 && current?.parentElement && current !== editor; depth += 1) {
      current = current.parentElement;
      const controls = current.querySelectorAll("input, textarea, button, [role='combobox']").length;
      const labels = current.querySelectorAll("label, [data-slot='label']").length;
      if (controls >= 1 && controls <= 8 && labels <= 4) return current;
    }

    return label.parentElement;
  }

  function setLabelText(root, variants, text) {
    const label = findLabelElement(root, variants) || root.querySelector("label, [data-slot='label']");
    if (!label) return;

    const textNode = [...label.childNodes].find((node) =>
      node.nodeType === Node.TEXT_NODE && normalizeText(node.nodeValue)
    );
    if (textNode) {
      textNode.nodeValue = text;
    } else {
      label.prepend(document.createTextNode(text));
    }
    label.removeAttribute("for");
  }

  function sanitizeNativeField(field) {
    field.querySelectorAll("[id], [name], [for], [aria-describedby], [aria-controls]").forEach((element) => {
      element.removeAttribute("id");
      element.removeAttribute("name");
      element.removeAttribute("for");
      element.removeAttribute("aria-describedby");
      element.removeAttribute("aria-controls");
    });
    field.querySelectorAll("[role='alert']").forEach((element) => element.remove());
  }

  function cloneNativeField(templateField, labelVariants, labelText, kind, node) {
    if (!templateField) return null;

    const field = templateField.cloneNode(true);
    sanitizeNativeField(field);
    setLabelText(field, labelVariants, labelText);

    const input = field.querySelector("input, textarea");
    if (!input) return null;

    input.disabled = false;
    input.readOnly = false;
    input.removeAttribute("value");
    input.removeAttribute("aria-invalid");

    if (kind === "reset") {
      field.dataset.xboardNodeAdminResetField = "1";
      input.dataset.field = "traffic-reset-day";
      input.type = "number";
      input.min = "1";
      input.max = "31";
      input.step = "1";
      input.inputMode = "numeric";
      input.placeholder = copy.resetDayPlaceholder;
      input.value = node?.traffic_reset_day ? String(node.traffic_reset_day) : "";
    } else {
      field.dataset.xboardNodeAdminRemarkField = "1";
      input.dataset.field = "remark";
      input.type = "text";
      input.maxLength = 2000;
      input.placeholder = copy.remarkPlaceholder;
      input.value = node?.remark || "";
    }

    field.dataset.nodeId = node?.id !== undefined ? String(node.id) : "";
    field.dataset.dirty = "0";
    field.addEventListener("input", () => { field.dataset.dirty = "1"; });
    field.addEventListener("change", () => { field.dataset.dirty = "1"; });
    return field;
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
      return ![...element.children].some((child) => labelMatches(child.textContent, copy.tagLabels));
    });
  }

  function hydrateNativeFields(resetField, remarkField, node) {
    const expectedId = node?.id !== undefined ? String(node.id) : "";

    if (resetField && resetField.dataset.dirty !== "1" && resetField.dataset.nodeId !== expectedId) {
      resetField.dataset.nodeId = expectedId;
      const input = resetField.querySelector('[data-field="traffic-reset-day"]');
      if (input) input.value = node?.traffic_reset_day ? String(node.traffic_reset_day) : "";
    }

    if (remarkField && remarkField.dataset.dirty !== "1" && remarkField.dataset.nodeId !== expectedId) {
      remarkField.dataset.nodeId = expectedId;
      const input = remarkField.querySelector('[data-field="remark"]');
      if (input) input.value = node?.remark || "";
    }
  }

  function injectMetadataFields() {
    for (const tagLabel of findVisibleTagLabels()) {
      const editor = findEditorRoot(tagLabel);
      if (!editor) continue;

      const tagField = findFieldContainer(tagLabel, editor);
      const parent = tagField?.parentElement;
      if (!tagField || !parent) continue;

      const node = findNodeForEditor(editor);
      let resetField = editor.querySelector("[data-xboard-node-admin-reset-field]");
      let remarkField = editor.querySelector("[data-xboard-node-admin-remark-field]");

      if (!resetField) {
        const trafficLabel = findLabelElement(editor, copy.trafficLimitLabels);
        const trafficTemplate = findFieldContainer(trafficLabel, editor, parent) ||
          findFieldContainer(trafficLabel, editor);
        resetField = cloneNativeField(
          trafficTemplate,
          copy.trafficLimitLabels,
          copy.resetDayLabel,
          "reset",
          node
        );
      }

      if (!remarkField) {
        const hostLabel = findLabelElement(editor, copy.hostLabels);
        const hostTemplate = findFieldContainer(hostLabel, editor, parent) ||
          findFieldContainer(hostLabel, editor);
        remarkField = cloneNativeField(
          hostTemplate,
          copy.hostLabels,
          copy.remarkLabel,
          "remark",
          node
        );
      }

      if (!resetField || !remarkField) continue;

      // Reinsert on every pass. React may reorder unknown DOM nodes during a
      // controlled-form render; this keeps reset day and remark directly above
      // the native Node Tags field in a stable order.
      parent.insertBefore(resetField, tagField);
      parent.insertBefore(remarkField, tagField);
      hydrateNativeFields(resetField, remarkField, node);
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

  function findNodeTitleRow(cell, nodeName) {
    const nameElement = findDeepestExactTextElement(cell, nodeName);
    if (!nameElement) return null;

    let current = nameElement;
    let candidate = nameElement;

    for (let depth = 0; depth < 6 && current?.parentElement && current.parentElement !== cell; depth += 1) {
      const parent = current.parentElement;
      const style = getComputedStyle(parent);
      const display = style.display;
      const direction = style.flexDirection;
      const parentText = normalizeText(parent.textContent);

      if ((display === "flex" || display === "inline-flex") &&
          !direction.startsWith("column") && parentText.includes(nodeName)) {
        candidate = parent;
        break;
      }

      if (parentText === nodeName) {
        candidate = parent;
        current = parent;
        continue;
      }
      break;
    }

    return candidate;
  }

  function metadataParts(node) {
    const limit = Number(node?.transfer_enable || 0) > 0
      ? `${formatGb(node.transfer_enable)} GB`
      : copy.unlimited;
    const resetDay = node?.traffic_reset_day
      ? copy.everyMonthDay(node.traffic_reset_day)
      : copy.notSet;
    const remark = normalizeText(node?.remark || "") || copy.notSet;

    return [
      [copy.monthLimit, limit],
      [copy.resetDay, resetDay],
      [copy.remark, remark],
    ];
  }

  function renderNodeMetadata(meta, node) {
    const parts = metadataParts(node);
    const fragment = document.createDocumentFragment();

    parts.forEach(([label, value], index) => {
      if (index > 0) {
        const separator = document.createElement("span");
        separator.className = "xboard-node-admin-row-separator";
        separator.textContent = "·";
        fragment.append(separator);
      }

      const item = document.createElement("span");
      item.className = "xboard-node-admin-row-item";
      if (label === copy.remark) item.classList.add("xboard-node-admin-row-remark");

      const labelElement = document.createElement("span");
      labelElement.className = "xboard-node-admin-row-label";
      labelElement.textContent = label;

      const valueElement = document.createElement("span");
      valueElement.className = "xboard-node-admin-row-value";
      valueElement.textContent = value;

      item.append(labelElement, valueElement);
      fragment.append(item);
    });

    meta.replaceChildren(fragment);
    meta.title = parts.map(([label, value]) => `${label}: ${value}`).join("\n");
  }

  function decorateNodeRows() {
    if (state.nodesById.size === 0) return;

    for (const row of document.querySelectorAll("tr, [role='row']")) {
      if (!isVisible(row)) continue;

      const node = findNodeForRow(row);
      if (!node) continue;

      const name = normalizeText(node.name);
      const cells = [...row.querySelectorAll("td, [role='cell']")];
      const cell = cells.find((candidate) =>
        findDeepestExactTextElement(candidate, name)
      );
      if (!cell) continue;

      const titleRow = findNodeTitleRow(cell, name);
      if (!titleRow?.parentElement) continue;

      let meta = row.querySelector(`[data-xboard-node-admin-row-meta="${CSS.escape(String(node.id))}"]`);
      if (!meta) {
        meta = document.createElement("div");
        meta.dataset.xboardNodeAdminRowMeta = String(node.id);
        meta.className = "text-xs text-muted-foreground xboard-node-admin-row-meta";
      }

      renderNodeMetadata(meta, node);

      // Always move it after the native node-title row. React can move foreign
      // children during reconciliation; order: max plus reinsertion prevents the
      // metadata from jumping above the node name after refreshes.
      titleRow.insertAdjacentElement("afterend", meta);
    }
  }

  function scheduleScan() {
    cancelAnimationFrame(state.scanFrame);
    state.scanFrame = requestAnimationFrame(injectMetadataFields);
  }

  function scheduleDecorate() {
    cancelAnimationFrame(state.decorateFrame);
    state.decorateFrame = requestAnimationFrame(decorateNodeRows);
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
      [data-xboard-node-admin-reset-field],
      [data-xboard-node-admin-remark-field] {
        min-width: 0;
      }
      [data-field="traffic-reset-day"],
      [data-field="remark"] {
        font: inherit;
      }
      .xboard-node-admin-row-meta {
        order: 2147483647 !important;
        display: flex;
        align-items: center;
        align-self: stretch;
        min-width: 0;
        width: 100%;
        max-width: 100%;
        margin-top: 0.25rem;
        overflow: hidden;
        color: hsl(var(--muted-foreground));
        font-family: inherit;
        font-size: 0.75rem;
        font-weight: 400;
        line-height: 1rem;
        white-space: nowrap;
      }
      .xboard-node-admin-row-item {
        display: inline-flex;
        min-width: 0;
        gap: 0.2rem;
      }
      .xboard-node-admin-row-label {
        flex: none;
        opacity: 0.72;
      }
      .xboard-node-admin-row-value {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .xboard-node-admin-row-separator {
        flex: none;
        margin: 0 0.4rem;
        opacity: 0.45;
      }
      .xboard-node-admin-row-remark {
        min-width: 0;
        max-width: 15rem;
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
