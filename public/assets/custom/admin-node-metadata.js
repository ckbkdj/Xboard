(() => {
  "use strict";

  if (window.__XBOARD_NODE_ADMIN_METADATA_INSTALLED__) {
    return;
  }
  window.__XBOARD_NODE_ADMIN_METADATA_INSTALLED__ = true;

  const GIB = 1024 ** 3;
  const state = {
    nodesById: new Map(),
    pendingNodeId: null,
    scanTimer: null,
    decorateTimer: null,
  };

  const locale = (() => {
    const raw = String(
      document.documentElement.lang ||
      localStorage.getItem("i18nextLng") ||
      navigator.language ||
      "zh-CN"
    ).toLowerCase();
    return raw.startsWith("zh") ? "zh" : "en";
  })();

  const copy = locale === "zh" ? {
    sectionTitle: "管理员节点信息",
    limitLabel: "月流量限制",
    limitPlaceholder: "0 表示不限制",
    limitHint: "单位 GB；达到上限后节点会停止分发，重置日到达后自动清零。",
    resetDayLabel: "每月重置日",
    resetDayPlaceholder: "不自动重置",
    resetDayHint: "可选 1–31；当月没有该日期时按当月最后一天执行。",
    remarkLabel: "节点备注",
    remarkPlaceholder: "仅管理员可见，不会进入用户订阅。",
    unlimited: "不限",
    notSet: "未设置",
    monthLimit: "月限额",
    resetDay: "重置日",
    everyMonthDay: (day) => `每月 ${day} 日`,
    remark: "备注",
    used: "已用",
    tagLabels: ["节点标签"],
    nodeNameLabels: ["节点名称"],
    hostLabels: ["节点地址"],
    addWords: ["添加节点", "新建节点"],
  } : {
    sectionTitle: "Administrator node metadata",
    limitLabel: "Monthly traffic limit",
    limitPlaceholder: "0 means unlimited",
    limitHint: "GB. The node stops being distributed after reaching the limit and is cleared on its reset day.",
    resetDayLabel: "Monthly reset day",
    resetDayPlaceholder: "No automatic reset",
    resetDayHint: "Choose 1–31. Months without that date use their final day.",
    remarkLabel: "Node remark",
    remarkPlaceholder: "Administrator-only. Never included in user subscriptions.",
    unlimited: "Unlimited",
    notSet: "Not set",
    monthLimit: "Monthly limit",
    resetDay: "Reset day",
    everyMonthDay: (day) => `Day ${day} monthly`,
    remark: "Remark",
    used: "Used",
    tagLabels: ["Node Tags", "Tags"],
    nodeNameLabels: ["Node Name", "Name"],
    hostLabels: ["Node Address", "Address", "Host"],
    addWords: ["Add Node", "New Node"],
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
    const value = String(url || "");
    return value.includes(`/server/manage/${action}`);
  }

  function pickNodeList(payload) {
    if (Array.isArray(payload)) {
      return payload;
    }
    if (Array.isArray(payload?.data)) {
      return payload.data;
    }
    if (Array.isArray(payload?.data?.data)) {
      return payload.data.data;
    }
    return null;
  }

  function captureNodes(payload) {
    const nodes = pickNodeList(payload);
    if (!nodes) {
      return;
    }

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
      .find((element) => isVisible(element)) || null;
  }

  function collectMetadataValues() {
    const section = getVisibleMetadataSection();
    if (!section) {
      return null;
    }

    const limitInput = section.querySelector('[data-field="transfer-limit-gb"]');
    const resetInput = section.querySelector('[data-field="traffic-reset-day"]');
    const remarkInput = section.querySelector('[data-field="remark"]');

    const limitGb = Number.parseFloat(limitInput?.value || "0");
    const resetDay = Number.parseInt(resetInput?.value || "", 10);
    const remark = normalizeText(remarkInput?.value || "");

    return {
      transfer_enable: Number.isFinite(limitGb) && limitGb > 0
        ? Math.round(limitGb * GIB)
        : 0,
      traffic_reset_day: Number.isInteger(resetDay) && resetDay >= 1 && resetDay <= 31
        ? resetDay
        : null,
      remark: remark || null,
    };
  }

  function mergeObjectMetadata(payload) {
    const metadata = collectMetadataValues();
    if (!metadata || !payload || typeof payload !== "object") {
      return payload;
    }
    return Object.assign(payload, metadata);
  }

  function mergeRequestBody(body) {
    const metadata = collectMetadataValues();
    if (!metadata || body === undefined || body === null) {
      return body;
    }

    if (body instanceof FormData) {
      body.set("transfer_enable", String(metadata.transfer_enable));
      body.set("traffic_reset_day", metadata.traffic_reset_day === null ? "" : String(metadata.traffic_reset_day));
      body.set("remark", metadata.remark || "");
      return body;
    }

    if (body instanceof URLSearchParams) {
      body.set("transfer_enable", String(metadata.transfer_enable));
      body.set("traffic_reset_day", metadata.traffic_reset_day === null ? "" : String(metadata.traffic_reset_day));
      body.set("remark", metadata.remark || "");
      return body;
    }

    if (typeof body === "string") {
      try {
        const parsed = JSON.parse(body);
        return JSON.stringify(mergeObjectMetadata(parsed));
      } catch (_) {
        try {
          const params = new URLSearchParams(body);
          params.set("transfer_enable", String(metadata.transfer_enable));
          params.set("traffic_reset_day", metadata.traffic_reset_day === null ? "" : String(metadata.traffic_reset_day));
          params.set("remark", metadata.remark || "");
          return params.toString();
        } catch (_) {
          return body;
        }
      }
    }

    if (typeof body === "object") {
      return mergeObjectMetadata(body);
    }

    return body;
  }

  function installFetchInterceptor() {
    if (typeof window.fetch !== "function") {
      return;
    }

    const originalFetch = window.fetch.bind(window);
    window.fetch = async (input, init = undefined) => {
      let requestInput = input;
      let requestInit = init ? { ...init } : {};
      const url = typeof input === "string" || input instanceof URL
        ? String(input)
        : input?.url || "";

      if (isManagedUrl(url, "save")) {
        if (requestInit.body !== undefined) {
          requestInit.body = mergeRequestBody(requestInit.body);
        } else if (input instanceof Request && !["GET", "HEAD"].includes(input.method.toUpperCase())) {
          try {
            const cloned = input.clone();
            const body = await cloned.text();
            requestInput = new Request(input, {
              body: mergeRequestBody(body),
            });
          } catch (_) {
            // XHR is the normal transport used by the admin app; leave unusual
            // Request objects untouched if their body cannot be cloned.
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
    if (typeof XMLHttpRequest === "undefined") {
      return;
    }

    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
      this.__xboardAdminMethod = method;
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

  function findLabelElement(root, variants) {
    const normalizedVariants = variants.map(normalizeText);
    const elements = root.querySelectorAll("label, [data-slot='label'], span, p, div");

    for (const element of elements) {
      const text = normalizeText(element.textContent);
      if (!text || !normalizedVariants.includes(text)) {
        continue;
      }

      const childMatch = [...element.children].some((child) =>
        normalizedVariants.includes(normalizeText(child.textContent))
      );
      if (!childMatch || element.tagName === "LABEL") {
        return element;
      }
    }

    return null;
  }

  function findInputByLabel(root, variants) {
    const label = findLabelElement(root, variants);
    if (!label) {
      return null;
    }

    if (label.htmlFor) {
      const target = root.querySelector(`#${CSS.escape(label.htmlFor)}`);
      if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
        return target;
      }
    }

    let container = label;
    for (let depth = 0; depth < 5 && container && container !== root; depth += 1) {
      const target = container.querySelector("input, textarea");
      if (target) {
        return target;
      }
      container = container.parentElement;
    }

    return null;
  }

  function findFieldContainer(label, dialog) {
    let container = label;

    for (let depth = 0; depth < 6 && container?.parentElement && container !== dialog; depth += 1) {
      container = container.parentElement;
      const controls = container.querySelectorAll("input, textarea, button, [role='combobox']").length;
      const labels = container.querySelectorAll("label, [data-slot='label']").length;
      if (controls >= 1 && controls <= 8 && labels <= 4) {
        return container;
      }
    }

    return label.parentElement || dialog;
  }

  function isAddDialog(dialog) {
    const header = normalizeText(
      dialog.querySelector("h1, h2, h3, [role='heading']")?.textContent || ""
    );
    return copy.addWords.some((word) => header.includes(word));
  }

  function findNodeForDialog(dialog) {
    if (isAddDialog(dialog)) {
      return null;
    }

    if (state.pendingNodeId && state.nodesById.has(String(state.pendingNodeId))) {
      return state.nodesById.get(String(state.pendingNodeId));
    }

    const name = normalizeText(findInputByLabel(dialog, copy.nodeNameLabels)?.value || "");
    const host = normalizeText(findInputByLabel(dialog, copy.hostLabels)?.value || "");

    const nodes = [...state.nodesById.values()];
    if (name && host) {
      const exact = nodes.find((node) =>
        normalizeText(node.name) === name && normalizeText(node.host) === host
      );
      if (exact) {
        return exact;
      }
    }

    return name
      ? nodes.find((node) => normalizeText(node.name) === name) || null
      : null;
  }

  function formatGb(bytes) {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) {
      return "0";
    }

    const gb = value / GIB;
    return Number.isInteger(gb)
      ? String(gb)
      : String(Number(gb.toFixed(3)));
  }

  function usedGb(node) {
    return formatGb(Number(node?.u || 0) + Number(node?.d || 0));
  }

  function createField(labelText, input) {
    const wrapper = document.createElement("label");
    wrapper.className = "xboard-node-admin-field";

    const label = document.createElement("span");
    label.className = "xboard-node-admin-label";
    label.textContent = labelText;

    wrapper.append(label, input);
    return wrapper;
  }

  function createMetadataSection(node) {
    const section = document.createElement("section");
    section.dataset.xboardNodeAdminFields = "1";
    section.dataset.nodeId = node?.id !== undefined ? String(node.id) : "";
    section.dataset.dirty = "0";
    section.className = "xboard-node-admin-section";

    const title = document.createElement("div");
    title.className = "xboard-node-admin-title";
    title.textContent = copy.sectionTitle;

    const grid = document.createElement("div");
    grid.className = "xboard-node-admin-grid";

    const limitInput = document.createElement("input");
    limitInput.type = "number";
    limitInput.min = "0";
    limitInput.step = "0.01";
    limitInput.placeholder = copy.limitPlaceholder;
    limitInput.value = formatGb(node?.transfer_enable);
    limitInput.dataset.field = "transfer-limit-gb";
    limitInput.className = "xboard-node-admin-input";

    const limitField = createField(`${copy.limitLabel} (GB)`, limitInput);
    const limitHint = document.createElement("small");
    limitHint.textContent = node
      ? `${copy.limitHint} ${copy.used}: ${usedGb(node)} GB`
      : copy.limitHint;
    limitField.append(limitHint);

    const resetSelect = document.createElement("select");
    resetSelect.dataset.field = "traffic-reset-day";
    resetSelect.className = "xboard-node-admin-input";

    const unsetOption = document.createElement("option");
    unsetOption.value = "";
    unsetOption.textContent = copy.resetDayPlaceholder;
    resetSelect.append(unsetOption);

    for (let day = 1; day <= 31; day += 1) {
      const option = document.createElement("option");
      option.value = String(day);
      option.textContent = copy.everyMonthDay(day);
      resetSelect.append(option);
    }
    resetSelect.value = node?.traffic_reset_day ? String(node.traffic_reset_day) : "";

    const resetField = createField(copy.resetDayLabel, resetSelect);
    const resetHint = document.createElement("small");
    resetHint.textContent = copy.resetDayHint;
    resetField.append(resetHint);

    const remarkInput = document.createElement("textarea");
    remarkInput.rows = 3;
    remarkInput.maxLength = 2000;
    remarkInput.placeholder = copy.remarkPlaceholder;
    remarkInput.value = node?.remark || "";
    remarkInput.dataset.field = "remark";
    remarkInput.className = "xboard-node-admin-input xboard-node-admin-textarea";

    const remarkField = createField(copy.remarkLabel, remarkInput);
    remarkField.classList.add("xboard-node-admin-full");

    grid.append(limitField, resetField, remarkField);
    section.append(title, grid);

    section.addEventListener("input", () => {
      section.dataset.dirty = "1";
    });
    section.addEventListener("change", () => {
      section.dataset.dirty = "1";
    });

    return section;
  }

  function hydrateSection(section, node) {
    if (section.dataset.dirty === "1") {
      return;
    }

    const expectedId = node?.id !== undefined ? String(node.id) : "";
    if (section.dataset.nodeId === expectedId) {
      return;
    }

    section.dataset.nodeId = expectedId;
    section.querySelector('[data-field="transfer-limit-gb"]').value = formatGb(node?.transfer_enable);
    section.querySelector('[data-field="traffic-reset-day"]').value =
      node?.traffic_reset_day ? String(node.traffic_reset_day) : "";
    section.querySelector('[data-field="remark"]').value = node?.remark || "";
  }

  function injectMetadataFields() {
    const dialogs = [...document.querySelectorAll("[role='dialog']")].filter(isVisible);

    for (const dialog of dialogs) {
      const tagLabel = findLabelElement(dialog, copy.tagLabels);
      if (!tagLabel) {
        continue;
      }

      const node = findNodeForDialog(dialog);
      const existing = dialog.querySelector("[data-xboard-node-admin-fields]");
      if (existing) {
        hydrateSection(existing, node);
        continue;
      }

      const anchor = findFieldContainer(tagLabel, dialog);
      const section = createMetadataSection(node);
      anchor.parentNode?.insertBefore(section, anchor);
    }
  }

  function findNodeForRow(row) {
    const text = normalizeText(row.textContent);
    if (!text) {
      return null;
    }

    const nodes = [...state.nodesById.values()]
      .sort((a, b) => normalizeText(b.name).length - normalizeText(a.name).length);

    const byName = nodes.filter((node) => text.includes(normalizeText(node.name)));
    if (byName.length === 1) {
      return byName[0];
    }

    if (byName.length > 1) {
      const byId = byName.find((node) => {
        const id = String(node.id);
        return new RegExp(`(^|\\D)${id}(\\D|$)`).test(text);
      });
      return byId || byName[0];
    }

    return null;
  }

  function metadataSummary(node) {
    const limit = Number(node?.transfer_enable || 0) > 0
      ? `${formatGb(node.transfer_enable)} GB`
      : copy.unlimited;
    const day = node?.traffic_reset_day
      ? copy.everyMonthDay(node.traffic_reset_day)
      : copy.notSet;
    const remark = normalizeText(node?.remark || "");

    const parts = [
      `${copy.monthLimit}: ${limit}`,
      `${copy.resetDay}: ${day}`,
    ];
    if (remark) {
      parts.push(`${copy.remark}: ${remark}`);
    }

    return {
      short: parts.join(" · "),
      full: parts.join("\n"),
    };
  }

  function decorateNodeRows() {
    if (state.nodesById.size === 0) {
      return;
    }

    const rows = document.querySelectorAll("tr, [role='row']");
    for (const row of rows) {
      if (!isVisible(row)) {
        continue;
      }

      const node = findNodeForRow(row);
      if (!node) {
        continue;
      }

      const cells = [...row.querySelectorAll("td, [role='cell']")];
      const name = normalizeText(node.name);
      const target = cells.find((cell) => normalizeText(cell.textContent).includes(name))
        || cells[0]
        || row;

      let meta = target.querySelector(":scope > [data-xboard-node-admin-row-meta]");
      if (!meta) {
        meta = document.createElement("div");
        meta.dataset.xboardNodeAdminRowMeta = "1";
        meta.className = "xboard-node-admin-row-meta";
        target.append(meta);
      }

      const summary = metadataSummary(node);
      meta.textContent = summary.short;
      meta.title = summary.full;
    }
  }

  function scheduleScan() {
    clearTimeout(state.scanTimer);
    state.scanTimer = setTimeout(injectMetadataFields, 30);
  }

  function scheduleDecorate() {
    clearTimeout(state.decorateTimer);
    state.decorateTimer = setTimeout(decorateNodeRows, 60);
  }

  function installClickTracking() {
    document.addEventListener("pointerdown", (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) {
        return;
      }

      const buttonText = normalizeText(target.closest("button")?.textContent || "");
      if (copy.addWords.some((word) => buttonText.includes(word))) {
        state.pendingNodeId = null;
        return;
      }

      const row = target.closest("tr, [role='row']");
      if (!row) {
        return;
      }

      const node = findNodeForRow(row);
      if (node) {
        state.pendingNodeId = String(node.id);
      }
    }, true);
  }

  function installStyles() {
    const style = document.createElement("style");
    style.textContent = `
      .xboard-node-admin-section {
        margin: 14px 0;
        padding: 14px;
        border: 1px solid color-mix(in srgb, currentColor 16%, transparent);
        border-radius: 10px;
        background: color-mix(in srgb, currentColor 3%, transparent);
      }
      .xboard-node-admin-title {
        margin-bottom: 12px;
        font-size: 13px;
        font-weight: 700;
      }
      .xboard-node-admin-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
      }
      .xboard-node-admin-field {
        display: flex;
        min-width: 0;
        flex-direction: column;
        gap: 6px;
        font-size: 13px;
      }
      .xboard-node-admin-field small {
        opacity: .68;
        line-height: 1.45;
      }
      .xboard-node-admin-label {
        font-weight: 600;
      }
      .xboard-node-admin-input {
        box-sizing: border-box;
        width: 100%;
        min-height: 38px;
        padding: 8px 10px;
        color: inherit;
        border: 1px solid color-mix(in srgb, currentColor 20%, transparent);
        border-radius: 7px;
        background: transparent;
        outline: none;
      }
      .xboard-node-admin-input:focus {
        border-color: color-mix(in srgb, currentColor 55%, transparent);
        box-shadow: 0 0 0 2px color-mix(in srgb, currentColor 10%, transparent);
      }
      .xboard-node-admin-textarea {
        resize: vertical;
      }
      .xboard-node-admin-full {
        grid-column: 1 / -1;
      }
      .xboard-node-admin-row-meta {
        max-width: 420px;
        margin-top: 4px;
        overflow: hidden;
        color: color-mix(in srgb, currentColor 65%, transparent);
        font-size: 11px;
        line-height: 1.35;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      @media (max-width: 720px) {
        .xboard-node-admin-grid {
          grid-template-columns: 1fr;
        }
        .xboard-node-admin-full {
          grid-column: auto;
        }
      }
    `;
    document.head.append(style);
  }

  function startDomObserver() {
    const observer = new MutationObserver(() => {
      scheduleScan();
      scheduleDecorate();
    });
    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
    });
    scheduleScan();
    scheduleDecorate();
  }

  installFetchInterceptor();
  installXhrInterceptor();
  installStyles();
  installClickTracking();

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startDomObserver, { once: true });
  } else {
    startDomObserver();
  }
})();
