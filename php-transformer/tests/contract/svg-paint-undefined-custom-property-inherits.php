<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// A paint that reads only undefined custom properties is invalid at
// computed-value time, so the inherited property behaves as `unset` and the
// glyph takes its ancestor's paint. The standalone image must restate that
// inherited paint instead of defaulting to black. A defined custom property
// keeps winning, and a declaration the compiler cannot resolve still bakes
// nothing.
$svgFill = static function (string $css): ?string {
    $html = '<html><head><style>' . $css . '</style></head><body><main><p>Account</p>'
        . '<div class="badge"><div class="glyph"><svg viewBox="0 0 50 50" width="50" height="50"><path d="M25 0a25 25 0 1 0 0 50a25 25 0 1 0 0-50"/></svg></div></div>'
        . '</main></body></html>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    foreach ($result['assets'] ?? array() as $asset) {
        $content = (string) ($asset['content'] ?? '');
        if (!str_contains($content, 'M25 0a25')) continue;
        preg_match('/<svg\b[^>]*>/', $content, $tag);
        return preg_match('/fill:\s*([^;"]+)/', $tag[0] ?? '', $fill) ? trim($fill[1]) : '';
    }
    return null;
};

$inherited = $svgFill('.badge{fill:#130022}.glyph{fill:var(--glyph-fill-override,var(--glyph-fill))}');
if ('#130022' !== $inherited) {
    throw new RuntimeException('A paint reading only undefined custom properties must inherit the ancestor paint, got ' . var_export($inherited, true) . '.');
}
$defined = $svgFill(':root{--glyph-fill:#333333}.badge{fill:#130022}.glyph{fill:var(--glyph-fill-override,var(--glyph-fill))}');
if ('#333333' !== $defined) {
    throw new RuntimeException('A defined custom property paint must still win, got ' . var_export($defined, true) . '.');
}
$partial = $svgFill(':root{--tone:51,51,51}.badge{fill:#130022}.glyph{fill:rgb(var(--tone),var(--alpha))}');
if ('' !== $partial) {
    throw new RuntimeException('A paint reading a defined but unresolvable value must not bake an ancestor paint, got ' . var_export($partial, true) . '.');
}

echo "svg-paint-undefined-custom-property-inherits: ok\n";
