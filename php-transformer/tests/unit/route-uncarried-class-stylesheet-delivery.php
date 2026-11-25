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

// One author sheet every route links styles each route's own content. A
// route loads its own copy of the sheet: the rules for classes only another
// route's markup carries match nothing there, so its copy must not ship them
// (issue #6145, theme_css_weight).
$css = '.lead{font-size:20px;line-height:1.6;color:#123456}';
$menu = $gallery = '';
for ($i = 0; $i < 40; ++$i) {
    $css .= '.dish-' . $i . '{padding:' . $i . 'px;border:1px solid #c9a24d;letter-spacing:' . ($i / 100) . 'px}.dish-' . $i . ':hover{background-color:#f1e8d0;box-shadow:0 0 0 4px #f6efe0}';
    $css .= '.photo-' . $i . '{margin:' . $i . 'px;border-radius:' . $i . 'px;opacity:.9}@media (min-width:640px){.photo-' . $i . '{margin:' . (2 * $i) . 'px}}';
    $menu .= '<p class="dish-' . $i . '">Dish ' . $i . '</p>';
    $gallery .= '<p class="photo-' . $i . '">Photo ' . $i . '</p>';
}
$page = static fn(string $title, string $main): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body><nav><a href="/index.html">Home</a> <a href="/menu/index.html">Menu</a> <a href="/gallery/index.html">Gallery</a></nav><main>' . $main . '</main></body></html>';
$files = array(
    'index.html' => $page('Home', '<p class="lead">Welcome</p>'),
    'menu/index.html' => $page('Menu', '<p class="lead">Menu</p>' . $menu),
    'gallery/index.html' => $page('Gallery', '<p class="lead">Gallery</p>' . $gallery),
    'style.css' => $css,
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$byTarget = array_column($plan['assets'], null, 'target_path');
$shared = array_values(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind'] && !empty($asset['stylesheet_instances']) && str_contains((string) ($asset['content'] ?? ''), '.dish-')));
$assert(1 === count($shared), 'Fixture keeps one shared author sheet');
$shared = $shared[0];
$assert(str_contains($shared['content'], '.photo-') && str_contains($shared['content'], '.lead'), 'The shared sheet keeps every route\'s rules');

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

$own = array('index.html' => null, 'menu/index.html' => 'dish-', 'gallery/index.html' => 'photo-');
$checked = 0;
foreach ($plan['pages'] as $document) {
    $source = $document['source_path'];
    if (!array_key_exists($source, $own)) continue;
    $loaded = '';
    foreach (ThemeBootstrap::documentStylesheets($plan, $source) as $sheet) {
        $asset = $byTarget[$sheet['target_path']] ?? null;
        if (null !== $asset && str_contains((string) ($asset['content'] ?? ''), '.lead')) $loaded .= $asset['content'];
    }
    $assert('' !== $loaded, $source . ': the route loads the author sheet');
    ++$checked;
    foreach (array('dish-', 'photo-') as $prefix) {
        if ($prefix === $own[$source]) {
            $assert(str_contains($loaded, '.' . $prefix . '39'), $source . ': the route keeps the rules for its own .' . $prefix . ' content');
        } else {
            $assert(!str_contains($loaded, '.' . $prefix), $source . ': the route loads no rule for another route\'s .' . $prefix . ' content');
        }
    }
    $assert(strlen($loaded) < strlen($shared['content']), $source . ': the route loads fewer bytes than the shared sheet');

    // Same cascade on this route for its own content.
    $dom = new DOMDocument();
    $dom->loadHTML('<html><body><main><p class="lead">Text</p>' . ('dish-' === $own[$source] ? $menu : ('photo-' === $own[$source] ? $gallery : '')) . '</main></body></html>');
    foreach ($document['document_metadata']['root_attributes'] ?? array() as $name => $value) $dom->documentElement->setAttribute($name, $value);
    foreach ($dom->getElementsByTagName('p') as $item) {
        $assert($armsMatching($shared['content'], $item) === $armsMatching($loaded, $item), $source . ': the route matches what the shared sheet matches on .' . $item->getAttribute('class'));
    }
}
$assert(3 === $checked, 'Every route was checked');
echo "Route uncarried-class stylesheet delivery passed\n";
