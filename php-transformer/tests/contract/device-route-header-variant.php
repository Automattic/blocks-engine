<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/*
 * Route-local header presentation must move out of page content even when
 * the capture contains a separate copy in each device document. Keep the
 * device wrappers in place and preserve each copy's own compiled paint.
 */
$routes = array('index.html' => '/', 'about/index.html' => '/about/', 'work/index.html' => '/work/', 'shop/index.html' => '/shop/');
$files = array();
foreach ($routes as $source => $path) {
    $items = '';
    foreach ($routes as $href) {
        $selected = $href === $path;
        $label = '/' === $href ? 'Home' : ucfirst(trim($href, '/'));
        $items .= '<li><div class="item' . ($selected ? ' menu__item--selected' : '') . '"><a href="' . $href . '"' . ($selected ? ' aria-current="page"' : '') . '><span class="label">' . $label . '</span></a></div></li>';
    }
    $style = '/' === $path ? ' style="min-height:0;margin:0"' : '';
    $files[$source] = '<!doctype html><html><head><title>' . $source . '</title><style>#site-header{background:#eee;padding:10px}#site-header ul{display:flex;gap:12px;list-style:none}.menu__item--selected .label{color:#c00;font-weight:700}main{min-height:200px}</style></head><body><header id="site-header"' . $style . '><p class="brand">Shared brand</p><nav><ul>' . $items . '</ul></nav></header><main><h1>' . $source . '</h1><p>Route content.</p></main></body></html>';
}
foreach ($files as &$html) {
    $html = preg_replace_callback('/<body>(.*)<\/body>/s', static fn(array $match): string => '<body>'
        . '<div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope="">' . $match[1] . '</div>'
        . '<div class="data-blocks-engine-capture-mobile-document" data-blocks-engine-capture-document-scope="">' . $match[1] . '</div></body>', $html);
}
unset($html);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
foreach ($plan['pages'] as $page) {
    if (preg_match('/<header[ >]/', $page['canonical_block_markup'])) throw new RuntimeException($page['source_path'] . ' retains a copy of the header shell in page content.');
}
$headers = array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']);
if (count($headers) < 2) throw new RuntimeException('Route-local header parts are missing.');
foreach ($plan['pages'] as $page) {
    foreach (array('desktop', 'mobile') as $device) {
        if (!str_contains($page['canonical_block_markup'], 'data-blocks-engine-capture-' . $device . '-document')) {
            throw new RuntimeException('Extraction removed a device document wrapper.');
        }
    }
}
echo "device-route-header-variant: passed\n";
