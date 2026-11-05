<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$path = 'M0,0.5 A0.5 0.5, 0 0 1, 1 0.5 M1,0.5 A0.5 0.5, 0 0 1, 0 0.5 Z';
$rows = array(
    'issue 4434' => array('<path d="' . $path . '"/>', 'ellipse(50% 50% at 50% 50%)'),
    'unrecognized transformed path' => array('<path transform="rotate(25 .5 .5)" d="M0 0 L1 0 L0 1 Z"/>', 'data:image/svg+xml,'),
    'bbox polygon' => array('<polygon points="0,0 1,0 0,1"/>', 'polygon(0% 0%, 100% 0%, 0% 100%)'),
    'bbox circle' => array('<circle cx=".5" cy=".5" r=".5"/>', 'ellipse(50% 50% at 50% 50%)'),
    'adjacent semicircular arcs' => array('<path d="M0 .5 A.5 .5 0 0 1 1 .5 A.5 .5 0 0 1 0 .5Z"/>', 'ellipse(50% 50% at 50% 50%)'),
    'user-space path' => array('<path d="M0 0 L100 0 L0 100 Z"/>', 'path("M0 0 L100 0 L0 100 Z")', 'userSpaceOnUse'),
    'bbox curve cannot use pixel path' => array('<path d="M0 0 C0 1 1 0 1 1 Z"/>', 'data:image/svg+xml,'),
    'visible icon' => array(null, null),
);
foreach ($rows as $name => $row) {
    list($shape, $expected) = $row;
    $units = $row[2] ?? 'objectBoundingBox';
    $svg = null === $shape ? '<svg width="24" height="24" viewBox="0 0 24 24"><path d="M0 0 L24 0 L0 24 Z"/></svg>' : '<svg width="0" height="0" style="position:absolute"><defs><clipPath id="m1" clipPathUnits="' . $units . '">' . $shape . '</clipPath></defs></svg>';
    $html = '<!doctype html><html><body><div class="fluid"><div class="wrap"><img src="https://example.com/a.jpg" alt="I"></div>' . $svg . '</div><style>.fluid .wrap { clip-path: url("#m1"); overflow: hidden }</style></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = $result['serialized_blocks'];
    if (!str_contains($markup, '<!-- wp:image') || str_contains($markup, 'svg-artwork') || str_contains($markup, '<!-- wp:html')) {
        throw new RuntimeException($name . ': expected native image(s), no HTML or SVG companion');
    }
    $assets = $result['source_reports']['wordpress_site_plan']['assets'] ?? array();
    if (null === $expected) {
        if (!str_contains($markup, 'blocks-engine-synthetic-svg-paragraph') || !array_filter($assets, static fn ($asset) => str_contains($asset['content'] ?? '', 'M0 0 L24 0 L0 24 Z'))) {
            throw new RuntimeException($name . ': visible SVG must still materialize as an image asset');
        }
        continue;
    }
    if ('data:image/svg+xml,' === $expected) {
        $css = implode("\n", array_column(array_filter($assets, static fn ($asset) => 'css' === ($asset['kind'] ?? '')), 'content'));
        if (!preg_match('/data:image\/svg\+xml,([^"\s]+)#m1/', $css, $resource)
            || !str_contains(rawurldecode($resource[1]), rtrim(substr($shape, 0, -2)) . '>')
            || !str_contains(rawurldecode($resource[1]), 'clipPathUnits="objectBoundingBox"')) {
            throw new RuntimeException($name . ': stylesheet resource lost exact SVG geometry or units');
        }
    }
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $matched = false;
    foreach ($assets as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function ($selector, $body) use ($expected, $dom, &$matched): void {
            if (!str_contains($body, $expected)) return;
            foreach ($dom->getElementsByTagName('*') as $element) {
                if (str_contains(' ' . $element->getAttribute('class') . ' ', ' wrap ') && CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector))['matches']) {
                    $matched = true;
                }
            }
        });
    }
    if (!$matched) throw new RuntimeException($name . ': missing exact crop on wrapper selector');
}
echo "Definitions-only SVG compiler contracts passed\n";
