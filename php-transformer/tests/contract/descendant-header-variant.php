<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Source-identical landmark wrappers do not imply identical chrome: route
// styles can change a descendant without changing the header's own attributes.
$files = array();
foreach (array('index', 'about', 'accessibility') as $slug) {
    $color = 'accessibility' === $slug ? 'blue' : 'red';
    $files[$slug . '.html'] = '<!doctype html><html><head><title>' . $slug . '</title></head><body>'
        . '<header id="site-header"><div style="color:' . $color . '"><p>Shared brand</p></div></header>'
        . '<main><h1>' . $slug . '</h1><p>Route content.</p></main></body></html>';
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
// Feed the public site-plan interface compiled landmarks with identical outer
// attributes and a differing descendant layout (the compiler's output boundary).
foreach ($result['source_reports']['compiled_site']['pages'] as &$page) {
    $page['shell_artifacts'] = array();
    $padding = 'accessibility.html' === $page['source_path'] ? '24px' : '12px';
    $page['block_markup'] = '<!-- wp:group {"anchor":"site-header","tagName":"header"} --><header id="site-header" class="wp-block-group"><!-- wp:group {"style":{"spacing":{"padding":{"top":"' . $padding . '"}}}} --><div class="wp-block-group" style="padding-top:' . $padding . '"><!-- wp:paragraph --><p>Shared brand</p><!-- /wp:paragraph --></div><!-- /wp:group --></header><!-- /wp:group --><!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:paragraph --><p>Route content.</p><!-- /wp:paragraph --></main><!-- /wp:group -->';
}
unset($page);
$plan = (new WordPressSitePlan())->fromResult($result);
WordPressSitePlan::assertValid($plan);
$parts = array_column($plan['template_parts'], null, 'slug');
foreach ($plan['pages'] as $page) {
    $markup = $page['canonical_block_markup'];
    if (str_contains($markup, '<header')) throw new RuntimeException($page['source_path'] . ' retains its header instead of an editable part.');
    if (!str_contains($markup, 'Route content.')) throw new RuntimeException('Extraction lost route content.');
}
$variant = array_filter($parts, static fn(array $part): bool => 'header' === ($part['area'] ?? null) && str_contains($part['canonical_block_markup'], '24px'));
if (1 !== count($variant)) throw new RuntimeException('The descendant presentation must survive in a route-specific header part.');
// Route-specific paint must also remain editable outside post content.
foreach ($result['source_reports']['compiled_site']['pages'] as &$page) {
    $page['block_markup'] = preg_replace('/"spacing":\{"padding":\{"top":"(?:12|24)px"\}\}/', '"color":{"background":"' . ('accessibility.html' === $page['source_path'] ? '#900' : '#123') . '"}', $page['block_markup']);
    $page['block_markup'] = str_replace(array('padding-top:12px', 'padding-top:24px'), 'background-color:' . ('accessibility.html' === $page['source_path'] ? '#900' : '#123'), $page['block_markup']);
}
unset($page);
$paintPlan = (new WordPressSitePlan())->fromResult($result);
WordPressSitePlan::assertValid($paintPlan);
$paintHeaders = array_filter($paintPlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? null));
if (2 !== count($paintHeaders)) throw new RuntimeException('Descendant paint must survive in a route-specific shell variant.');
echo "Descendant header variant: passed\n";
