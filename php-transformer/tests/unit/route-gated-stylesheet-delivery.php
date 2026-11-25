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

// Route-variant projection gates a shared sheet's rules on root route
// classes. Every route links the sheet, so a route must not download the
// arms gated on other routes' classes (issue #6145, theme_css_weight): each
// route loads only arms its root can match, and matches what the shared
// sheet matches there.
$css = '';
for ($i = 0; $i < 80; ++$i) {
    foreach (array('listing', 'detail') as $variant) $css .= 'body.tweak-' . $variant . ' .foo{--sample-' . $variant . '-' . $i . ':' . $i . ';padding-bottom:' . ($i + 1) . 'px}';
}
$files = array('site.css' => $css);
for ($i = 0; $i < 6; ++$i) {
    $files['page-' . $i . '.html'] = '<html><head><title>Page ' . $i . '</title><link rel="stylesheet" href="/site.css"></head><body class="tweak-' . ($i % 2 ? 'detail' : 'listing') . '"><main><nav><ul><li class="foo"><a href="/page-0.html">Page ' . $i . '</a></li></ul></nav></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'page-0.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$byTarget = array_column($plan['assets'], null, 'target_path');
$shared = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind'] && str_contains($asset['source_path'], 'site') && str_contains((string) ($asset['content'] ?? ''), '--sample-') && !empty($asset['stylesheet_instances'])));
$assert(1 === count($shared), 'Fixture keeps one shared author sheet');
$shared = $shared[0];
preg_match_all('/(?<![\w-])(be-rs?-[0-9a-f]+)/', $shared['content'], $gates);
$assert(array() !== $gates[0], 'Fixture exercises route-gated arms');

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

foreach ($plan['pages'] as $page) {
    if (!preg_match('/^page-\d+\.html$/', $page['source_path'])) continue;
    $roots = preg_split('/\s+/', trim($page['document_metadata']['root_attributes']['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $loaded = '';
    foreach (ThemeBootstrap::documentStylesheets($plan, $page['source_path']) as $sheet) {
        $asset = $byTarget[$sheet['target_path']] ?? null;
        if (null !== $asset && str_contains((string) ($asset['content'] ?? ''), '--sample-')) $loaded .= $asset['content'];
    }
    $assert('' !== $loaded, $page['source_path'] . ': the route loads the author sheet');
    preg_match_all('/(?<![\w-])(be-rs?-[0-9a-f]+)/', $loaded, $named);
    $foreign = array_diff(array_unique($named[1]), $roots);
    $assert(array() === $foreign, $page['source_path'] . ': the route loads no arm gated on another route: ' . implode(' ', $foreign));
    if (array() !== array_diff(array_unique($gates[1]), $roots)) $assert(strlen($loaded) < strlen($shared['content']), $page['source_path'] . ': the route loads fewer bytes than the shared sheet');

    // Same cascade on this route: every declaration the shared sheet applies
    // to the route's item is applied by what the route loads.
    $dom = new DOMDocument();
    $dom->loadHTML('<html><body><nav><ul><li class="foo"></li></ul></nav></body></html>');
    foreach (array('html' => 'root_attributes', 'body' => 'body_attributes') as $tag => $key) {
        foreach ($page['document_metadata'][$key] as $name => $value) $dom->getElementsByTagName($tag)->item(0)->setAttribute($name, $value);
    }
    preg_match('/wp:navigation (\{.*?\}) \/-->/', $page['canonical_block_markup'], $block);
    $nav = $dom->getElementsByTagName('nav')->item(0);
    $nav->setAttribute('class', 'wp-block-navigation ' . (json_decode($block[1] ?? '{}', true)['className'] ?? ''));
    $dom->getElementsByTagName('ul')->item(0)->setAttribute('class', 'wp-block-navigation__container');
    $item = $dom->getElementsByTagName('li')->item(0);
    $item->setAttribute('class', 'wp-block-navigation-item foo');
    $assert($armsMatching($shared['content'], $item) === $armsMatching($loaded, $item), $page['source_path'] . ': the route matches what the shared sheet matches');
}

// Views no captured route owns (archives, the editor's frame fallback) still
// load the whole shared sheet.
$assert(isset($byTarget[$shared['target_path']]) && str_contains($byTarget[$shared['target_path']]['content'], $gates[0][0]), 'The shared sheet keeps every route variant');
echo "Route-gated stylesheet delivery passed\n";
