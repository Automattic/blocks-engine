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

// A route's copy of a shared sheet keeps only arms its root can match, so
// every route gate left in it (`:root.be-rs-…`, `:root:not(.be-rs-…)`) is
// already true on that root. Spelling each one per arm is dead weight
// (issue #6145, theme_css_weight): the copy writes them out and still
// matches what the shared sheet matches there.
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
$assert(1 === preg_match('/:root:is\(\.be-r-[0-9a-f]+\)/', $shared['content']), 'Fixture exercises route-gated arms');

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
$gated = false;
foreach ($plan['pages'] as $document) {
    $loaded = '';
    foreach (ThemeBootstrap::documentStylesheets($plan, $document['source_path']) as $sheet) {
        $asset = $byTarget[$sheet['target_path']] ?? null;
        if (null !== $asset && str_contains((string) ($asset['content'] ?? ''), '--sample-')) $loaded .= $asset['content'];
    }
    $assert('' !== $loaded, $document['source_path'] . ': the route loads the author sheet');
    if ($loaded === $shared['content']) continue;
    ++$checked;
    $gated = $gated || 'about/index.html' === $document['source_path'];
    preg_match_all('/(?<![\w-])be-rs?-[0-9a-f]+/', $loaded, $gates);
    $assert(array() === $gates[0], $document['source_path'] . ': the route copy spells no gate its root decides: ' . implode(' ', array_unique($gates[0])));

    // Same cascade on this route for the root, and for the paragraph on the
    // route whose markup is one.
    $dom = new DOMDocument();
    $dom->loadHTML('<html><body><main><p class="' . trim($classes) . '">Text</p></main></body></html>');
    foreach ($document['document_metadata']['root_attributes'] as $name => $value) $dom->documentElement->setAttribute($name, $value);
    foreach ('about/index.html' === $document['source_path'] ? array($dom->getElementsByTagName('p')->item(0), $dom->documentElement) : array($dom->documentElement) as $element) {
        $assert($armsMatching($shared['content'], $element) === $armsMatching($loaded, $element), $document['source_path'] . ': the route matches what the shared sheet matches on ' . $element->nodeName);
    }
}
$assert($checked > 0 && $gated, 'Fixture delivers a route copy whose arms its root gates');
echo "Route decided-gate stylesheet delivery passed\n";
