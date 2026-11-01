<?php
declare(strict_types=1);

/**
 * A `picture` with no image candidate (no `img`, no `source[srcset]`) renders
 * no media: authors use it as a plain styled box, and its children may be
 * CSS-painted (an icon drawn with `mask-image`). Such a picture must lower as
 * the generic container it acts as — keeping its own box, its children, and
 * the author CSS that paints them — exactly like the same markup with a `div`
 * in its place, instead of disappearing with the media dispatch.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$cssAssets = static function (array $result): string {
    return implode("\n", array_column(
        array_filter($result['assets'] ?? array(), static fn (array $asset): bool => is_array($asset) && 'css' === ( $asset['kind'] ?? null )),
        'content'
    ));
};

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/picture-container-without-image-candidate.html');
$divSource = str_replace(array('<picture class="icon__frame">', '</picture>'), array('<div class="icon__frame">', '</div>'), $source);

$result = ( new HtmlTransformer() )->transform($source)->toArray();
$markup = (string) ( $result['serialized_blocks'] ?? '' );
$divResult = ( new HtmlTransformer() )->transform($divSource)->toArray();

$assert(str_contains($markup, 'wp-block-group icon__frame'), 'the picture frame keeps its own box', $markup);
$assert(str_contains($markup, 'icon__inner blocks-engine-empty-visual-group'), 'the masked child survives as a CSS-painted group', $markup);
$assert($markup === (string) ( $divResult['serialized_blocks'] ?? '' ), 'the picture lowers exactly like the same markup with a div', $markup);
$assert(str_contains($cssAssets($result), 'mask-image:url(quote.svg)'), 'the author mask rule stays in the projected stylesheet', $cssAssets($result));
$assert('pass' === ( $result['source_reports']['wp_block_validity']['status'] ?? '' ), 'the output stays editor-valid');

// A picture that does carry an image candidate keeps the media path.
$media = ( new HtmlTransformer() )->transform(
    '<picture><source srcset="wide.jpg" media="(min-width: 800px)"><img src="narrow.jpg" alt="Hero" width="800" height="400"></picture>'
)->toArray();
$assert(str_contains((string) ( $media['serialized_blocks'] ?? '' ), 'responsive-media'), 'a picture with an image candidate still converts as media', (string) ( $media['serialized_blocks'] ?? '' ));

if ( 0 < $failures ) {
    fwrite(STDERR, "picture container without image candidate FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "picture container without image candidate passed: {$passes} assertions\n";
