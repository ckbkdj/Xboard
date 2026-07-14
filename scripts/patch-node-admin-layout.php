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

// Use the native Node Name field as the single visual reference for both new
// fields. This guarantees identical label typography, control height and width.
$content = $replaceExact(
    $content,
    <<<'JS'
    const trafficLabel = findLabelElement(editor, copy.trafficLabels);
    const trafficInput = findInputByLabel(editor, copy.trafficLabels);
    const hostLabel = findLabelElement(editor, copy.hostLabels);
    const hostInput = findInputByLabel(editor, copy.hostLabels);
JS,
    <<<'JS'
    const nodeNameLabel = findLabelElement(editor, copy.nodeNameLabels);
    const nodeNameInput = findInputByLabel(editor, copy.nodeNameLabels);
    const nativeLabelSource = nodeNameLabel || findLabelElement(editor, copy.hostLabels);
    const nativeInputSource = nodeNameInput || findInputByLabel(editor, copy.hostLabels);
JS,
    'shared native node-name style source'
);

$content = $replaceExact(
    $content,
    <<<'JS'
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
JS,
    <<<'JS'
    const resetField = createSafeField(
      copy.resetDayLabel,
      resetInput,
      nativeLabelSource,
      nativeInputSource
    );
    const remarkField = createSafeField(
      copy.remarkLabel,
      remarkInput,
      nativeLabelSource,
      nativeInputSource
    );
JS,
    'identical reset and remark typography'
);

// Read the real form-grid gap and Node Name input width. The two custom fields
// stay on separate rows but use exactly the same control width as Node Name.
$content = $replaceExact(
    $content,
    <<<'JS'
      const tagField = findFieldContainer(tagLabel, editor);
      if (!tagField?.parentElement) continue;

      const node = findNodeForEditor(editor);
JS,
    <<<'JS'
      const tagField = findFieldContainer(tagLabel, editor);
      if (!tagField?.parentElement) continue;

      const nativeNameInput = findInputByLabel(editor, copy.nodeNameLabels);
      const parentStyle = getComputedStyle(tagField.parentElement);
      const nativeFieldGap = parentStyle.rowGap && parentStyle.rowGap !== "normal"
        ? parentStyle.rowGap
        : (parentStyle.gap && parentStyle.gap !== "normal" ? parentStyle.gap : "1rem");
      const nativeInputWidth = nativeNameInput?.getBoundingClientRect().width || 0;

      const node = findNodeForEditor(editor);
JS,
    'native field gap and input width measurement'
);

$content = $replaceExact(
    $content,
    <<<'JS'
      if (section.parentElement !== tagField.parentElement || section.nextElementSibling !== tagField) {
        tagField.parentElement.insertBefore(section, tagField);
      }
      hydrateSection(section, node);
JS,
    <<<'JS'
      section.style.setProperty("--xboard-native-field-gap", nativeFieldGap);
      if (nativeInputWidth > 0) {
        section.style.setProperty("--xboard-native-input-width", `${nativeInputWidth}px`);
      }

      if (section.parentElement !== tagField.parentElement || section.nextElementSibling !== tagField) {
        tagField.parentElement.insertBefore(section, tagField);
      }
      hydrateSection(section, node);
JS,
    'apply native spacing and width'
);

// Render list metadata vertically instead of one crowded horizontal sentence.
$content = $replaceExact(
    $content,
    <<<'JS'
    return `${copy.monthLimit} ${limit} · ${copy.resetDay} ${day} · ${copy.remark} ${remark}`;
JS,
    <<<'JS'
    return [
      `${copy.monthLimit}: ${limit}`,
      `${copy.resetDay}: ${day}`,
      `${copy.remark}: ${remark}`,
    ].join("\n");
JS,
    'vertical node metadata text'
);

$content = $replaceExact(
    $content,
    '        grid-template-columns: repeat(2, minmax(0, 1fr));',
    '        grid-template-columns: minmax(0, 1fr);',
    'one custom field per row'
);

$content = $replaceExact(
    $content,
    '        gap: 1rem;',
    '        gap: var(--xboard-native-field-gap, 1rem);',
    'native form row spacing'
);

$content = $replaceExact(
    $content,
    <<<'CSS'
      .xboard-node-admin-field {
        display: flex;
        min-width: 0;
        flex-direction: column;
        gap: 0.5rem;
        pointer-events: auto;
      }
CSS,
    <<<'CSS'
      .xboard-node-admin-field {
        display: flex;
        width: var(--xboard-native-input-width, 100%);
        max-width: 100%;
        min-width: 0;
        flex-direction: column;
        gap: 0.5rem;
        pointer-events: auto;
      }
CSS,
    'native node-name control width'
);

$content = $replaceExact(
    $content,
    <<<'CSS'
        line-height: 1rem;
        text-overflow: ellipsis;
        white-space: nowrap;
CSS,
    <<<'CSS'
        line-height: 1.15rem;
        text-overflow: ellipsis;
        white-space: pre-line;
CSS,
    'vertical list line layout'
);

if (file_put_contents($path, $content) === false) {
    fwrite(STDERR, "Unable to write {$path}\n");
    exit(1);
}

echo "Aligned node admin layout: vertical list metadata, one editor field per row, native spacing, and Node Name control width.\n";
