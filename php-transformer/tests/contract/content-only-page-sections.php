<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ContentOnlySectionProjection;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$lock = '"templateLock":"contentOnly"';

// Imported page sections lock their structure: owners edit the content, not
// the wrappers. The shared header nests as deeply as the page section, but a
// template part is chrome, never a page section.
$page = static fn(string $title, string $body): string => '<!doctype html><html><head><title>' . $title . '</title></head><body>'
    . '<header class="top"><div class="bar"><div class="brand"><p>Acme</p></div></div><nav><a href="/index.html">Home</a><a href="/about/index.html">About</a></nav></header>'
    . $body . '<footer class="foot"><p>Acme footer</p></footer></body></html>';
$deep = '<main><section class="hero"><div class="wrap"><div class="card"><h1>Welcome</h1><p>Deep body</p></div><div class="card"><p>Second</p></div></div></section></main>';
$shallow = '<main><section class="note"><p>Only a paragraph</p></section></main>';
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', $deep),
    'about/index.html' => $page('About', $shallow),
)))->toArray()['source_reports'];
$plan = $compiled['wordpress_site_plan'];
$pages = array_column($plan['pages'], null, 'source_path');

$home = $pages['index.html']['canonical_block_markup'];
$assert(1 === preg_match('/^<!-- wp:group (\{.*?\}) -->/', $home, $opening) && 'contentOnly' === (json_decode($opening[1], true)['templateLock'] ?? null), 'A deeply nested page section is locked as contentOnly.');
$assert(1 === substr_count($home, $lock), 'Only the top-level section carries the lock; nested groups stay unlocked.');
$assert(str_ends_with($opening[1], ',' . $lock . '}'), 'The lock is appended after the existing attributes in house encoding.');
$assert($pages['index.html']['content_hash'] === WordPressSitePlan::contentHash($home), 'A locked page keeps a content hash of its final markup.');

// Only the opening comment's JSON changes. The unlocked compile is the same
// markup without the attribute, and projecting it again restores every byte.
$unlocked = str_replace(',' . $lock, '', $home);
$assert(!str_contains($unlocked, 'templateLock') && substr($home, strlen($opening[0])) === substr($unlocked, strlen($opening[0]) - strlen(',' . $lock)), 'The section inner HTML is byte-identical to the unlocked compile.');
$assert(ContentOnlySectionProjection::project(array(array('source_path' => 'index.html', 'canonical_block_markup' => $unlocked)))[0]['canonical_block_markup'] === $home, 'Locking the unlocked compile reproduces the plan markup exactly.');

$about = $pages['about/index.html']['canonical_block_markup'];
$assert(str_contains($about, 'Only a paragraph') && !str_contains($about, 'templateLock'), 'A shallow section (a group holding only a paragraph) is not locked.');

$assert(array() !== $plan['template_parts'], 'The fixture extracts shared chrome into template parts.');
foreach ($plan['template_parts'] as $part) $assert(!str_contains((string) ($part['canonical_block_markup'] ?? ''), 'templateLock'), 'Template parts are never locked: ' . ($part['slug'] ?? ''));
$assert(1 === ($compiled['editability_report']['metrics']['content_only_section_count'] ?? null), 'The editability report counts locked page sections.');

// An author's own lock is preserved byte for byte, and non-page documents stay unlocked.
$nested = '<!-- wp:group --><div class="wp-block-group"><!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:group -->';
$authored = '<!-- wp:group {"templateLock":"all"} --><div class="wp-block-group">' . $nested . '</div><!-- /wp:group -->';
$bare = '<!-- wp:group --><div class="wp-block-group">' . $nested . '</div><!-- /wp:group -->';
$projected = ContentOnlySectionProjection::project(array(
    array('canonical_block_markup' => $authored, 'post_type' => 'page'),
    array('canonical_block_markup' => $bare, 'post_type' => 'post'),
    array('canonical_block_markup' => $bare),
));
$assert($projected[0]['canonical_block_markup'] === $authored && !isset($projected[0]['content_hash']), 'An existing templateLock is preserved and the document is unchanged.');
$assert($projected[1]['canonical_block_markup'] === $bare, 'Posts are not page documents and stay unlocked.');
$assert($projected[2]['canonical_block_markup'] === '<!-- wp:group {"templateLock":"contentOnly"} --><div class="wp-block-group">' . $nested . '</div><!-- /wp:group -->', 'An attribute-less section gains an attribute object holding only the lock.');

// A section that is itself a runtime-bound block keeps the exact bytes its binding anchors on.
$formPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<form method="post" action="#"><div class="controls"><div><input name="email" type="email"><p>Help</p></div><div><select name="region"><option>Global</option></select></div></div><button type="submit">Send</button></form>')))->toArray()['source_reports']['wordpress_site_plan'];
$formDeclaration = current(array_filter($formPlan['runtime_declarations'], static fn(array $declaration): bool => 'forms' === ($declaration['type'] ?? null)));
$assert(!str_contains($formPlan['pages'][0]['canonical_block_markup'], 'templateLock') && is_string($formDeclaration['payload']['entities'][0]['bindings'][0]['search_block_markup'] ?? null), 'A runtime-bound section stays unlocked and keeps its binding.');

echo "content-only-page-sections: ok\n";
