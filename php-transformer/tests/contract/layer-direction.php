<?php
declare(strict_types=1);

// The package's layers must depend in one direction only.
//
// `php-transformer` holds three concerns that read as one namespace tree:
//
//   * shared primitives — Css/, Support/, Contract/, AssetAnalysis/, Path/
//   * WordPress vocabulary — WordPress/ (Gutenberg block grammar: parse,
//     serialize, core block capabilities). A translation layer that targets
//     Gutenberg legitimately depends on this.
//   * translation — HtmlToBlocks/, converting source HTML into block markup
//   * WordPress materialization — WordPressSitePlan/, StaticSite/, producing
//     the plan a WordPress consumer applies
//   * orchestration — ArtifactCompiler/, the composition root that runs the
//     conversion and assembles the plan
//
// Until this check existed the graph was a cycle: materialization imported
// conversion internals (CSS transforms, the shell landmark policy, the block
// contract map) while conversion imported materialization. A cycle cannot be
// split, cannot be reasoned about in isolation, and hides which half owns a
// behavior. Breaking it is what makes "a translation layer that does not know
// about the WordPress site runtime" a checkable property rather than a slogan.
//
// The rule enforced here: materialization must not import translation.
// Orchestration may import anything, because composing the two is its job.
//
// The remaining conversion -> materialization edges are pinned rather than
// forbidden. `StaticSite\FontMaterialization` is consumed directly by
// downstream packages, so relocating it is a coordinated release rather than a
// move; pinning the exact set means the debt cannot silently grow while that
// is scheduled.

$root = dirname(__DIR__, 2);
$failures = array();
$checks = 0;

/** @return array<int, string> */
$phpFiles = static function (string $dir): array {
    if (! is_dir($dir)) {
        return array();
    }
    $files = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && 'php' === $file->getExtension()) {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);
    return $files;
};

/** @return array<int, string> */
$importsOf = static function (string $path): array {
    $source = (string) file_get_contents($path);
    $matches = array();
    preg_match_all('/^use\s+(Automattic\\\\BlocksEngine\\\\PhpTransformer\\\\[A-Za-z0-9_\\\\]+)/m', $source, $matches);
    return $matches[1];
};

$relative = static function (string $path) use ($root): string {
    return ltrim(str_replace($root, '', $path), '/');
};

// 1. Materialization must not depend on translation.
$materializationRoots = array(
    $root . '/src/WordPressSitePlan',
    $root . '/src/WordPress',
    $root . '/src/StaticSite',
);
foreach ($materializationRoots as $dir) {
    foreach ($phpFiles($dir) as $file) {
        ++$checks;
        foreach ($importsOf($file) as $import) {
            if (str_starts_with($import, 'Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\')) {
                $failures[] = $relative($file) . ' imports conversion internals: ' . $import;
            }
        }
    }
}

// 2. Conversion may not reach into materialization beyond the pinned set.
$allowedConversionToMaterialization = array(
    'Automattic\\BlocksEngine\\PhpTransformer\\StaticSite\\FontMaterialization\\CssFontAnalysisCache',
    'Automattic\\BlocksEngine\\PhpTransformer\\StaticSite\\FontMaterialization\\FontMaterializationPlanBuilder',
);
$observed = array();
foreach ($phpFiles($root . '/src/HtmlToBlocks') as $file) {
    ++$checks;
    foreach ($importsOf($file) as $import) {
        if (! str_starts_with($import, 'Automattic\\BlocksEngine\\PhpTransformer\\WordPressSitePlan\\')
            && ! str_starts_with($import, 'Automattic\\BlocksEngine\\PhpTransformer\\StaticSite\\')) {
            continue;
        }
        $observed[$import] = true;
        if (! in_array($import, $allowedConversionToMaterialization, true)) {
            $failures[] = $relative($file) . ' adds an unpinned materialization dependency: ' . $import;
        }
    }
}
foreach ($allowedConversionToMaterialization as $pinned) {
    ++$checks;
    if (! isset($observed[$pinned])) {
        $failures[] = 'pinned materialization dependency is gone; drop it from the allowed set: ' . $pinned;
    }
}

// 3. Shared primitives must not depend on any layer above them.
foreach (array('/src/Css', '/src/Support', '/src/AssetAnalysis') as $shared) {
    foreach ($phpFiles($root . $shared) as $file) {
        ++$checks;
        foreach ($importsOf($file) as $import) {
            foreach (array('HtmlToBlocks', 'WordPressSitePlan', 'StaticSite', 'ArtifactCompiler') as $upper) {
                if (str_starts_with($import, 'Automattic\\BlocksEngine\\PhpTransformer\\' . $upper . '\\')) {
                    $failures[] = $relative($file) . ' is a shared primitive but imports ' . $upper . ': ' . $import;
                }
            }
        }
    }
}

if (array() !== $failures) {
    fwrite(STDERR, "Layer direction contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo 'Layer direction contract passed (' . $checks . " checks).\n";
