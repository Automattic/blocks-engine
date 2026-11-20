<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/capture-device-unavailable.html');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach (array(
    'default selection' => $source,
    'unavailable selection' => str_replace('<html lang="en">', '<html lang="en" data-blocks-engine-capture-document-unavailable>', $source),
    'without runtime styles' => (string) preg_replace('~<style\b[^>]*>.*?</style>~s', '', $source),
) as $label => $html) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = $result['serialized_blocks'];
    $assert(!str_contains($markup, 'This source device document was not captured.'), $label . ': capture runtime notice must not become block content');
    $assert(!str_contains(json_encode($result['blocks'], JSON_THROW_ON_ERROR), 'This source device document was not captured.'), $label . ': no block contains the notice text');
    foreach (array('Desktop heading', 'Desktop content.', 'Mobile heading', 'Mobile content.', 'Shared footer.') as $text) {
        $assert(str_contains($markup, $text), $label . ': source content survives: ' . $text);
    }
}

$unmarked = str_replace(' data-blocks-engine-capture-device-unavailable', '', $source);
$result = (new HtmlTransformer())->transform($unmarked)->toArray();
$assert(str_contains($result['serialized_blocks'], 'This source device document was not captured.'), 'An ordinary status paragraph remains source content');

echo "OK: capture device-unavailable notice omitted\n";
