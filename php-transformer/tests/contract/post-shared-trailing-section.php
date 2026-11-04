<?php
declare(strict_types=1);

// A section repeated identically after each entry's article belongs to the
// single-post template, complete and in source order: none of it (including
// its media) leaks into the post body (#4224).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, mixed $context = null): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n" . (null === $context ? '' : json_encode($context, JSON_UNESCAPED_SLASHES) . "\n")); exit(1); } };
$root = dirname(__DIR__) . '/fixtures/shared-trailing-section-posts';
$files = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
}
$plan = (new WordPressSitePlan())->fromCompilerResult((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray());
WordPressSitePlan::assertValid($plan);
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
$posts = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'post' === $page['post_type']));
$assert(3 === count($posts), 'All three entries are posts.', array_column($plan['pages'], 'post_type', 'source_path'));
foreach ($posts as $post) {
    $slug = basename(dirname($post['source_path']));
    $body = $post['canonical_block_markup'];
    $assert(str_contains($body, 'Body copy for ' . $slug . '.'), "{$slug}: the article body stays in post content", $body);
    $assert(!str_contains($body, 'c-donation'), "{$slug}: no element of the shared section stays in post content", $body);
    $template = $templates[$post['route_template'] ?? 'single'] ?? '';
    $content = strpos($template, '<!-- wp:post-content');
    $section = strpos($template, 'class="wp-block-group c-donation"');
    $assert(false !== $content && false !== $section && $content < $section, "{$slug}: the template carries the shared section after post-content", $template);
    $trailing = substr($template, $section);
    foreach (array('c-donation-media', '<img ', 'Support us', 'c-donation-list', '50 <small>DKK</small>', '100 <small>DKK</small>') as $needle) {
        $assert(str_contains($trailing, $needle), "{$slug}: the shared section is complete ({$needle})", $template);
    }
    $rendered = preg_replace('/<!-- wp:post-content(?:\s+\{.*?\})?\s*\/-->/', $body, $template, 1);
    $assert(strpos($rendered, 'Body copy for ' . $slug) < strpos($rendered, 'c-donation-media'), "{$slug}: the rendered entry keeps source order", $rendered);
}
fwrite(STDOUT, "post-shared-trailing-section: ok\n");
