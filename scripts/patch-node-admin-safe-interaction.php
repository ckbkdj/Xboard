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

// Never copy a fixed pixel width from a native input. The custom field should
// use the active form grid width instead.
$content = $replaceExact(
    $content,
    '      "boxSizing", "width", "height", "minHeight", "paddingTop", "paddingRight",',
    '      "boxSizing", "height", "minHeight", "paddingTop", "paddingRight",',
    'responsive input width'
);

// A copied stacking context is unnecessary and can cover adjacent React fields
// in narrow drawers. Keep these controls in normal document flow.
$content = $replaceExact(
    $content,
    <<<'JS'
    target.style.pointerEvents = "auto";
    target.style.position = "relative";
    target.style.zIndex = "0";
JS,
    <<<'JS'
    target.style.pointerEvents = "auto";
    target.style.width = "100%";
JS,
    'remove control stacking context'
);

// Do not detach and reinsert the section on every observer pass. Repeated moves
// can interrupt focus while React is updating another native field.
$content = $replaceExact(
    $content,
    <<<'JS'
      tagField.parentElement.insertBefore(section, tagField);
      hydrateSection(section, node);
JS,
    <<<'JS'
      if (section.parentElement !== tagField.parentElement || section.nextElementSibling !== tagField) {
        tagField.parentElement.insertBefore(section, tagField);
      }
      hydrateSection(section, node);
JS,
    'focus-safe section placement'
);

$content = $replaceExact(
    $content,
    <<<'CSS'
        margin: 0;
        padding: 0;
        position: relative;
        z-index: 0;
        pointer-events: auto;
CSS,
    <<<'CSS'
        grid-column: 1 / -1;
        margin: 0;
        padding: 0;
        pointer-events: auto;
CSS,
    'full-row section without overlay'
);

$content = $replaceExact(
    $content,
    <<<'CSS'
        gap: 0.5rem;
        position: relative;
        z-index: 0;
        pointer-events: auto;
CSS,
    <<<'CSS'
        gap: 0.5rem;
        pointer-events: auto;
CSS,
    'field normal flow'
);

$content = $replaceExact(
    $content,
    <<<'CSS'
        width: 100%;
        pointer-events: auto !important;
        position: relative !important;
        z-index: 0 !important;
CSS,
    <<<'CSS'
        width: 100%;
        pointer-events: auto !important;
CSS,
    'input normal flow'
);

if (file_put_contents($path, $content) === false) {
    fwrite(STDERR, "Unable to write {$path}\n");
    exit(1);
}

echo "Hardened node admin interactions: no cloned native controls, no stacking overlay, and focus-safe placement.\n";
