<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
// The earlier, more specific id/child rule establishes the containing block.
// The later resting rule must not win merely because its class survives the
// editor's replacement of block ids with client ids.
$css = ':where(.masthead) > :where(#brand-frame):not(.inactive):not(.collapsed){position:relative}'
    . '.site-header{position:var(--resting-position,static)}.badge{position:absolute;left:50%;top:0}';
$files = array();
foreach (array('index.html', 'about.html') as $route) {
    $files[$route] = '<!doctype html><html><head><style>' . $css . '</style></head><body>'
        . '<div class="masthead"><header id="brand-frame" class="site-header"><p class="badge">Brand</p>'
        . '<nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header>'
        . '<main><h1>' . $route . '</h1></main></div></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? ''));
$assert(array() !== $headers, 'The fixture extracts a shared header.');
$headerMarkup = implode('', array_column($headers, 'canonical_block_markup'));
$assert(str_contains($headerMarkup, 'blocks-engine-editor-anchor-brand-frame'), 'The shared header carries the editor identity.');
$assert(str_contains($headerMarkup, 'badge'), 'The absolute child remains inside the shared header.');

// Compare normal declarations by specificity, breaking ties in asset/rule
// emission order. The fixture has no important or conditional position rules.
$winner = static function (bool $editor, bool $includeRepairs) use ($plan, $assert): ?string {
    $document = new DOMDocument();
    $document->loadHTML('<html><body class="' . ($editor ? 'editor-styles-wrapper' : '') . '"><div class="masthead"><header id="'
        . ($editor ? 'block-client' : 'brand-frame') . '" class="site-header blocks-engine-editor-anchor-brand-frame"><p class="badge">Brand</p></header></div></body></html>');
    $element = $document->getElementsByTagName('header')->item(0);
    $value = null;
    // Core's block-root default has (0,2,0). Authored mirrors must
    // beat it without importance, even for zero-specificity source hooks.
    $weight = $editor ? CssSelectorMatcher::specificity(CssSelectorMatcher::parse('.block-editor-block-list__layout .block-editor-block-list__block')) : -1;
    if ($editor) $value = 'relative';
    foreach ($plan['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '') || !in_array($asset['stylesheet_target'] ?? 'both', $editor ? array('editor', 'both') : array('frontend', 'both'), true)) continue;
        if (!$includeRepairs && 'editor-static-state' === ($asset['source'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($element, $editor, $assert, &$value, &$weight): void {
            if (!preg_match('/(?:^|;)position:([^;]+)/', $body, $declaration)) return;
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                // The source matcher intentionally aliases ids to anchor classes.
                // Disable that alias, including inside repair guards: browsers
                // match the anchor class only when CSS explicitly names it.
                $parsed = CssSelectorMatcher::parse($editor ? str_replace('#brand-frame', '#source-only-brand-frame', $selector) : $selector);
                $assert($parsed['supported'], 'Position selector is supported: ' . $selector);
                if (!CssSelectorMatcher::matches($element, $parsed)['matches']) continue;
                $specificity = CssSelectorMatcher::specificity($parsed);
                if ($specificity >= $weight) {
                    $weight = $specificity;
                    $value = $declaration[1];
                }
            }
        });
    }
    return $value;
};
$frontend = $winner(false, true);
$editor = $winner(true, true);
$authoredEditor = $winner(true, false);
echo 'Anchor position: frontend=' . $frontend . ', editor=' . $editor . ', authored editor=' . $authoredEditor . PHP_EOL;
$assert('relative' === $frontend, 'The authored child-combinator rule establishes the frontend containing block.');
$assert($frontend === $editor, 'The emitted editor stylesheets preserve the frontend position winner.');
// Authored mirrors must preserve their own cascade too: correctness cannot
// depend on a later identity repair replaying the same winning declaration.
$assert($frontend === $authoredEditor, 'Authored editor-anchor position copies preserve the frontend winner independently of late repairs.');
echo "editor-anchor-position-cascade: passed\n";
