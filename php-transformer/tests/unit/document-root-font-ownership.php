<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$html = static fn(string $root): string => '<!doctype html><html class="' . $root . '" id="document"><head><title>Neutral</title><link rel="stylesheet" href="type.css"></head><body><main><h1>Selected font</h1><p>Inherited typography</p></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html('type-a'),
    'other/index.html' => $html('type-b'),
    'type.css' => '.type-a{--selected-face:"Neutral A",serif}.type-b{--selected-face:"Neutral B",sans-serif}html{font-family:var(--selected-face)}body,h1,p{font-family:inherit}@media(min-width:600px){.type-a{--selected-face:"Neutral Wide",serif}}',
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$pages = array_column($plan['pages'], null, 'source_path');
$assert('type-a' === ($pages['index.html']['document_metadata']['root_attributes']['class'] ?? null), 'entry root scope travels through site plan');
$assert('type-b' === ($pages['other/index.html']['document_metadata']['root_attributes']['class'] ?? null), 'route-specific scope stays separate');
$assert('document' === ($pages['index.html']['document_metadata']['root_attributes']['id'] ?? null), 'root ID travels alongside classes');
$bootstrap = '';
foreach ($plan['writes'] as $write) if ('functions.php' === $write['target_path']) $bootstrap = $write['payload']['data'];
$assert(str_contains($bootstrap, "'language_attributes'") && str_contains($bootstrap, 'WP_HTML_Tag_Processor') && str_contains($bootstrap, "'type-a'") && str_contains($bootstrap, "'type-b'"), 'generated runtime adopts route-owned root identity through native HTML API');
$assert(str_contains($bootstrap, "'_blocks_engine_reconciliation_identity'") && str_contains($bootstrap, 'is_front_page()'), 'runtime selection uses destination identity with route fallback');
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content'));
$assert(str_contains($css, '.type-a') && str_contains($css, '.type-b') && str_contains($css, '@media'), 'authored root selector and conditional cascade stay intact');
$assert(str_contains($css, '.editor-styles-wrapper{--selected-face:'), 'editor canvas receives source-proved inherited root tokens');
$assert(array('class' => 'scope "quoted"', 'id' => 'root') === DocumentRootContext::fromHtml('<html class="scope &quot;quoted&quot;" id="root" onclick="bad()"><body>Text</body></html>'), 'only selector identity is captured with decoded attribute values');
$assert(array() === DocumentRootContext::fromHtml('<body class="not-root">Text</body>'), 'body identity is not mistaken for html identity');
try { DocumentRootContext::assertValid(array('onclick' => 'bad()')); $assert(false, 'unexpected root field rejected'); } catch (InvalidArgumentException) {}
fwrite(STDOUT, "Document root font ownership passed\n");
