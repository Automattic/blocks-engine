<?php
declare(strict_types=1);

/**
 * An inline image inside an inline-block link becomes core/image, whose core
 * styles make the image a block. The link then has no line box, its baseline
 * falls to the margin edge and the parent's strut adds descent below it. The
 * synthesized figure must follow inline flow so the image keeps the source's
 * inline display and vertical-align.
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

$out = ( new HtmlTransformer() )->transform((string) file_get_contents(dirname(__DIR__) . '/fixtures/inline-block-link-inline-image.html'))->toArray();
$blocks = (string) ( $out['serialized_blocks'] ?? '' );
$css = '';
foreach ( $out['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $css .= (string) ( $asset['content'] ?? '' );
    }
}

$assert(1 === preg_match('/<figure class="[^"]*blocks-engine-synthetic-image-figure-inline[^"]*"><a href="\/" class="logo(?: [^"]*)?">/', $blocks), 'the linked image figure follows inline flow', $blocks);
$assert(str_contains($css, ':root .blocks-engine-synthetic-image-figure-inline{display:contents}'), 'the inline figure generates no box', $css);
$assert(str_contains($css, '.blocks-engine-synthetic-image-figure-inline) > a > img{vertical-align:middle}'), 'the source vertical-align reaches the linked image', $css);

if ( 0 !== $failures ) {
    exit(1);
}

echo "Inline-block link inline image contract passed.\n";
