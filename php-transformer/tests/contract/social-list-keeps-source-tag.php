<?php
declare(strict_types=1);

/**
 * A source list lowered to a group keeps its list tag and item tags, so
 * element-type rules (`ul{margin-bottom}`) and its inline formatting context
 * carry over instead of being replaced by an invented flex row.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ( '' === $detail ? '' : ' - ' . $detail ) . PHP_EOL);
    }
};

$out = ( new HtmlTransformer() )->transform((string) file_get_contents(dirname(__DIR__) . '/fixtures/list-flex-group-source-tag.html'))->toArray();
$blocks = (string) ( $out['serialized_blocks'] ?? '' );

$assert(1 === preg_match('/<ul class="wp-block-group list social">/', $blocks), 'the group keeps the source list tag', $blocks);
$assert(2 === substr_count($blocks, '<li class="wp-block-group social-item">'), 'item wrappers keep the li tag', $blocks);
$assert(! str_contains($blocks, 'is-layout-flex'), 'the block-level list is not replaced by flex', $blocks);

if ( 0 !== $failures ) {
    exit(1);
}

echo "Social list source tag contract passed.\n";
