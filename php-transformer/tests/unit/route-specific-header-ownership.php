<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Non-equivalent navigation-dialog headers retain their route presentation,
// but belong to theme chrome rather than editable page content.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><h1>Home</h1></main>',
    'about.html' => '<main><h1>About</h1></main>',
)))->toArray();
foreach ($result['source_reports']['compiled_site']['pages'] as &$page) {
    $label = 'index.html' === $page['source_path'] ? 'Home brand' : 'About brand';
    $page['block_markup'] = '<!-- wp:group --><div class="wp-block-group">'
        . '<!-- wp:group {"tagName":"header"} --><header class="wp-block-group">'
        . '<!-- wp:paragraph --><p>' . $label . '</p><!-- /wp:paragraph -->'
        . '<!-- wp:group {"blocksEngineDialog":{"schema":"blocks-engine/dialog/v1"}} --><div class="wp-block-group data-blocks-engine-captured-dialog"><!-- wp:paragraph --><p>Menu</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
        . '</header><!-- /wp:group -->'
        . '<!-- wp:paragraph --><p>Route content</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->';
}
unset($page);
$plan = (new WordPressSitePlan())->fromResult($result);
foreach ($plan['pages'] as $page) {
    if (str_contains($page['canonical_block_markup'], '<header')) throw new RuntimeException('Route header remains page-owned');
    if (!str_contains($page['canonical_block_markup'], 'Route content')) throw new RuntimeException('Route content was lost');
    $label = 'index.html' === $page['source_path'] ? 'Home brand' : 'About brand';
    $found = false;
    foreach ($plan['template_parts'] as $part) {
        if (!str_contains($part['canonical_block_markup'], $label)) continue;
        if (!str_contains($page['canonical_block_markup'], '"slug":"' . $part['slug'] . '"')) throw new RuntimeException('Route header reference missing');
        $found = true;
    }
    if (!$found) throw new RuntimeException('Route header content was lost');
}
fwrite(STDOUT, "Route-specific header ownership passed\n");
