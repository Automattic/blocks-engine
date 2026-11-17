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
echo "Shared frame route cascade passed\n";
