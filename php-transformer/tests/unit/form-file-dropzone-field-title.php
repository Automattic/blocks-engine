<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A file field whose input sits inside its drop zone's "Add a File" label is
// named by the title label above it, even when that title's `for` targets an
// id no element carries; its helper paragraph stays the field description.
$html = '<main><form method="post" action="#"><label for="n">Name</label><input id="n" name="name">'
    . '<div class="field"><label for="proof-field" class="title"><span>Proof of Address</span></label>'
    . '<p class="description">(utility bill within 3 months)</p>'
    . '<div class="drop"><label class="zone"><input type="file" name="proof"><span>Add a File</span></label></div></div>'
    . '<button type="submit">Send</button></form></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$declaration = current(array_filter($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), static fn (array $d): bool => 'forms' === ($d['type'] ?? null)));
$file = current(array_filter($declaration['payload']['entities'][0]['controls'] ?? array(), static fn (array $c): bool => 'file' === ($c['type'] ?? null)));
$unrepresented = array_filter($result['diagnostics'] ?? array(), static fn (array $d): bool => 'form_field_context_unrepresented' === ($d['code'] ?? null));

$failures = array();
if ( 'Proof of Address' !== ($file['label'] ?? null) ) $failures[] = 'The title label names the file field; got ' . json_encode($file['label'] ?? null);
if ( '(utility bill within 3 months)' !== ($file['description'] ?? null) ) $failures[] = 'The helper paragraph describes the file field; got ' . json_encode($file['description'] ?? null);
if ( array() !== $unrepresented ) $failures[] = 'Every field copy reaches the form entity.';
foreach ( $failures as $failure ) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
