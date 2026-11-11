<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/empty-margin-spacer.html');
$result = (new HtmlTransformer())->transform($source)->toArray();
$markup = $result['serialized_blocks'];
if ( ! preg_match('/<!-- wp:group[^\n]*spacer/', $markup) ) {
    throw new RuntimeException('The contributing margin spacer must survive as a native group.');
}
if ( 'pass' !== ($result['source_reports']['wp_block_validity']['status'] ?? '') ) {
    throw new RuntimeException('The margin spacer must remain editor-valid.');
}
// A margin already represented by an adjoining paragraph needs no empty carrier.
$redundant = str_replace('margin-top:32px', 'margin-top:16px', $source);
$collapsed = (new HtmlTransformer())->transform($redundant)->toArray();
if ( preg_match('/<div[^>]*class="[^"]*\bspacer\b/', $collapsed['serialized_blocks']) ) {
    throw new RuntimeException('A fully absorbed margin must not add an editable wrapper.');
}
if ( $path = getenv('EMPTY_MARGIN_ARTIFACT') ) {
    file_put_contents($path, json_encode(array(
        'source' => $source,
        'markup' => $markup,
        'native' => '<style>' . implode("\n", array_column($result['assets'], 'content')) . '</style>' . $markup,
    ), JSON_THROW_ON_ERROR));
}
echo "Empty margin spacer passed.\n";
