<?php
declare(strict_types=1);

// Style declarations are read through one set of readers.
//
// A hand-written `(?:^|;)\s*property:` regex re-implements declaration
// parsing with its own rules for case, `!important`, shorthands, and which of
// several declarations wins. New code copies the nearest example, so each
// probe invites the next. `SourceDeclarations`, `CssValueInspector` and
// `CssRuleAnalyzer::declarations()` own that parsing; AGENTS.md lists which
// reader fits which question.
//
// Pinned sites need the raw regex match itself. Pinning the exact set means a
// new probe fails here instead of spreading.

$root = dirname(__DIR__, 2);
$pinned = array(
    // Rewrites z-index values in place, so it needs each match's byte offset.
    'src/WordPressSitePlan/ChromeStackingIdentity.php' => 1,
);

$observed = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file->isFile() || 'php' !== $file->getExtension()) {
        continue;
    }
    $count = substr_count((string) file_get_contents($file->getPathname()), '(?:^|;)\s*');
    if (0 < $count) {
        $observed[substr($file->getPathname(), strlen($root) + 1)] = $count;
    }
}
ksort($observed);

$failures = array();
foreach ($observed as $path => $count) {
    if ($count > ($pinned[$path] ?? 0)) {
        $failures[] = $path . ' parses declarations with a `(?:^|;)\s*` regex; read them through SourceDeclarations, CssValueInspector or CssRuleAnalyzer::declarations() (see AGENTS.md)';
    }
}
foreach ($pinned as $path => $count) {
    if (($observed[$path] ?? 0) < $count) {
        $failures[] = 'pinned declaration probe is gone; lower or drop its pin: ' . $path;
    }
}

if (array() !== $failures) {
    fwrite(STDERR, "Declaration reader contract failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo 'Declaration reader contract passed (' . count($observed) . " pinned files).\n";
