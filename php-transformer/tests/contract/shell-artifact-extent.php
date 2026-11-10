<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Unlabeled chrome detection can claim an off-canvas drawer that holds the
// navigation; the source-shared header artifact covers only the landmark.
$group = static fn(string $class, string $inner): string => '<!-- wp:group {"className":"' . $class . '"} --><div class="wp-block-group ' . $class . '">' . $inner . '</div><!-- /wp:group -->';
$paragraph = static fn(string $text): string => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$pages = array();
foreach (array('index.html' => 'a', 'about.html' => 'b') as $path => $geometry) {
    $drawer = $group('slideout be-inline-geometry-' . $geometry, $paragraph('Shopping Cart') . $paragraph('Home'));
    $pages[] = array('source_path' => $path, 'canonical_block_markup' => $drawer, 'shell_candidates' => array(
        array('area' => 'header', 'markup' => $drawer, 'identity_markup' => $drawer, 'template_part_markup' => $drawer, 'source_path' => $path, 'offset' => 0, 'length' => strlen($drawer)),
    ));
}
$artifact = array('area' => 'header', 'canonical_block_markup' => $group('site-header', $paragraph('Home')), 'placement' => array('variant' => 1, 'equal_source_identity' => true, 'source_paths' => array('index.html', 'about.html')));
foreach ((new ShellExtraction(new WordPressSitePlan()))->withSourceSharedShellIdentity($pages, array($artifact)) as $page) {
    if (!str_contains($page['shell_candidates'][0]['template_part_markup'], 'Shopping Cart')) throw new RuntimeException("{$page['source_path']}: a landmark-only artifact must not replace chrome it does not cover.");
}
