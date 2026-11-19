<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;

$document = static fn(string $title): string => '<!doctype html><html><head><title>' . $title . '</title></head><body><header><nav><a href="index.html">Home</a><a href="about.html">About</a></nav></header><main><h1>' . $title . '</h1><p>' . str_repeat('Page content. ', 30) . '</p></main><footer>Shared footer</footer></body></html>';
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $document('Welcome'), 'about.html' => $document('About us')),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) { ++$failures; fwrite(STDERR, "FAIL: {$message}\n"); }
};
$assert(1 === substr_count($templates['page'], '<!-- wp:post-title '), 'new pages receive one native title');
$assert(str_contains($templates['page'], '<!-- wp:post-content /-->'), 'new pages retain an editable content slot');
foreach ($plan['pages'] as $page) {
    $slug = TemplateSlugs::forPage($page);
    $assert(!str_contains($templates[$slug] ?? $templates['page'], '<!-- wp:post-title '), 'captured routes do not acquire a duplicate title');
    $assert(str_contains($page['canonical_block_markup'], '<h1'), 'captured headings remain content-owned');
}
exit($failures ? 1 : 0);
