<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\DescriptionListBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$generator = new DescriptionListBlockGenerator();
$definition = $generator->definition();
$assert(DescriptionListBlockGenerator::NAME === ($definition['block_json']['name'] ?? null), 'block metadata uses the stable companion name');
$assert(3 === ($definition['block_json']['apiVersion'] ?? null), 'block metadata uses apiVersion 3');
$assert(false === ($definition['block_json']['supports']['html'] ?? null), 'block metadata disables raw HTML editing');
$assert('file:./index.js' === ($definition['block_json']['editorScript'] ?? null), 'block metadata declares the editor asset');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'RawHTML'), 'editor asset serializes semantic static markup');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'escapeAttribute'), 'editor asset escapes presentation attributes');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'attributes: attributes'), 'client registration declares the block attribute schema');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'safeCssText'), 'editor rendering sanitizes captured inline styles through the browser CSSOM');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'useEffect'), 'editor rendering scopes exact inline CSS without React style-object loss');

$html = '<dl class="facts &amp; figures" style="display:grid"><dt class="term"><strong>Office</strong> <em>location</em></dt><dt>Alias</dt><dd class="definition">North <a href="/hall">Hall</a></dd><dd>Weekdays</dd><dt>Hours</dt><dd>09:00 &amp; 17:00</dd></dl>';
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$block = $result['blocks'][0] ?? array();
$groups = $block['attrs']['groups'] ?? array();
$serialized = (string) ($result['serialized_blocks'] ?? '');
$assert(DescriptionListBlockGenerator::NAME === ($block['blockName'] ?? null), 'direct valid list maps to the companion block');
$assert(2 === count($groups) && 2 === count($groups[0]['terms'] ?? array()) && 2 === count($groups[0]['descriptions'] ?? array()), 'term and description ordering is grouped deterministically');
$assert('<strong>Office</strong> <em>location</em>' === ($groups[0]['terms'][0]['content'] ?? null) && 'North <a href="/hall">Hall</a>' === ($groups[0]['descriptions'][0]['content'] ?? null), 'nested inline markup is preserved in the payload');
$assert(str_contains($serialized, '<dl class="facts &amp; figures" style="display:grid"><dt class="term"><strong>Office</strong> <em>location</em></dt><dt>Alias</dt><dd class="definition">North <a href="/hall">Hall</a></dd><dd>Weekdays</dd><dt>Hours</dt><dd>09:00 &amp; 17:00</dd></dl>'), 'static markup retains semantics and escapes attributes exactly once');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'static companion serialization is editor-valid');
$assert(1 === count($result['source_reports']['generated_blocks'] ?? array()), 'one definition is generated for multiple lists in one document');
$assert('semantic-description-list' === ($result['source_reports']['gutenberg_gaps'][0]['id'] ?? null), 'source report records the Gutenberg description-list gap');
$assert(str_contains((string) ($result['diagnostics'][count($result['diagnostics']) - 1]['references'][0] ?? ''), 'gutenberg/issues/4880'), 'diagnostic links the missing core capability to Gutenberg issue #4880');

foreach ( array(
    '<dl><dd>Description before term</dd><dt>Term</dt><dd>Description</dd></dl>',
    '<dl><dt>Term</dt></dl>',
    '<dl><div><dt>Term</dt><dd>Description</dd></div></dl>',
    '<dl><dt>Term</dt><dd>Description</dd><span>Unexpected wrapper</span></dl>',
    '<dl><dt>Term</dt><dd><p>Block-level description</p></dd></dl>',
    '<dl><dt><span class="unsupported-richtext-attribute">Term</span></dt><dd>Description</dd></dl>',
) as $malformed ) {
    $converted = ( new HtmlTransformer() )->transform($malformed)->toArray();
    $assert(DescriptionListBlockGenerator::NAME !== ($converted['blocks'][0]['blockName'] ?? null), 'malformed or wrapped lists retain conservative fallback conversion');
    $assert(array() === ($converted['source_reports']['generated_blocks'] ?? null), 'malformed or wrapped lists do not generate a companion definition');
}

if ( 0 < $failures ) {
    fwrite(STDERR, "Description-list block unit tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Description-list block unit tests: {$passes} passed" . PHP_EOL);
