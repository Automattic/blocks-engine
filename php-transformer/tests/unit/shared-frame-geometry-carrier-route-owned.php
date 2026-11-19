<?php
declare(strict_types=1);

/**
 * The generic page template is chosen by a frame identity that keeps geometry
 * carriers, so every route rendering it compiled those carriers and owns their
 * rules. Shared chrome is enqueued on every route, including routes rendering
 * another template: lifting a frame-only carrier there just copies its rules
 * onto pages that never render it. A carrier a shared part renders still
 * follows the part.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SharedChromeStylesheets;

$frameCarrier = 'be-inline-geometry-54f5197661e13672';
$partCarrier = 'be-inline-geometry-0123456789abcdef';
$frameRule = '.' . $frameCarrier . '.' . $frameCarrier . '>a{position:fixed;right:20px;bottom:20px}';
$partRule = '.' . $partCarrier . '.' . $partCarrier . '{display:flex}';
$assets = array(array('kind' => 'css', 'content' => $frameRule . $partRule, 'source_path' => 'route.css', 'path' => 'route.css', 'target_path' => 'assets/route.css'));
$parts = array(
    array(
        'slug' => 'footer',
        'area' => 'footer',
        'placement' => array('kind' => 'shared_shell'),
        'canonical_block_markup' => '<!-- wp:group {"className":"' . $partCarrier . '"} --><div class="wp-block-group ' . $partCarrier . '"></div><!-- /wp:group -->',
    ),
    array(
        'slug' => 'page-frame',
        'placement' => array('kind' => 'shared_shell'),
        'canonical_block_markup' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:post-content /--><!-- wp:paragraph {"className":"' . $frameCarrier . '"} --><p class="' . $frameCarrier . '"><a href="https://wa.me/1">Chat</a></p><!-- /wp:paragraph --></div><!-- /wp:group -->',
    ),
);

$projected = SharedChromeStylesheets::project($assets, $parts);
$shared = array_values(array_filter($projected, static fn(array $asset): bool => str_ends_with((string) ($asset['source_path'] ?? ''), '.shared-chrome')));
$route = array_values(array_filter($projected, static fn(array $asset): bool => 'route.css' === ($asset['source_path'] ?? null)));
if (1 !== count($shared) || !str_contains($shared[0]['content'], $partCarrier)) throw new RuntimeException('A shared part carrier must follow the part into shared chrome');
if (str_contains($shared[0]['content'], $frameCarrier)) throw new RuntimeException('A page-frame-only geometry carrier must not be copied into site-wide shared chrome');
if (1 !== count($route) || !str_contains($route[0]['content'], $frameCarrier)) throw new RuntimeException('A page-frame-only geometry carrier must keep its rule on the routes that render the frame');
echo "Shared frame geometry carrier route ownership passed\n";
