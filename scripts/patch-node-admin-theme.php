<?php

declare(strict_types=1);

$root = rtrim($argv[1] ?? '/www', '/');
$path = "{$root}/public/assets/custom/admin-node-metadata.js";
$content = file_get_contents($path);

if ($content === false) {
    fwrite(STDERR, "Unable to read {$path}\n");
    exit(1);
}

$replaceExact = static function (string $content, string $search, string $replace, string $label): string {
    $count = substr_count($content, $search);
    if ($count !== 1) {
        fwrite(STDERR, "Patch '{$label}' expected 1 match, got {$count}\n");
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

// Reuse the native React form classes instead of imposing a separate font,
// color, input height, border, or dark-mode palette on the injected controls.
$content = $replaceExact(
    $content,
    <<<'JS'
  function injectMetadataFields() {
JS,
    <<<'JS'
  function inheritNativeEditorTheme(editor, section, tagLabel) {
    const referenceControl = [...editor.querySelectorAll(
      "input:not([type='hidden']), textarea, button[role='combobox'], [role='combobox']"
    )].find((element) => !section.contains(element) && isVisible(element));

    const nativeControlClass = referenceControl?.getAttribute("class") || "";
    const nativeLabelClass = tagLabel?.getAttribute("class") || "";

    for (const control of section.querySelectorAll(".xboard-node-admin-input")) {
      const customClasses = ["xboard-node-admin-input"];
      if (control.matches("textarea")) {
        customClasses.push("xboard-node-admin-textarea");
      }

      control.setAttribute(
        "class",
        [nativeControlClass, ...customClasses].filter(Boolean).join(" ")
      );
      control.style.fontFamily = "inherit";
      control.style.fontSize = "inherit";
      control.style.lineHeight = "inherit";
      control.style.letterSpacing = "inherit";
    }

    for (const label of section.querySelectorAll(".xboard-node-admin-label")) {
      label.setAttribute(
        "class",
        [nativeLabelClass, "xboard-node-admin-label"].filter(Boolean).join(" ")
      );
    }

    section.style.fontFamily = "inherit";
    section.style.fontSize = "inherit";
    section.style.lineHeight = "inherit";
    section.style.color = "inherit";
  }

  function injectMetadataFields() {
JS,
    'native administrator form theme inheritance'
);

$content = $replaceExact(
    $content,
    <<<'JS'
      const section = createMetadataSection(node);
      anchor.parentNode.insertBefore(section, anchor);
JS,
    <<<'JS'
      const section = createMetadataSection(node);
      inheritNativeEditorTheme(editor, section, tagLabel);
      anchor.parentNode.insertBefore(section, anchor);
JS,
    'apply native administrator form theme'
);

// Keep only layout rules. Typography and colors inherit from the active admin
// theme or from the native classes copied above.
$content = $replaceRegex(
    $content,
    '~    style\.textContent = `\n      \.xboard-node-admin-section \{.*?\n      \}\n    `;~s',
    <<<'JS'
    style.textContent = `
      .xboard-node-admin-section {
        box-sizing: border-box;
        margin: 14px 0;
        padding: 0;
        color: inherit;
        font-family: inherit;
        font-size: inherit;
        font-style: inherit;
        font-variant: inherit;
        line-height: inherit;
        letter-spacing: inherit;
        text-rendering: inherit;
      }
      .xboard-node-admin-title {
        margin: 0 0 12px;
        color: inherit;
        font-family: inherit;
        font-size: inherit;
        line-height: inherit;
        font-weight: 500;
        letter-spacing: inherit;
      }
      .xboard-node-admin-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
      }
      .xboard-node-admin-field {
        display: flex;
        min-width: 0;
        flex-direction: column;
        gap: 8px;
        color: inherit;
        font: inherit;
      }
      .xboard-node-admin-field small {
        color: hsl(var(--muted-foreground, 0 0% 45%));
        font-family: inherit;
        font-size: 0.75rem;
        font-weight: 400;
        line-height: 1rem;
        letter-spacing: inherit;
      }
      .xboard-node-admin-label {
        color: inherit;
        font-family: inherit;
        font-size: inherit;
        font-style: inherit;
        line-height: inherit;
        letter-spacing: inherit;
      }
      .xboard-node-admin-input {
        box-sizing: border-box;
        width: 100%;
        font-family: inherit !important;
        font-size: inherit !important;
        font-style: inherit;
        line-height: inherit !important;
        letter-spacing: inherit;
      }
      .xboard-node-admin-textarea {
        min-height: 76px;
        resize: vertical;
      }
      .xboard-node-admin-full {
        grid-column: 1 / -1;
      }
      .xboard-node-admin-row-meta {
        max-width: 420px;
        margin-top: 4px;
        overflow: hidden;
        color: hsl(var(--muted-foreground, 0 0% 45%));
        font-family: inherit;
        font-size: 0.75rem;
        font-weight: 400;
        line-height: 1rem;
        letter-spacing: inherit;
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
JS,
    'administrator metadata theme-compatible styles'
);

if (file_put_contents($path, $content) === false) {
    fwrite(STDERR, "Unable to write {$path}\n");
    exit(1);
}

echo "Aligned administrator node metadata typography and controls with the active theme.\n";
