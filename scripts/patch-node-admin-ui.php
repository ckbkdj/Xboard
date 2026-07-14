<?php

declare(strict_types=1);

$root = rtrim($argv[1] ?? '/www', '/');

$replaceExact = static function (string $path, string $search, string $replace, string $label): void {
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "Unable to read {$path}\n");
        exit(1);
    }

    $count = substr_count($content, $search);
    if ($count !== 1) {
        fwrite(STDERR, "Patch '{$label}' expected 1 match, got {$count}\n");
        exit(1);
    }

    $content = str_replace($search, $replace, $content);
    if (file_put_contents($path, $content) === false) {
        fwrite(STDERR, "Unable to write {$path}\n");
        exit(1);
    }
};

$js = "{$root}/public/assets/custom/admin-node-metadata.js";
$replaceExact(
    $js,
    '    const remark = normalizeText(remarkInput?.value || "");',
    '    const remark = String(remarkInput?.value || "").trim();',
    'preserve administrator remark line breaks'
);

$replaceExact(
    $js,
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

$blade = "{$root}/resources/views/admin.blade.php";
$replaceExact(
    $blade,
    '<script src="/assets/custom/admin-node-metadata.js?v={{ urlencode((string) $version) }}"></script>',
    '<script src="/assets/custom/admin-node-metadata.js?v={{ file_exists(public_path(\'assets/custom/admin-node-metadata.js\')) ? filemtime(public_path(\'assets/custom/admin-node-metadata.js\')) : urlencode((string) $version) }}"></script>',
    'administrator node metadata cache busting'
);

echo "Hardened administrator node metadata UI: multiline remarks, stable DOM updates, file-mtime cache busting.\n";
