<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// The body identity survives the editor; the ordinary wrapper identity does
// not. Body ids are deliberately outside the anchor inventory. Unlike a pair
// of wrapper ids, this mixed selector is replayed but its override is not.
$files = array();
foreach (array('index.html', 'about.html') as $route) {
    $html = '<html><head><link rel="stylesheet" href="shared.css"></head><body id="retained">';
    $html .= '<main id="frame"><p>' . $route . '</p></main>';
    $files[$route] = $html . '<nav><a href="index.html">Home</a><a href="about.html">About</a></nav></body></html>';
}
$files['shared.css'] = ':is(#retained,#frame){width:320px}#retained{width:100%}';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(2 === count($plan['pages']), 'The fixture compiles both routes.');
$editorCss = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'editor-static-state' === ($asset['source'] ?? '')), 'content'));
$assert(str_contains($editorCss, ':is(#retained,:where(.blocks-engine-editor-anchor-frame)'), 'The repair mixes a raw retained id with a lost wrapper anchor.');
$assert(!str_contains($editorCss, 'width:100%'), 'The winning author override must not be replayed.');

$document = new DOMDocument();
$document->loadHTML('<html><body id="retained"><main id="frame" class="blocks-engine-editor-anchor-frame"></main></body></html>');
$retained = $document->getElementsByTagName('body')->item(0);
// Evaluate declarations in plan emission order, resolving selector lists by
// their matching arm's specificity (not by substring inspection of CSS).
$winner = static function (DOMElement $element, bool $editor) use ($plan, $assert): ?string {
    $value = null;
    $weight = -1;
    foreach ($plan['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? '') || !in_array($asset['stylesheet_target'] ?? 'both', $editor ? array('editor', 'both') : array('frontend', 'both'), true)) continue;
        if (!$editor && 'editor-static-state' === ($asset['source'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($element, $assert, &$value, &$weight): void {
            if (!preg_match('/(?:^|;)width:([^;]+)/', $body, $declaration)) return;
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                $parsed = CssSelectorMatcher::parse($selector);
                $assert($parsed['supported'], 'Width selector is supported: ' . $selector);
                if (!CssSelectorMatcher::matches($element, $parsed, true)['matches']) continue;
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
$frontend = $winner($retained, false);
$editor = $winner($retained, true);
echo 'Retained identity: frontend=' . $frontend . ', editor=' . $editor . PHP_EOL;
$assert('100%' === $frontend, 'The later author rule wins on the frontend.');
$assert($frontend === $editor, 'The editor repair must not overturn the retained identity author winner.');
echo "editor-anchor-retained-cascade: passed\n";
