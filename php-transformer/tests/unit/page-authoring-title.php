<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\TemplateSlugs;

$view = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<title>Home | Studio</title><main><h1>Welcome</h1><p>Home body.</p></main>',
        'access.html' => '<title>Accessibility | Studio</title><main><h1>Accessibility</h1><p>Access body.</p></main>',
        'about.html' => '<title>About | Studio</title><main><p>An intentionally heading-free page.</p></main>',
    ),
))->toWordPressSitePlanView();
$plan = $view['wordpress_site_plan'];
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { ++$failures; fwrite(STDERR, "FAIL: $message\n"); }
};
$assert(1 === substr_count($templates['page'], '<!-- wp:post-title '), 'a new page renders its native title once');
$assert(1 === substr_count($templates['page'], '<!-- wp:post-content'), 'a new page renders its body once');
foreach ($plan['pages'] as $page) {
    $key = TemplateSlugs::forPage($page);
    $assert(isset($templates[$key]), 'each captured page has its original presentation template');
    $assert(!str_contains($templates[$key] ?? '', '<!-- wp:post-title'), 'captured pages do not gain duplicate or unwanted titles');
    if ('access.html' === $page['source_path']) $assert(str_contains($page['canonical_block_markup'], 'Accessibility</h1>'), 'the captured heading retains its presentation');
}
exit($failures ? 1 : 0);
