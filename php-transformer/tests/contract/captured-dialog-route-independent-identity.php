<?php
declare(strict_types=1);

/**
 * The same captured dialog on many routes (a product page's size picker, a
 * newsletter popup) keeps one identity, so the routes can share a template.
 * Two dialogs in one route still stay apart.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

$picker = static fn (string $key, string $label): string => '<div class="picker"><button type="button" aria-expanded="false" aria-controls="' . $key . '" data-blocks-engine-capture-dialog-trigger="' . $key . '">' . $label . '</button>'
    . '<dialog class="panel data-blocks-engine-capture-dialog data-blocks-engine-capture-dropdown" id="' . $key . '" data-blocks-engine-capture-dialog-panel="' . $key . '"><p>Choose your size.</p><p>Runs true to size.</p></dialog></div>';
$page = static fn (string $title, string $pickers): string => '<main><h1>' . $title . '</h1><p>' . $title . ' details.</p>' . $pickers . '</main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Boating tee', $picker('size', 'Size')) . '<a href="disc.html">Disc</a>',
    'disc.html' => $page('Disc golf tee', $picker('size', 'Size')),
    'both.html' => $page('Two pickers', $picker('size', 'Size') . $picker('color', 'Color')),
)))->toArray();

$ids = array();
foreach ($result['source_reports']['wordpress_site_plan']['pages'] as $page) {
    preg_match_all('/blocks-engine-dialog-[0-9a-f]{16}\b/', (string) ($page['canonical_block_markup'] ?? ''), $found);
    $ids[$page['source_path']] = array_values(array_unique($found[0]));
}
$assert(1 === count($ids['index.html'] ?? array()) && ($ids['index.html'] ?? null) === ($ids['disc.html'] ?? null), 'The same dialog on two routes keeps one identity: ' . json_encode($ids));
$assert(2 === count($ids['both.html'] ?? array()), 'Two dialogs on one route keep distinct identities: ' . json_encode($ids));
