<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

function add_filter(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool { $GLOBALS['title_filters'][$hook][] = $callback; return true; }
function add_action(string $hook, mixed $callback, int $priority = 10, int $args = 1): bool { return true; }
function remove_action(string $hook, mixed $callback, int $priority = 10): bool { return true; }
function is_page(int $id): bool { return 42 === $id; }
function get_queried_object_id(): int { return 42; }
function get_post_type(int $id): string { return $GLOBALS['title_type'] ?? 'page'; }
function get_post_meta(int $id, string $key, bool $single): string { return $GLOBALS['title_identity'] ?? ''; }
function get_page_template_slug(int $id): string { return $GLOBALS['title_template'] ?? ''; }
function get_the_title(int $id): string { return 'New page'; }
function render_block(array $block): string {
    if ('core/post-title' !== $block['blockName'] || 1 !== $block['attrs']['level']) throw new RuntimeException('Use the native H1 title renderer.');
    return '<h1 class="wp-block-post-title">New page</h1>';
}

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<main><h1>Home</h1><p>Welcome.</p></main>',
        'faqs.html' => '<main><h1>FAQs</h1><p>Answers.</p></main>',
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$rendered = ThemeTemplates::rendered($plan);
$assert(!isset($rendered['page-faqs']), 'Adding the authoring title must not create route overrides.');
$assert(!str_contains($rendered['page'], 'wp:post-title'), 'Captured templates keep their original structure.');
foreach ($plan['writes'] as $write) if ('functions.php' === $write['target_path']) eval('?>' . ($write['payload']['data'] ?? $write['canonical_payload']));
$filters = $GLOBALS['title_filters']['render_block_core/post-content'] ?? array();
$assert(1 === count($filters), 'The generated theme supplies a title for new pages.');
$filter = $filters[0];
$instance = (object) array('context' => array('postId' => 42));
$content = '<div class="wp-block-post-content"><p>New content</p></div>';
$assert('<h1 class="wp-block-post-title">New page</h1>' . $content === $filter($content, array(), $instance), 'New default-template pages render one native title.');
$GLOBALS['title_identity'] = 'captured-page';
$assert($content === $filter($content, array(), $instance), 'Captured pages, even without H1s, remain byte-identical.');
unset($GLOBALS['title_identity']);
$GLOBALS['title_template'] = 'custom-layout';
$assert($content === $filter($content, array(), $instance), 'Explicit custom layouts own their title slots.');
unset($GLOBALS['title_template']);
$heading = '<h1>Authored heading</h1>' . $content;
$assert($heading === $filter($heading, array(), $instance), 'An authored H1 is not duplicated.');
$GLOBALS['title_type'] = 'post';
$assert($content === $filter($content, array(), $instance), 'Article content is unaffected.');
echo "Page authoring title: ok\n";
