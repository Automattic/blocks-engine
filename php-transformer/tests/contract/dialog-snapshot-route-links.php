<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (! $condition) throw new RuntimeException($message); };

// A captured dialog panel's swapped-in snapshots link with the artifact's
// portable routes, exactly like the page's own anchors. Published markup must
// map both to the same WordPress routes, or the swapped-in links 404 (#7328).
$snapshotHtml = '<div role="dialog"><a href="/contact/index.html">Contact</a> <a href="/index.html">Home</a></div>';
$existing = (string) json_encode(array('resting' => array('html' => $snapshotHtml, 'hidden' => true), 'open' => array('html' => $snapshotHtml, 'hidden' => false)), JSON_UNESCAPED_SLASHES);
$state = (string) json_encode(array('html' => $snapshotHtml, 'hidden' => false), JSON_UNESCAPED_SLASHES);
$attributes = array('class' => 'data-blocks-engine-capture-dialog', CaptureEvidence::EXISTING_PANEL => $existing, CaptureEvidence::DIALOG_PANEL_STATE => $state);
$dialog = '<!-- wp:ssi-site/layout-shell ' . json_encode(array('wrappers' => array(array('tagName' => 'div', 'attributes' => $attributes))), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) . ' --><div class="data-blocks-engine-capture-dialog" ' . CaptureEvidence::EXISTING_PANEL . '="' . htmlspecialchars($existing, ENT_QUOTES) . '" ' . CaptureEvidence::DIALOG_PANEL_STATE . '="' . htmlspecialchars($state, ENT_QUOTES) . '"><!-- wp:paragraph --><p>Menu</p><!-- /wp:paragraph --></div><!-- /wp:ssi-site/layout-shell -->';
$page = static fn(string $title, string $extra): string => '<!-- wp:group {"tagName":"main"} --><main class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">' . $title . '</h2><!-- /wp:heading --><!-- wp:paragraph --><p><a href="/contact/index.html">Contact</a> <a href="/index.html">Home</a></p><!-- /wp:paragraph -->' . $extra . '</main><!-- /wp:group -->';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main><h1>Home</h1></main>', 'contact/index.html' => '<main><h1>Contact</h1></main>')))->toArray();
foreach ($result['source_reports']['compiled_site']['pages'] as &$compiled) $compiled['block_markup'] = $page($compiled['source_path'], 'index.html' === $compiled['source_path'] ? $dialog : '');
unset($compiled);
$plan = (new WordPressSitePlan())->fromResult($result);
WordPressSitePlan::assertValid($plan);

$pages = array_column($plan['pages'], null, 'source_path');
$assert(isset($pages['index.html'], $pages['contact/index.html']), 'Both routes are published.');
$contact = (string) $pages['contact/index.html']['route']['path'];
$assert('/' === $pages['index.html']['route']['path'] && in_array($contact, array('/contact', '/contact/'), true), 'The plan publishes the WordPress routes.');
$markup = (string) $pages['index.html']['canonical_block_markup'];
$links = '<a href="' . $contact . '">Contact</a> <a href="/">Home</a>';
$assert(str_contains($markup, '<p>' . $links . '</p>'), 'Ordinary anchors map to WordPress routes: ' . $markup);

$snapshots = array();
foreach (array(CaptureEvidence::EXISTING_PANEL, CaptureEvidence::DIALOG_PANEL_STATE) as $name) {
    $assert((bool) preg_match_all('/\s' . preg_quote($name, '/') . '="([^"]*)"/', $markup, $html), "The published markup keeps the {$name} attribute.");
    foreach ($html[1] as $value) $snapshots[$name][] = json_decode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5), true);
    $assert((bool) preg_match_all('/"' . preg_quote($name, '/') . '":"((?:[^"\\\\]|\\\\.)*)"/', $markup, $comment), "The block comment keeps the {$name} attribute.");
    foreach ($comment[1] as $value) $snapshots[$name][] = json_decode((string) json_decode('"' . $value . '"'), true);
}
$expected = '<div role="dialog">' . $links . '</div>';
foreach ($snapshots[CaptureEvidence::EXISTING_PANEL] as $snapshot) {
    $assert(is_array($snapshot) && $expected === ($snapshot['resting']['html'] ?? null) && $expected === ($snapshot['open']['html'] ?? null), 'Existing-panel snapshot links map to WordPress routes: ' . json_encode($snapshot));
    $assert(true === $snapshot['resting']['hidden'] && false === $snapshot['open']['hidden'], 'Other snapshot keys stay untouched.');
}
foreach ($snapshots[CaptureEvidence::DIALOG_PANEL_STATE] as $snapshot) {
    $assert(is_array($snapshot) && $expected === ($snapshot['html'] ?? null) && false === $snapshot['hidden'], 'Dialog panel state links map to WordPress routes: ' . json_encode($snapshot));
}
