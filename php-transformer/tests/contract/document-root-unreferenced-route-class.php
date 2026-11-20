<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

function apply_filters(string $hook, mixed $value): mixed { return $value; }
function is_singular(): bool { return true; }
function get_queried_object_id(): int { return 1; }
function get_post_meta(int $id, string $key, bool $single): string { return $GLOBALS['captured_identity']; }
function is_front_page(): bool { return false; }
function is_category(): bool { return false; }
function is_tag(): bool { return false; }
function is_tax(): bool { return false; }
function is_author(): bool { return false; }
function is_date(): bool { return false; }

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

// Every captured route carries a route class on its root (`be-r-…`), which
// route-variant CSS gates rules on. Only one route here has such a rule. The
// other routes' classes match nothing, yet each made its root attributes
// unique and its registry row one more per-route entry in functions.php.
$pages = array();
foreach (array('one', 'two', 'three') as $slug) {
    $pages[] = array('source_path' => $slug . '.html', 'post_type' => 'post', 'route' => array('path' => '/2020/01/01/' . $slug), 'reconciliation_identity' => hash('sha256', $slug), 'canonical_block_markup' => '<!-- wp:paragraph --><p>' . $slug . '</p><!-- /wp:paragraph -->', 'metadata' => array(), 'document_metadata' => array('root_attributes' => array('lang' => 'en', 'class' => 'be-r be-r-' . substr(hash('sha256', $slug), 0, 8)), 'body_attributes' => array()));
}
$pages[] = array('source_path' => 'about.html', 'post_type' => 'page', 'route' => array('path' => '/about'), 'reconciliation_identity' => hash('sha256', 'about'), 'canonical_block_markup' => '', 'metadata' => array(), 'document_metadata' => array('root_attributes' => array('lang' => 'en', 'class' => 'be-r be-r-' . substr(hash('sha256', 'about'), 0, 8)), 'body_attributes' => array()));
$gated = 'be-r-' . substr(hash('sha256', 'one'), 0, 8);
$assets = array(array('kind' => 'css', 'source_path' => 'site.css', 'target_path' => 'assets/site.css', 'content_hash' => hash('sha256', 'site'), 'content' => ':where(:root:is(.' . $gated . ')) p{color:red}', 'scopes' => array(array('kind' => 'global'))));
$bootstrap = ThemeBootstrap::functionsPhp($assets, array(), array(), array(), array(), $pages);

$assert(str_contains($bootstrap, $gated), 'A route class that CSS gates on is kept.');
foreach (array('two', 'three', 'about') as $slug) $assert(!str_contains($bootstrap, 'be-r-' . substr(hash('sha256', $slug), 0, 8)), 'The unreferenced route class of ' . $slug . ' is not emitted.');
$assert(!preg_match("/'identity' => '[0-9a-f]{64}'/", $bootstrap) && !str_contains($bootstrap, '2020/01/01/'), 'Registry rows are positional and posts carry no route path.');

$start = strpos($bootstrap, 'global $blocks_engine_document_roots');
$end = strpos($bootstrap, "add_filter( 'language_attributes'", $start);
$assert(false !== $start && false !== $end, 'The document resolver is emitted.');
eval(substr($bootstrap, $start, $end - $start) . "\n}");
$attributes = $GLOBALS['blocks_engine_document_attributes'];
$GLOBALS['captured_identity'] = hash('sha256', 'one');
$assert(array('lang' => 'en', 'class' => 'be-r ' . $gated) === $attributes('html'), 'The gated route resolves its route class.');
$GLOBALS['captured_identity'] = hash('sha256', 'two');
$assert(array('lang' => 'en', 'class' => 'be-r') === $attributes('html'), 'Another route keeps the route marker and its other attributes.');
$assert(4 === count($GLOBALS['blocks_engine_document_roots']), 'Every route keeps its registry row.');
fwrite(STDOUT, "document-root-unreferenced-route-class: ok\n");
