<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Reduced from mebenedetto's native responsive header and captured Core CSS.
$html = <<<'HTML'
<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body>
<nav class="wp-block-navigation items-justified-right">
<button class="wp-block-navigation__responsive-container-open">Open</button>
<div class="wp-block-navigation__responsive-container"><div class="wp-block-navigation__responsive-container-content">
<ul class="wp-block-navigation__container"><li class="wp-block-navigation-item has-child"><a class="wp-block-navigation-item__content" href="#">Links</a>
<ul class="wp-block-navigation__submenu-container"><li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="https://example.com">GitHub</a></li></ul>
</li></ul></div></div></nav></body></html>
HTML;
$css = <<<'CSS'
.wp-block-navigation .wp-block-navigation-item { align-items: center; background-color: inherit; display: flex; position: relative; }
.wp-block-navigation__responsive-container.is-menu-open:where(:not(.disable-default-overlay)) .wp-block-navigation__responsive-container-content .wp-block-navigation-item, .wp-block-navigation__responsive-container.is-menu-open:where(:not(.disable-default-overlay)) .wp-block-navigation__responsive-container-content .wp-block-navigation__container, .wp-block-navigation__responsive-container.is-menu-open:where(:not(.disable-default-overlay)) .wp-block-navigation__responsive-container-content .wp-block-page-list { align-items: var(--navigation-layout-justification-setting,initial); display: flex; flex-direction: column; }
CSS;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'style.css' => $css)))->toArray();
if (!str_contains($result['serialized_blocks'], 'wp:navigation-submenu')) throw new RuntimeException('Fixture must compile to a native submenu');

// Runtime items deliberately have no source-class or importer item markers.
$runtime = new DOMDocument();
$runtime->loadHTML(str_replace('class="wp-block-navigation__responsive-container"', 'class="wp-block-navigation__responsive-container is-menu-open"', $html));
$item = $runtime->getElementsByTagName('li')->item(1);
$centers = array();
$overlays = array();
foreach ($result['source_reports']['compiled_site']['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    (new CssStylesheetTransformer())->transform($asset['content'], static function (string $prelude, string $body) use ($item, &$centers, &$overlays): string {
        if (!preg_match('/align-items\s*:\s*(center|var\(--navigation-layout-justification-setting,\s*initial\))/', $body, $value)) return $prelude;
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            if (!$parsed['supported'] || !CssSelectorMatcher::matches($item, $parsed)['matches']) continue;
            if ('center' === $value[1]) $centers[] = CssSelectorMatcher::specificity($parsed);
            elseif (str_contains($selector, '.is-menu-open') && str_contains($selector, '.wp-block-navigation__responsive-container-content')) $overlays[] = CssSelectorMatcher::specificity($parsed);
        }
        return $prelude;
    });
}
if (array() === $centers || array() === $overlays || max($overlays) < max($centers)) {
    throw new RuntimeException('Runtime submenu overlay must match and outrank every compiled center rule');
}
echo "Core navigation overlay precedence passed\n";
