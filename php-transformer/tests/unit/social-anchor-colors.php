<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<main><p>Follow us</p><div class="follow"><span class="links">
<a href="https://facebook.com/example" class="profile brand-facebook btn"><span class="sr-only">Facebook</span><i aria-hidden="true" class="icon icon-facebook"></i></a>
<a href="https://instagram.com/example/" class="profile brand-instagram btn"><span class="sr-only">Instagram</span><i aria-hidden="true" class="icon icon-instagram"></i></a>
</span></div></main>';
$css = '.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
.btn{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:#cacaca;color:#5b5b5b;margin-right:8px;text-decoration:none}
.icon:before{font-family:sans-serif;content:"f"}.icon-instagram:before{content:"i"}';
$compile = static function (string $source, string $styles): array {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            array('path' => 'index.html', 'kind' => 'html', 'content' => '<link rel="stylesheet" href="style.css">' . $source),
            array('path' => 'style.css', 'kind' => 'css', 'content' => $styles),
        ),
    ))->toArray();
    $find = static function (array $blocks) use (&$find): ?array {
        foreach ($blocks as $block) {
            if ('core/social-links' === ($block['blockName'] ?? '')) return $block;
            $found = $find($block['innerBlocks'] ?? array());
            if (null !== $found) return $found;
        }
        return null;
    };
    $block = $find($result['blocks'] ?? array());
    if (null === $block) throw new RuntimeException('Expected social-links in compiled artifact');
    return $block['attrs'];
};
$attrs = $compile($html, $css);
foreach (array('customIconColor' => '#5b5b5b', 'iconColorValue' => '#5b5b5b', 'customIconBackgroundColor' => '#cacaca', 'iconBackgroundColorValue' => '#cacaca') as $key => $expected) {
    if (($attrs[$key] ?? null) !== $expected) throw new RuntimeException($key . ' must preserve source paint: ' . json_encode($attrs));
}
$preset = $compile($html, str_replace(array('#cacaca', '#5b5b5b'), array('var(--wp--preset--color--neutral)', 'var(--wp--preset--color--ink)'), $css));
if ('ink' !== ($preset['iconColor'] ?? null) || 'neutral' !== ($preset['iconBackgroundColor'] ?? null) || isset($preset['customIconColor'], $preset['customIconBackgroundColor'])) throw new RuntimeException('Preset colors must stay editable as slugs: ' . json_encode($preset));
$chainedCss = str_replace('background:#cacaca;color:#5b5b5b', '--content:var(--accent-text);background-color:var(--bg);color:var(--content)!important', $css)
    . 'main{--accent-text:#5b5b5b;--bg:#cacaca}';
$chained = $compile($html, $chainedCss);
foreach (array('customIconColor' => '#5b5b5b', 'iconColorValue' => '#5b5b5b', 'customIconBackgroundColor' => '#cacaca', 'iconBackgroundColorValue' => '#cacaca') as $key => $expected) {
    if (($chained[$key] ?? null) !== $expected) throw new RuntimeException($key . ' must preserve important chained paint: ' . json_encode($chained));
}
$brand = $compile($html, $css . '.brand-facebook{background:#0866ff}.brand-instagram{background:#e1306c}');
if (isset($brand['customIconBackgroundColor'])) throw new RuntimeException('Brand colors must remain per-service');
$logos = $compile(str_replace('<i aria-hidden="true" class="icon icon-facebook"></i>', '<svg width="16" height="16"></svg>', str_replace('<i aria-hidden="true" class="icon icon-instagram"></i>', '<svg width="16" height="16"></svg>', $html)), $css);
if (!str_contains($logos['className'] ?? '', 'is-style-logos-only') || isset($logos['customIconBackgroundColor'])) throw new RuntimeException('Logos-only must stay unchanged');
echo "Social anchor colors passed.\n";
