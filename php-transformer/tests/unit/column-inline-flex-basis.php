<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CanonicalSaveShapeValidator;

$html = '<html><head><style>.wp-block-columns{display:flex}.wp-block-column{flex-basis:0;flex-grow:1}.wp-block-column[style*=flex-basis]{flex-grow:0}</style></head><body><ol class="wp-block-comment-template"><li class="comment"><div class="wp-block-columns is-layout-flex"><div class="wp-block-column is-layout-flow" style="flex-basis: 40px"><div class="wp-block-avatar"><img src="a.png" width="40" height="40" alt="A"></div></div><div class="wp-block-column is-layout-flow"><div class="wp-block-comment-author-name">David</div><p>Thanks a lot.</p></div></div></li></ol></body></html>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

preg_match('/<!-- wp:column (\{.*?\}) -->\s*(<div[^>]*>)/', $markup, $firstColumn);
$attrs = json_decode($firstColumn[1] ?? '{}', true);
$assert('40px' === ($attrs['width'] ?? null), 'First column serializes native width of 40px.');
$assert(!str_contains($attrs['className'] ?? '', 'be-inline-geometry'), 'First column needs no inline geometry class for flex-basis.');
$assert(str_contains($firstColumn[2] ?? '', 'style="flex-basis:40px"'), 'Column width saves as inline flex-basis.');
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
$assert(!preg_match('/[^{}]*be-inline-geometry-[^{}]*\{[^{}]*flex-basis\s*:/', $css), 'No inline geometry carrier duplicates flex-basis.');
$assert(array() === (new CanonicalSaveShapeValidator())->findings($result['blocks']), 'Canonical column save shape holds.');

foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
if ($failures) exit(1);
echo "Column inline flex-basis regression passed.\n";
