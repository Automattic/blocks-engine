<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$files = array();
foreach (array('index', 'about', 'visit') as $route) {
    $links = '';
    foreach (array('One', 'Two', 'Three', 'Four', 'Five', 'Six') as $label) $links .= '<a class="menu-link" href="https://example.test/' . $route . '/' . $label . '"><p>' . $label . '</p></a>';
    $files[$route . '.html'] = '<!doctype html><html><body><div class="navbar"><p>Explore</p>' . $links . '<a href="mailto:office@example.test">Email</a></div><main><h1>' . $route . '</h1></main><footer><p>Shared address</p></footer></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
foreach ($plan['template_parts'] as $part) if ('header' === $part['area']) throw new RuntimeException('Many varying links must not merge into a shared header.');
foreach ($plan['pages'] as $page) if (!str_contains($page['canonical_block_markup'], 'navbar')) throw new RuntimeException('Distinct menus must remain page-owned.');
fwrite(STDOUT, "route-edge varying-links contract passed\n");
