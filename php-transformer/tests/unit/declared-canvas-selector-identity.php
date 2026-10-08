<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_TEST_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!doctype html><html><head><style>:root{--canvas-width:1040px}#canvas{width:100%;min-width:var(--canvas-width)}[data-dla-device-document="slate"] #grid{display:grid;grid-template-columns:100%;position:relative}[data-dla-device-document="slate"] [id="card"]{position:relative;left:17px;width:120px}</style></head><body>';
foreach (array('default', 'compact', 'slate') as $profile) $html .= '<div data-dla-device-document="' . $profile . '" data-dla-document-scope="" class="scope-context"><div id="canvas"><div id="grid"><header><p>Heading</p></header><main><div id="card"><p>Card</p></div></main><footer><p>Footer</p></footer></div></div></div>';
$html .= '</body></html>';
$result = (new ArtifactCompiler())->compile(array('files' => array('index.html' => $html)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'editor' !== ($asset['stylesheet_target'] ?? 'both')), 'content'));
$failures = array();
if (!str_contains($css, 'min-width:var(--canvas-width)') || preg_match('/#canvas[^{}]*\{[^}]*min-width:0[^}]*max-width:100%/', $css)) $failures[] = 'Declared document canvas minimum width is source layout, not an implicit responsive repair.';
if (!str_contains($css, ':is(#grid,.blocks-engine-editor-anchor-grid)') || !str_contains($css, ':is(#card,.blocks-engine-editor-anchor-card)')) $failures[] = 'Frontend ID and equality selectors must retain a stable declared identity when scope disambiguation changes saved IDs.';
if (str_contains($result['serialized_blocks'], '<!-- wp:html')) $failures[] = 'Declared canvas identity repair must retain native block authoring.';
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n" . substr($css, 0, 6000) . "\n"); exit(1); }
fwrite(STDOUT, "Declared canvas selector identity passed\n");
