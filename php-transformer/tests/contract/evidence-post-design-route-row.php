<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// Posts whose styles are evidence-owned (box-tree reduction) replay their
// design's stylesheet sequence, delivered from one representative post. Every
// other post of the design matched that design row already, yet each got its
// own identity-tested route row with an empty sequence: one bootstrap line per
// imported post. The posts of one design share one route row.
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$pages = $instances = array();
foreach (array(1, 2, 3, 4) as $n) {
    $pages[] = array('source_path' => "post-$n.html", 'post_type' => 'post', 'route' => array('path' => "/post-$n"), 'reconciliation_identity' => hash('sha256', "post-$n"), 'canonical_block_markup' => '', 'metadata' => array(), 'document_metadata' => array('engine_owned_styles' => true));
    $instances[] = array('source_path' => "post-$n.html", 'order' => 0, 'media' => '');
}
$pages[] = array('source_path' => 'about.html', 'post_type' => 'page', 'route' => array('path' => '/about'), 'reconciliation_identity' => hash('sha256', 'about'), 'canonical_block_markup' => '', 'metadata' => array(), 'document_metadata' => array('engine_owned_styles' => true));
$instances[] = array('source_path' => 'about.html', 'order' => 0, 'media' => '');
$assets = array(array('kind' => 'css', 'source_path' => 'author.css', 'target_path' => 'assets/author.css', 'content_hash' => hash('sha256', 'author'), 'scopes' => array(array('kind' => 'global')), 'stylesheet_instances' => $instances));
$bootstrap = ThemeBootstrap::functionsPhp($assets, array(), array(), array(), array(), $pages);
preg_match_all('/^\s*if \( (.*) \) \$blocks_engine_style_routes\[\] = /m', $bootstrap, $rows);
$assert(array() === preg_grep("/_blocks_engine_reconciliation_identity', true \)$/", $rows[1]) && !preg_grep("/is_singular\( 'post' \) && '[0-9a-f]{64}'/", $rows[1]), 'No post is selected for a route row by its identity.');
$assert(1 === count(preg_grep("/get_page_template_slug/", $rows[1])), 'The posts of the single design share one route row.');
$assert(2 === count($rows[1]), 'One row for the post design and one for the page.');
$assert(str_contains($bootstrap, "'assets/author.css'"), 'The design row still delivers the author sheet.');
fwrite(STDOUT, "evidence-post-design-route-row: ok\n");
