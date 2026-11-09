<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A fixed-width page frame encloses header, content and footer. Detached into
// shared parts, the header and footer must keep the frame's minimum width, or
// their content centered against it shifts off-screen in narrow viewports.
$compile = static function (string $frameRule): string {
    $document = static function (string $title) use ($frameRule): string {
        $inner = '<div class="frame"><header class="site-top"><p class="brand">Studio</p></header><main><h1>' . $title . '</h1><p>Route content</p></main><footer class="site-footer"><p>Copyright Studio</p></footer></div>';
        return '<!doctype html><html><head><style>' . $frameRule . '.brand{margin-left:calc((100% - 980px) / 2)}@media (max-width:600px){.data-blocks-engine-capture-desktop-document{display:none}}@media (min-width:601px){.data-blocks-engine-capture-mobile-document{display:none}}</style></head><body><div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope="">' . $inner . '</div><div class="data-blocks-engine-capture-mobile-document" data-blocks-engine-capture-document-scope="">' . str_replace('Studio</p>', 'Mobile Studio</p>', $inner) . '</div></body></html>';
    };
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $document('Home'), 'about/index.html' => $document('About'))))->toArray()['source_reports']['wordpress_site_plan'];
    $parts = array_column($plan['template_parts'], 'placement', 'slug');
    if ('shared_shell' !== ($parts['header']['kind'] ?? null) || 'shared_shell' !== ($parts['footer']['kind'] ?? null)) throw new RuntimeException('Header and footer are detached shared parts: ' . json_encode($parts));
    return implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content'));
};

$root = '.wp-site-blocks:has(>.wp-block-post-content .frame){min-width:980px}';
foreach (array(
    'unconditional' => array('.frame{min-width:980px}', $root),
    'media-scoped' => array('@media (min-width:601px){.frame{min-width:980px}}', '@media (min-width:601px){' . $root),
    'no minimum' => array('.frame{min-width:0}', null),
) as $label => [$rule, $expected]) {
    $css = $compile($rule);
    $found = null === $expected ? !str_contains($css, '.wp-site-blocks:has(>.wp-block-post-content .frame){min-width') : str_contains(str_replace(array("\n", ' {'), array('', '{'), $css), $expected);
    if (!$found) throw new RuntimeException('FAIL ' . $label . ': template root minimum width');
}
echo "detached-chrome-min-width: ok\n";
