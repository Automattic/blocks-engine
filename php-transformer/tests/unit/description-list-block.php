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
$assert(3 === ($definition['block_json']['apiVersion'] ?? null), 'block metadata uses apiVersion 3');
$assert(false === ($definition['block_json']['supports']['html'] ?? null), 'block metadata disables raw HTML editing');
$assert('file:./index.js' === ($definition['block_json']['editorScript'] ?? null), 'block metadata declares the plain editor asset');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'window.wp.blocks'), 'editor asset uses WordPress globals without a build');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'blockEditor.RichText'), 'editor asset exposes editable term and description content');
$assert(str_contains((string) ($definition['assets']['index.asset.php'] ?? ''), "'wp-block-editor'"), 'editor asset declares its WordPress dependencies');

$html = '<dl class="facts" style="display:grid"><dt class="term">One</dt><dt style="font-weight:bold">Alias</dt><dd class="definition">First</dd><dd style="color:red">Second</dd><dt>Two</dt><dd>Third</dd></dl>';
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$block = $result['blocks'][0] ?? array();
$assert(DescriptionListBlockGenerator::NAME === ($block['blockName'] ?? null), 'direct valid list maps to the stable companion block');
$assert(2 === count($block['attrs']['groups'] ?? array()), 'multiple term and description sequences retain grouped semantics');
$assert($html === ($block['innerHTML'] ?? null) && array($html) === ($block['innerContent'] ?? null), 'static parsed block retains exact dl dt dd markup');
$assert(1 === count($result['source_reports']['generated_blocks'] ?? array()), 'block definition is emitted only when a direct list is used');
$assert('semantic-description-list' === ($result['source_reports']['gutenberg_gaps'][0]['id'] ?? null), 'source report identifies the semantic core gap');

$plain = ( new HtmlTransformer() )->transform('<p>No description list</p>')->toArray();
$assert(array() === ($plain['source_reports']['generated_blocks'] ?? null), 'unused transforms omit the description-list definition');
$wrapped = ( new HtmlTransformer() )->transform('<dl><div><dt>Term</dt><dd>Description</dd></div></dl>')->toArray();
$assert(DescriptionListBlockGenerator::NAME !== ($wrapped['blocks'][0]['blockName'] ?? null), 'wrapped lists retain the existing safe conversion path');

if ( 0 < $failures ) {
    fwrite(STDERR, "Description-list block unit tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Description-list block unit tests: {$passes} passed" . PHP_EOL);
