<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
$document = static fn(string $title): string => '<html><head><title>' . $title . '</title></head><body><header><p>Brand</p></header><main class="page-frame"><h1>' . $title . '</h1><div class="body-column"><p>A substantial opening paragraph for ' . $title . ' with unique content.</p><p>More source text.</p></div></main><footer><p>Copyright</p></footer></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $document('Home'), 'about.html' => $document('About'), 'services.html' => $document('Services'))))->toWordPressSitePlanView()['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$failures = 0;
$assert = static function(bool $pass, string $message) use (&$failures): void { if (!$pass) { ++$failures; fwrite(STDERR, "FAIL: $message\n"); } };
$assert(str_contains($templates['page'], 'wp:post-title'), 'new page has native title');
$assert(str_contains($templates['page'], 'body-column'), 'new page inherits source body frame');
$assert(!str_contains($templates['page'], 'substantial opening'), 'new page does not copy source body');
foreach ($plan['pages'] as $page) {
    if (!empty($page['entrypoint'])) continue;
    $slug = $page['route_template'] ?? 'page';
    $assert('page' !== $slug, 'captured page keeps a separate content-owned template');
    $assert(!str_contains($templates[$slug], 'wp:post-title'), 'captured title is not duplicated');
    $assert(str_contains($page['canonical_block_markup'], 'substantial opening'), 'captured body remains unchanged');
}
exit($failures ? 1 : 0);
