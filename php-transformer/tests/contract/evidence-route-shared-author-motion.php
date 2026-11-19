<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EvidenceRouteStylesheets;

/*
 * #6729: a site whose author sheet legacy routes still link retires it only
 * on its evidence routes. Those routes must still get what the box-tree
 * carriers cannot replace — here a finite entrance animation the carrier
 * binds by name only (`animation-name:imb-up`), whose duration and fill mode
 * live in the resting `.imb-anim-up` rule and whose keyframes live in the
 * author sheet. Without them the animation never registers on the route.
 */
$assert = static function (bool $ok, string $message, string $detail = ''): void {
    if (!$ok) throw new RuntimeException($message . ('' !== $detail ? ': ' . $detail : ''));
    echo "PASS: $message\n";
};
$evidencePage = array(
    'source_path' => 'menu/index.html',
    'post_type' => 'page',
    'route' => array('path' => '/menu/'),
    'reconciliation_identity' => 'menu',
    'document_metadata' => array('engine_owned_styles' => true),
    'canonical_block_markup' => '<!-- wp:group {"className":"imb-anim-up be-inline-geometry-1"} --><div class="wp-block-group imb-anim-up be-inline-geometry-1">Our Menus</div><!-- /wp:group -->',
);
$legacyPage = array(
    'source_path' => 'booking/index.html',
    'post_type' => 'page',
    'route' => array('path' => '/booking/'),
    'reconciliation_identity' => 'booking',
    'document_metadata' => array(),
    'canonical_block_markup' => '<p class="imb-anim-up">Book</p>',
);
$authorCss = '.container{padding:1rem}.imb-anim-up{animation:.9s cubic-bezier(.2,.7,.2,1) both imb-up}'
    . '@keyframes imb-up{0%{opacity:0}to{opacity:1}}@keyframes unused{to{opacity:0}}.hover\:underline:hover{text-decoration-line:underline}';
$assets = array(
    array('kind' => 'css', 'source' => 'engine-support', 'source_path' => 'engine-before.css', 'target_path' => 'assets/css/engine-support-before-author.css', 'content' => '.be-inline-geometry-1.be-inline-geometry-1{display:block;animation-name:imb-up}', 'scopes' => array(array('kind' => 'page', 'source_path' => 'menu/index.html'))),
    array('kind' => 'css', 'source' => 'files', 'source_path' => 'assets/index.css', 'target_path' => 'assets/website/assets/index.css', 'token' => 'asset-author', 'content' => $authorCss, 'scopes' => array(array('kind' => 'global')), 'stylesheet_instances' => array(array('source_path' => 'menu/index.html', 'order' => 0, 'media' => ''), array('source_path' => 'booking/index.html', 'order' => 0, 'media' => ''))),
);

$retired = EvidenceRouteStylesheets::retire($assets, array($evidencePage, $legacyPage));
$byTarget = array_column($retired, null, 'target_path');
$author = $byTarget['assets/website/assets/index.css'] ?? null;
$sibling = $byTarget['assets/website/assets/index-evidence-routes.css'] ?? null;
$assert(null !== $author && $authorCss === $author['content'], 'legacy routes keep the whole author sheet');
$assert(array('booking/index.html') === array_column($author['scopes'], 'source_path') && array('booking/index.html') === array_column($author['stylesheet_instances'], 'source_path'), 'the author sheet is linked only on legacy routes', (string) json_encode($author['scopes']));
$assert(null !== $sibling, 'evidence routes get the retained rules of a sheet legacy routes keep', implode(', ', array_keys($byTarget)));
$assert(array('menu/index.html') === array_column($sibling['scopes'], 'source_path'), 'the retained sheet is scoped to the evidence routes', (string) json_encode($sibling['scopes']));
$assert(array_search($sibling, $retired, true) === array_search($author, $retired, true) + 1, 'the retained sheet keeps the author sheet cascade position');
$assert(str_contains($sibling['content'], '.imb-anim-up{animation:.9s cubic-bezier(.2,.7,.2,1) both imb-up}'), 'a resting rule applying a carried animation keeps its motion declarations', $sibling['content']);
$assert(str_contains($sibling['content'], '@keyframes imb-up{') && !str_contains($sibling['content'], 'unused'), 'carried keyframes are retained and unbound keyframes dropped', $sibling['content']);
$assert(str_contains($sibling['content'], '.hover\:underline:hover{') && !str_contains($sibling['content'], 'padding:1rem'), 'state rules are retained and resting rules retired', $sibling['content']);
$assert($sibling['token'] !== $author['token'] && $sibling['source_path'] !== $author['source_path'], 'the retained sheet is its own asset');
