<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

// One stylesheet scoped to many pages is replayed to the editor once per
// scope, in order. Those entries differ only by their scope index, so the
// table repeated the file, handle and media once per page: one more entry per
// route for every shared sheet. A run of them is one entry with a scope list,
// and the replay loop expands it back to the same ordered sequence.
$pages = $scopes = array();
foreach (range(1, 6) as $n) {
    $page = array('source_path' => "page-$n.html", 'post_type' => 'page', 'route' => array('path' => "/page-$n"), 'reconciliation_identity' => hash('sha256', "page-$n"), 'canonical_block_markup' => '', 'metadata' => array(), 'document_metadata' => array());
    $pages[] = $page;
    $scopes[] = array('kind' => 'page', 'source_path' => $page['source_path'], 'route_path' => "page-$n", 'reconciliation_identity' => $page['reconciliation_identity'], 'front_page' => false);
}
$assets = array(array('kind' => 'css', 'source_path' => 'shared.css', 'target_path' => 'assets/shared.css', 'content_hash' => hash('sha256', 'shared'), 'content' => 'p{color:red}', 'scopes' => $scopes));
$bootstrap = ThemeBootstrap::functionsPhp($assets, array(), array(), array(), array(), $pages);
$assert(1 === preg_match('/^\$blocks_engine_presentation_replay = (\[.*\]);$/m', $bootstrap, $match), 'The replay table is emitted.');
$replay = eval('return ' . $match[1] . ';');
$assert(1 === count($replay) && array(0, 1, 2, 3, 4, 5) === $replay[0][1], 'One shared sheet across six pages is one replay entry with six scopes.');

// Replay sets each expanded style's scope and template parts, so a file row
// that also aggregated them across occurrences was emitted and never read.
$assert(1 === preg_match('/^\$blocks_engine_presentation_styles_rows = (\[.*\]);$/m', $bootstrap, $rows), 'The file table is emitted.');
foreach (eval('return ' . $rows[1] . ';') as $row) {
    $assert(!array_key_exists('scopes', $row) && !array_key_exists('template_part_slugs', $row), 'A file row carries only what every occurrence of the file shares.');
}

$start = strpos($bootstrap, '$blocks_engine_presentation_scopes_rows');
$end = strpos($bootstrap, '$blocks_engine_presentation_matches', $start);
eval(substr($bootstrap, $start, $end - $start));
$assert(6 === count($blocks_engine_presentation_sequence), 'The replay loop expands the run to one style per scope.');
foreach ($blocks_engine_presentation_sequence as $index => $style) {
    $assert(array($scopes[$index]) === $style['scopes'] && 'assets/shared.css' === $style['target_path'], 'Expanded style ' . $index . ' keeps its scope and resource in order.');
}
fwrite(STDOUT, "presentation-replay-scope-runs: ok\n");
