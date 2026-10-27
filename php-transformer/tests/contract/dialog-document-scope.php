<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$fixture = require dirname(__DIR__) . '/fixtures/dialog-document-scope.php';
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$flatten = static function (array $blocks) use (&$flatten): array {
    $out = array();
    foreach ($blocks as $block) { $out[] = $block; array_push($out, ...$flatten($block['innerBlocks'] ?? array())); }
    return $out;
};
foreach (array('desktop', 'mobile') as $variant) {
    $result = (new ArtifactCompiler())->compile($fixture($variant))->toArray();
    $blocks = $flatten($result['blocks']);
    $names = array_column($blocks, 'blockName');
    $assert(!in_array('core/html', $names, true), 'A document-scope lookup and deeply nested dialog do not turn the page into core/html: ' . $variant);
    $assert(in_array('core/heading', $names, true) && in_array('core/paragraph', $names, true), 'Unrelated main/footer remain native editable blocks.');
    $assert(str_contains($result['serialized_blocks'], 'data-blocks-engine-capture-document-scope') && str_contains($result['serialized_blocks'], 'data-blocks-engine-capture-dialog-ancestor-unverified'), 'Document identity and unverified ancestor proof remain reviewable in emitted markup.');
    $assert(str_contains($result['serialized_blocks'], 'data-blocks-engine-capture-dialog-panel="gallery-' . $variant . '"') && str_contains($result['serialized_blocks'], 'Captured image caption.'), 'The wired popup remains editable in its declared source document scope.');
    $assert('pass' === (new Runtime())->validateBlockSerialization($result['blocks'])['status'], 'The document and popup serialize as valid editable blocks.');
}
$unknown = (new ArtifactCompiler())->compile($fixture('desktop', true))->toArray();
$html = array_values(array_filter($flatten($unknown['blocks']), static fn(array $block): bool => 'core/html' === $block['blockName']));
$assert(1 === count($html) && str_starts_with(trim($html[0]['innerHTML']), '<div id="workspace">') && !str_contains($html[0]['innerHTML'], 'Unrelated main copy.'), 'An identity-addressed descendant application keeps one bounded block without swallowing the document.');
$assert(in_array('core/heading', array_column($flatten($unknown['blocks']), 'blockName'), true) && str_contains($unknown['serialized_blocks'], 'Unrelated editable footer.'), 'Content beside the application remains native and editable.');
$assert(array() !== array_filter($unknown['source_reports']['runtime_islands'] ?? array(), static fn(array $island): bool => 'app_shell' === ($island['kind'] ?? null) && '#workspace' === ($island['selector'] ?? null)), 'The application preservation diagnostic is truthful and source-owned.');
$assert(1 === count(array_filter($unknown['source_reports']['runtime_islands'] ?? array(), static fn(array $island): bool => 'app_shell' === ($island['kind'] ?? null))), 'Only the application root is an app shell; its static document ancestors are not.');

$static = $fixture();
$static['files']['index.html'] = str_replace(array('<main>', '</main>'), array('<div id="page-frame"><main>', '</main><canvas></canvas></div>'), $static['files']['index.html']);
$static['files']['index.html'] = str_replace('data-blocks-engine-capture-desktop-document', 'site-document-variant-portrait', $static['files']['index.html']);
$result = (new ArtifactCompiler())->compile($static)->toArray();
foreach (array_filter($flatten($result['blocks']), static fn(array $block): bool => 'core/html' === $block['blockName']) as $block) {
    $assert(!str_contains($block['innerHTML'], 'Unrelated main copy.'), 'An unrelated canvas does not move a page-wide fallback to the next static ancestor.');
}
$assert(in_array('core/heading', array_column($flatten($result['blocks']), 'blockName'), true), 'The generic declared variant contract remains editable.');

$routes = array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><title>Home</title><style id="source-style">body{margin:0}</style><script type="application/ld+json">{"@type":"LocalBusiness","name":"Neutral"}</script></head><body><h1>Home</h1><a href="about.html">About</a></body></html>',
    'about.html' => '<!doctype html><html><head><title>About</title><style id="about-style">body{margin:0}</style><script>window.aboutLoaded=true;</script></head><body><h1>About</h1></body></html>',
));
$result = (new ArtifactCompiler())->compile($routes)->toArray();
$assert('failed' !== $result['status'], 'Another route script occurrence cannot overwrite homepage inline-data loading identity.');
$pages = $result['source_reports']['wordpress_site_plan']['pages'];
$assert(2 === count($pages), 'Both source-owned routes compile through the real plan validation gate.');
foreach ($pages as $page) {
    $script = $page['document_metadata']['scripts'][0];
    if ('index.html' === $page['source_path']) {
        $assert('inline' === ($script['source_kind'] ?? null) && 'application/ld+json' === ($script['type'] ?? null), 'Homepage structured data retains its inline loading contract.');
    } else {
        $assert(isset($script['asset_reference']), 'The other route retains its executable script asset.');
    }
}

// A comment is inert, not a reason to lose a device scope when a source id
// is rebound through instance specificity shims. Inspect both compiler assets
// and the projected/support/editor stylesheets published by the site plan.
$deviceFixture = require dirname(__DIR__) . '/fixtures/device-gated-shell.php';
$deviceResult = (new ArtifactCompiler())->compile($deviceFixture())->toArray();
$reference = (new ArtifactCompiler())->compile($deviceFixture(false))->toArray();
$deviceAssets = static fn (array $result): array => array_values(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null)));
$ungated = 0;
$mobileRules = 0;
$copies = array();
$instanceRule = false;
foreach (array($deviceAssets($deviceResult), $deviceAssets($deviceResult['source_reports']['wordpress_site_plan'])) as $assets) {
    foreach ($assets as $asset) {
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude) use (&$ungated, &$mobileRules, &$copies, &$instanceRule, $asset, $assert): void {
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                if (!str_contains($selector, '#shell') && !str_contains($selector, '.blocks-engine-editor-anchor-shell')) continue;
                if (!str_contains($selector, '[data-be-d="desktop"]') && !str_contains($selector, '[data-be-d="mobile"]')) ++$ungated;
                if (!str_contains($selector, '[data-be-d="mobile"]')) continue;
                ++$mobileRules;
                $instanceRule = $instanceRule || str_contains($selector, ':not(#be-i-');
                $copies[$asset['source'] ?? 'files'] = true;
                // Removing a :where gate must not change any authored/shim weight.
                $withoutGate = str_replace(':where([data-be-d="mobile"])', '', $selector);
                $assert(CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($selector)) === CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($withoutGate)), 'The retained device gate adds no specificity.');
            }
        });
    }
}
$assert(0 === $ungated, 'Every emitted device id/anchor rule retains its gate; ungated rules: ' . $ungated);
$assert(0 < $mobileRules && $instanceRule && isset($copies['files'], $copies['engine-support'], $copies['editor-static-state']), 'The fixture exercises instance-weighted mobile author, after-author and editor-static-state copies.');
$desktopCss = static fn (array $result): array => array_values(array_map(
    static fn (array $asset): string => $asset['content'],
    array_filter($deviceAssets($result), static fn (array $asset): bool => 'desktop.css' === ($asset['path'] ?? null))
));
$assert(array() !== $desktopCss($reference) && $desktopCss($reference) === $desktopCss($deviceResult), 'The desktop stylesheet is byte-identical when only the mobile prelude has a comment.');

echo "Dialog document scope contract passed.\n";
