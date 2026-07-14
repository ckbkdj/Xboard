<?php

declare(strict_types=1);

$root = rtrim($argv[1] ?? '/www', '/');

$read = static function (string $path): string {
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "Unable to read {$path}\n");
        exit(1);
    }
    return $content;
};

$write = static function (string $path, string $content): void {
    if (file_put_contents($path, $content) === false) {
        fwrite(STDERR, "Unable to write {$path}\n");
        exit(1);
    }
};

$replaceExact = static function (string $content, string $search, string $replace, string $label): string {
    $count = substr_count($content, $search);
    if ($count !== 1) {
        fwrite(STDERR, "Patch '{$label}' expected 1 match, got {$count}\n");
        exit(1);
    }
    return str_replace($search, $replace, $content);
};

$replaceCount = static function (
    string $content,
    string $search,
    string $replace,
    int $expected,
    string $label
): string {
    $count = substr_count($content, $search);
    if ($count !== $expected) {
        fwrite(STDERR, "Patch '{$label}' expected {$expected} matches, got {$count}\n");
        exit(1);
    }
    return str_replace($search, $replace, $content);
};

$replaceRegex = static function (string $content, string $pattern, string $replace, string $label): string {
    $patched = preg_replace($pattern, $replace, $content, 1, $count);
    if ($patched === null || $count !== 1) {
        fwrite(STDERR, "Patch '{$label}' expected 1 match, got {$count}\n");
        exit(1);
    }
    return $patched;
};

$js = "{$root}/public/assets/custom/admin-node-metadata.js";
$content = $read($js);

// The admin HTML declares lang=en even when the React UI is Chinese. Prefer the
// UI's stored language and browser language before falling back to the HTML tag.
$content = $replaceExact(
    $content,
    <<<'JS'
  const locale = (() => {
    const raw = String(
      document.documentElement.lang ||
      localStorage.getItem("i18nextLng") ||
      navigator.language ||
      "zh-CN"
    ).toLowerCase();
    return raw.startsWith("zh") ? "zh" : "en";
  })();
JS,
    <<<'JS'
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

    const html = String(document.documentElement.lang || "").toLowerCase();
    return html.startsWith("zh") ? "zh" : "en";
  })();
JS,
    'administrator UI locale detection'
);

// Match both translations regardless of the language detected at page startup.
$content = $replaceExact(
    $content,
    "    tagLabels: [\"节点标签\"],\n" .
    "    nodeNameLabels: [\"节点名称\"],\n" .
    "    hostLabels: [\"节点地址\"],\n" .
    "    addWords: [\"添加节点\", \"新建节点\"],",
    "    tagLabels: [\"节点标签\", \"Node Tags\", \"Tags\"],\n" .
    "    nodeNameLabels: [\"节点名称\", \"Node Name\", \"Name\"],\n" .
    "    hostLabels: [\"节点地址\", \"Node Address\", \"Address\", \"Host\"],\n" .
    "    addWords: [\"添加节点\", \"新建节点\", \"Add Node\", \"New Node\"],",
    'Chinese editor label aliases'
);
$content = $replaceExact(
    $content,
    "    tagLabels: [\"Node Tags\", \"Tags\"],\n" .
    "    nodeNameLabels: [\"Node Name\", \"Name\"],\n" .
    "    hostLabels: [\"Node Address\", \"Address\", \"Host\"],\n" .
    "    addWords: [\"Add Node\", \"New Node\"],",
    "    tagLabels: [\"Node Tags\", \"Tags\", \"节点标签\"],\n" .
    "    nodeNameLabels: [\"Node Name\", \"Name\", \"节点名称\"],\n" .
    "    hostLabels: [\"Node Address\", \"Address\", \"Host\", \"节点地址\"],\n" .
    "    addWords: [\"Add Node\", \"New Node\", \"添加节点\", \"新建节点\"],",
    'English editor label aliases'
);

// Keep line breaks in administrator remarks.
$content = $replaceExact(
    $content,
    '    const remark = normalizeText(remarkInput?.value || "");',
    '    const remark = String(remarkInput?.value || "").trim();',
    'preserve administrator remark line breaks'
);

// The current official admin already owns the monthly traffic-limit input. Do
// not add a duplicate or overwrite its value; only append reset day and remark.
$content = $replaceRegex(
    $content,
    '~  function collectMetadataValues\(\) \{\n.*?\n  \}\n\n  function mergeObjectMetadata~s',
    <<<'JS'
  function collectMetadataValues() {
    const section = getVisibleMetadataSection();
    if (!section) {
      return null;
    }

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

  function mergeObjectMetadata
JS,
    'administrator metadata collection'
);
$content = $replaceCount(
    $content,
    '      body.set("transfer_enable", String(metadata.transfer_enable));' . "\n",
    '',
    2,
    'do not overwrite native traffic limit body'
);
$content = $replaceExact(
    $content,
    '          params.set("transfer_enable", String(metadata.transfer_enable));' . "\n",
    '',
    'do not overwrite native traffic limit query body'
);

$content = $replaceRegex(
    $content,
    '~  function createMetadataSection\(node\) \{\n.*?\n  \}\n\n  function hydrateSection~s',
    <<<'JS'
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

    grid.append(resetField, remarkField);
    section.append(title, grid);

    section.addEventListener("input", () => {
      section.dataset.dirty = "1";
    });
    section.addEventListener("change", () => {
      section.dataset.dirty = "1";
    });

    return section;
  }

  function hydrateSection
JS,
    'reset day and remark editor section'
);

$content = $replaceExact(
    $content,
    "    section.dataset.nodeId = expectedId;\n" .
    "    section.querySelector('[data-field=\"transfer-limit-gb\"]').value = formatGb(node?.transfer_enable);\n" .
    "    section.querySelector('[data-field=\"traffic-reset-day\"]').value =\n",
    "    section.dataset.nodeId = expectedId;\n" .
    "    section.querySelector('[data-field=\"traffic-reset-day\"]').value =\n",
    'remove duplicate traffic-limit hydration'
);

// The React admin uses a Sheet/Drawer that is not guaranteed to expose
// role=dialog. Locate the editor from its visible Node Tags field instead.
$content = $replaceRegex(
    $content,
    '~  function injectMetadataFields\(\) \{\n.*?\n  \}\n\n  function findNodeForRow~s',
    <<<'JS'
  function findEditorRoot(tagLabel) {
    let current = tagLabel.parentElement;
    let fallback = null;

    for (let depth = 0; depth < 18 && current; depth += 1) {
      const controls = current.querySelectorAll(
        "input, textarea, select, [role='combobox']"
      ).length;
      const hasNodeName = Boolean(findLabelElement(current, copy.nodeNameLabels));
      const text = normalizeText(current.textContent);
      const hasEditorHeading = [
        "编辑节点", "添加节点", "新建节点",
        "Edit Node", "Add Node", "New Node"
      ].some((word) => text.includes(word));

      if (controls >= 2 && (hasNodeName || hasEditorHeading)) {
        fallback ||= current;
        if (current.matches("[role='dialog'], form, [data-state='open']")) {
          return current;
        }
      }

      current = current.parentElement;
    }

    return fallback;
  }

  function findVisibleTagLabels() {
    const variants = copy.tagLabels.map(normalizeText);
    const elements = document.querySelectorAll(
      "label, [data-slot='label'], span, p, div"
    );

    return [...elements].filter((element) => {
      if (!isVisible(element) || !variants.includes(normalizeText(element.textContent))) {
        return false;
      }

      return ![...element.children].some((child) =>
        variants.includes(normalizeText(child.textContent))
      );
    });
  }

  function injectMetadataFields() {
    for (const tagLabel of findVisibleTagLabels()) {
      const editor = findEditorRoot(tagLabel);
      if (!editor) {
        continue;
      }

      const node = findNodeForDialog(editor);
      const existing = editor.querySelector("[data-xboard-node-admin-fields]");
      if (existing) {
        hydrateSection(existing, node);
        continue;
      }

      const anchor = findFieldContainer(tagLabel, editor);
      if (!anchor?.parentNode) {
        continue;
      }

      const section = createMetadataSection(node);
      anchor.parentNode.insertBefore(section, anchor);
    }
  }

  function findNodeForRow
JS,
    'Sheet and Drawer editor discovery'
);

$content = $replaceExact(
    $content,
    "      const summary = metadataSummary(node);\n" .
    "      meta.textContent = summary.short;\n" .
    "      meta.title = summary.full;",
    "      const summary = metadataSummary(node);\n" .
    "      if (meta.textContent !== summary.short) {\n" .
    "        meta.textContent = summary.short;\n" .
    "      }\n" .
    "      if (meta.title !== summary.full) {\n" .
    "        meta.title = summary.full;\n" .
    "      }",
    'avoid mutation observer refresh loop'
);

$write($js, $content);

$blade = "{$root}/resources/views/admin.blade.php";
$content = $read($blade);
$content = $replaceExact(
    $content,
    '<script src="/assets/custom/admin-node-metadata.js?v={{ urlencode((string) $version) }}"></script>',
    '<script src="/assets/custom/admin-node-metadata.js?v={{ file_exists(public_path(\'assets/custom/admin-node-metadata.js\')) ? filemtime(public_path(\'assets/custom/admin-node-metadata.js\')) : urlencode((string) $version) }}"></script>',
    'administrator node metadata cache busting'
);
$write($blade, $content);

echo "Hardened administrator node metadata UI: Sheet/Drawer support, Chinese locale, reset day and remark fields, stable DOM updates, and cache busting.\n";
