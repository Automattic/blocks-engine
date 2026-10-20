<?php
declare(strict_types=1);

$loader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
$root = getenv('BE_TRANSFORMER_ROOT');
if (is_string($root) && '' !== $root) $loader->addPsr4('Automattic\\BlocksEngine\\PhpTransformer\\', $root . '/src', true);

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function(bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, "FAIL: $message\n"); }
};
$flatten = static function(array $blocks) use (&$flatten): array {
    $out = array();
    foreach ($blocks as $block) { $out[] = $block; array_push($out, ...$flatten($block['innerBlocks'] ?? array())); }
    return $out;
};
foreach (require dirname(__DIR__) . '/fixtures/navigation-list-box-properties.php' as $name => $source) {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $navs = array_values(array_filter($flatten($result['blocks']), static fn(array $b): bool => 'core/navigation' === $b['blockName']));
    $assert(1 === count($navs), "$name lowers to one native navigation");
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<nav class="wp-block-navigation ' . htmlspecialchars($navs[0]['attrs']['className'] ?? '') . '"><ul class="wp-block-navigation__container"></ul></nav>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $nav = $dom->getElementsByTagName('nav')->item(0);
    $facts = array();
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'] ?? '', static function(string $selectors, string $body, array $conditions) use (&$facts, $nav): void {
            // Only facts that actually select the rendering box count, not
            // declarations stranded on Core's display:contents container.
            if (!(CssSelectorMatcher::matches($nav, CssSelectorMatcher::parse($selectors))['matches'] ?? false)) return;
            foreach (array('display', 'overflow-x') as $property) {
                if (preg_match('/(?:^|;)\s*' . $property . '\s*:\s*([^;!}]+)/', $body, $m)) $facts[] = array($property, trim($m[1]), $conditions);
            }
        });
    }
    if ('hidden' === $name) {
        $none = array_keys(array_filter($facts, static fn(array $f): bool => 'display' === $f[0] && 'none' === $f[1] && array() === $f[2]));
        $flex = array_keys(array_filter($facts, static fn(array $f): bool => 'display' === $f[0] && 'flex' === $f[1] && str_contains(implode(' ', $f[2]), '1279px')));
        $assert(array() !== $none, 'narrow rendering box receives display:none outside media');
        $assert(array() !== $flex, 'desktop rendering box receives display:flex inside source media');
        $assert($none && $flex && max($none) < max($flex), 'responsive override follows base declaration');
    } elseif ('inline-rows' === $name) {
        $assert(!array_filter($facts, static fn(array $f): bool => 'display' === $f[0] && !in_array($f[1], array('flex', 'none'), true)), 'shown list keeps the native flex row instead of the source block display');
    } else {
        $assert((bool) array_filter($facts, static fn(array $f): bool => 'overflow-x' === $f[0] && 'scroll' === $f[1] && array() === $f[2]), 'scroller rendering box receives overflow-x:scroll');
    }
}
if ($failures) exit(1);
echo "Navigation list box properties passed\n";
