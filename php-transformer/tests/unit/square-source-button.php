<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform((string) file_get_contents(__DIR__ . '/../fixtures/square-source-button.html'))->toArray();
$buttons = array();
$walk = static function (array $blocks) use (&$walk, &$buttons): void {
    foreach ($blocks as $block) {
        if ('core/button' === ($block['blockName'] ?? '')) $buttons[] = $block;
        $walk($block['innerBlocks'] ?? array());
    }
};
$walk($result['blocks'] ?? array());
$assert = static function (bool $condition, string $message) use ($result): void {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n" . json_encode($result) . "\n");
        exit(1);
    }
};
$assert(3 === count($buttons), 'all source controls lower to core/button');
$assert('0px' === ($buttons[0]['attrs']['style']['border']['radius'] ?? null), 'square source default is explicit native border radius');
$assert(str_contains($result['serialized_blocks'] ?? '', 'border-radius:0px'), 'saved link overrides core pill radius');
$assert('0px' !== ($buttons[1]['attrs']['style']['border']['radius'] ?? null), 'authored shorthand radius is not squared off');
$assert('0px' !== ($buttons[2]['attrs']['style']['border']['radius'] ?? null), 'authored corner radii are not squared off');
echo "square source button tests: passed\n";
