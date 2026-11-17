<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SharedChromeStylesheets;

// A frame is transported to a template, but remains around the content slot.
// Its authored rules must not move after unrelated route rules in the cascade.
$css = '.document-frame{position:relative}.document-frame:after{content:"";position:fixed;inset:0}';
$assets = array(array('kind' => 'css', 'content' => $css, 'source_path' => 'style.css', 'path' => 'style.css', 'target_path' => 'assets/style.css'));
$parts = array(array(
    'slug' => 'page-frame',
    'placement' => array('kind' => 'shared_shell'),
    'canonical_block_markup' => '<!-- wp:group {"tagName":"main","className":"document-frame"} --><main class="wp-block-group document-frame"></main><!-- /wp:group -->',
));
$projected = SharedChromeStylesheets::project($assets, $parts);
if ($projected !== $assets) throw new RuntimeException('Template frame styles must retain their route ownership and source order');
// The frame also contains chrome beside the slot. Its generated marker must
// stay shared, rather than being mistaken for a wrapper of route content.
$marker = 'blocks-engine-control-0123456789ab-1';
$assets[0]['content'] .= '.' . $marker . '{border-radius:99px}';
$parts[0]['canonical_block_markup'] = '<!-- wp:group {"className":"document-frame"} --><div class="wp-block-group document-frame"><!-- wp:post-content /--><!-- wp:button {"className":"' . $marker . '"} --><div class="wp-block-button ' . $marker . '"><a class="wp-block-button__link">Call</a></div><!-- /wp:button --></div><!-- /wp:group -->';
$projected = SharedChromeStylesheets::project($assets, $parts);
$shared = array_filter($projected, static fn(array $asset): bool => str_ends_with((string) ($asset['source_path'] ?? ''), '.shared-chrome'));
if (1 !== count($shared) || !str_contains(reset($shared)['content'], $marker)) throw new RuntimeException('Frame sibling chrome must retain site-wide marker rules');
if (!str_contains($projected[0]['content'], '.document-frame:after') || str_contains($projected[0]['content'], $marker)) throw new RuntimeException('Only content ancestors keep route ownership');
echo "Shared frame route cascade passed\n";
