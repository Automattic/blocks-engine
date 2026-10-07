<?php
declare(strict_types=1);

/**
 * Regression coverage for a block-display span inside a heading losing its own
 * authored (responsive) font-size.
 *
 * A two-line hero heading is commonly authored as
 * `<h1>First<span class="sub-line">Second</span></h1>` with `.sub-line` styled
 * `display:block` and its own, smaller `font-size`. The span stays an inline
 * element of the heading's RichText, but source analysis classified the
 * `display:block` span as an inline-layout carrier and scoped every rule that
 * addressed it behind `p.blocks-engine-inline-layout-carrier > …`, a paragraph
 * that is never emitted for heading content. The span's `font-size` therefore
 * matched nothing and the second line inherited the heading's far larger size.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$authorCss = static function (string $html): string {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $css    = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'author-css' === ( $asset['source'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }

    return $css;
};

$css = '.display-title{font-size:15vw}.sub-line{display:block;font-size:9vw}'
    . '@media (min-width:768px){.display-title{font-size:10rem}.sub-line{font-size:5.5rem}}';

// 1. A block-display span inside a heading keeps rules that reach it.
$heading = $authorCss('<style>' . $css . '</style><h1 class="display-title">First<span class="sub-line">Second</span></h1>');
$assert(
    ! str_contains($heading, 'blocks-engine-inline-layout-carrier'),
    'rules for a block span inside a heading are not scoped behind an inline-layout carrier paragraph',
    $heading
);
$assert(
    1 === preg_match('/\.sub-line[^{]*\{[^}]*font-size:9vw/', $heading)
        && 1 === preg_match('/\.sub-line[^{]*\{[^}]*font-size:5\.5rem/', $heading),
    'the block span keeps its base and media-conditional font-size rules',
    $heading
);

// 2. Control: the same span directly inside a paragraph is still a carrier,
//    so the carrier scoping for paragraph content is unchanged.
$paragraph = $authorCss('<style>' . $css . '</style><p>First<span class="sub-line">Second</span></p>');
$assert(
    str_contains($paragraph, 'blocks-engine-inline-layout-carrier'),
    'a block span inside a paragraph keeps its inline-layout carrier scoping',
    $paragraph
);

if ( 0 < $failures ) {
    fwrite(STDERR, "Heading block span font-size contract failed ({$failures} failing, {$passes} passing)\n");
    exit(1);
}

fwrite(STDOUT, "Heading block span font-size contract passed: {$passes} assertions\n");
