<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

// A route whose controls project an author class keeps the plain author arm
// for every other document behind `:where(:root:not(.be-r-…))`. The route
// that carries that class can never match such an arm, so its delivered copy
// of the shared sheet must not ship it (issue #6145, theme_css_weight).
$css = '.btn{padding:8px 16px;border-radius:4px;font-size:16px}';
$classes = '';
for ($i = 0; $i < 60; ++$i) {
    $css .= '.tone-' . $i . '{--sample-' . $i . ':' . $i . ';letter-spacing:' . ($i / 100) . 'px}';
    $classes .= ' tone-' . $i;
}
$page = static fn(string $title, string $main): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body><main>' . $main . '</main></body></html>';
$files = array(
    'index.html' => $page('Home', '<a href="/contact/index.html" class="btn' . $classes . '">Next</a><a href="/contact/index.html" class="btn' . $classes . '">Next</a>'),
    'contact/index.html' => $page('Contact', '<button type="button" class="btn' . $classes . '">Load</button>'),
    'about/index.html' => $page('About', '<p class="' . trim($classes) . '">Text</p>'),
    'style.css' => $css,
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$byTarget = array_column($plan['assets'], null, 'target_path');
$shared = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind'] && !empty($asset['stylesheet_instances']) && str_contains((string) ($asset['content'] ?? ''), '--sample-')));
$assert(1 === count($shared), 'Fixture keeps one shared author sheet');
$shared = $shared[0];
$assert(1 === preg_match('/:root:not\(\.be-r/', $shared['content']), 'Fixture exercises root-excluded route arms');

$armsMatching = static function (string $stylesheet, DOMElement $element): array {
    $matched = array();
    (new CssStylesheetTransformer())->visitStyleRules($stylesheet, static function (string $selector, string $body) use (&$matched, $element): void {
        foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
            $parsed = CssSelectorMatcher::parse(trim($arm));
            if ($parsed['supported'] && CssSelectorMatcher::matches($element, $parsed)['matches']) $matched[] = $body;
        }
    });
    sort($matched);
    return array_values(array_unique($matched));
};

$checked = 0;
foreach ($plan['pages'] as $document) {
    $roots = preg_split('/\s+/', trim($document['document_metadata']['root_attributes']['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $loaded = '';
    foreach (ThemeBootstrap::documentStylesheets($plan, $document['source_path']) as $sheet) {
        $asset = $byTarget[$sheet['target_path']] ?? null;
        if (null !== $asset && str_contains((string) ($asset['content'] ?? ''), '--sample-')) $loaded .= $asset['content'];
    }
    $assert('' !== $loaded, $document['source_path'] . ': the route loads the author sheet');
    preg_match_all('/:root(?:\.be-r(?![\w-]))?:not\(([^()]*)\)/', $loaded, $exclusions);
    $excluded = array();
    foreach ($exclusions[1] as $list) foreach (explode(',', $list) as $class) $excluded[ltrim(trim($class), '.')] = true;
    $own = array_values(array_intersect(array_keys($excluded), $roots));
    $assert(array() === $own, $document['source_path'] . ': the route loads no arm its root excludes: ' . implode(' ', $own));
    $excludedInShared = 1 === preg_match('/:root(?:\.be-r)?:not\([^()]*\.(?:' . implode('|', array_map(static fn(string $class): string => preg_quote($class, '/'), $roots)) . ')[,)]/', $shared['content']);
    if (!$excludedInShared) continue;
    ++$checked;
    $assert(strlen($loaded) < strlen($shared['content']), $document['source_path'] . ': the route loads fewer bytes than the shared sheet');

    // Same cascade on this route for its author element.
    $dom = new DOMDocument();
    $dom->loadHTML('<html><body><main><p class="' . trim($classes) . '">Text</p></main></body></html>');
    foreach ($document['document_metadata']['root_attributes'] as $name => $value) $dom->documentElement->setAttribute($name, $value);
    $item = $dom->getElementsByTagName('p')->item(0);
    $assert($armsMatching($shared['content'], $item) === $armsMatching($loaded, $item), $document['source_path'] . ': the route matches what the shared sheet matches');
}
$assert($checked > 0, 'Fixture has a route whose root a shared arm excludes');
echo "Route-excluded stylesheet delivery passed\n";
