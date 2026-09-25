<?php
declare(strict_types=1);

/**
 * Unit tests for responsive authored typography on rich-text mark carriers.
 *
 * Plain-PHP test script — no PHPUnit. A rich-text carrier (the `<mark>` a
 * styled inline is lowered to) keeps the author's classes as selector hooks,
 * so a media-conditional rule keeps addressing it after conversion — but an
 * inline declaration out-ranks every stylesheet rule. Projecting the static
 * cascade winner inline would freeze the base breakpoint's value onto the
 * carrier and silence the responsive rule at every other width: a desktop
 * hero title renders at the mobile size. Those declarations stay with the
 * author stylesheet, which resolves them per viewport; carriers a rule
 * cannot re-address (one without the author's classes) keep their projected
 * styles.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$authorCss = '<style>'
    . '.text-5xl { font-size: 3rem; line-height: 1; }'
    . '.text-6xl { font-size: 3.75rem; line-height: 1; }'
    . '.text-xl { font-size: 1.25rem; line-height: 1.75rem; }'
    . '.accent { color: rgb(120, 20, 20); }'
    . '@media (min-width:768px){'
    . '.md\:text-7xl { font-size: 4.5rem; line-height: 1; }'
    . '.md\:text-8xl { font-size: 6rem; line-height: 1; }'
    . '}'
    . '</style>';

// The hero-title shape: two responsive spans inside a heading. The marks must
// keep the author classes and carry no frozen font-size/line-height, so the
// base width reads the base utility and the desktop width reads the md: one.
$hero = ( new HtmlTransformer() )->transform(
    $authorCss
    . '<main><h1 class="uppercase leading-none"><span class="text-6xl md:text-8xl block">TOM</span>'
    . '<span class="text-5xl md:text-7xl block">MERRITT</span></h1></main>'
)->toArray();
$heroBlocks = (string) ( $hero['serialized_blocks'] ?? '' );
$assert(
    ! preg_match('/<mark class="[^"]*text-6xl md:text-8xl[^"]*"[^>]*style="[^"]*font-size:/i', $heroBlocks),
    'the first responsive mark does not inline a base font-size',
    $heroBlocks
);
$assert(
    ! preg_match('/<mark class="[^"]*text-5xl md:text-7xl[^"]*"[^>]*style="[^"]*font-size:/i', $heroBlocks),
    'the second responsive mark does not inline a base font-size',
    $heroBlocks
);
$assert(
    ! preg_match('/<mark class="[^"]*text-6xl md:text-8xl[^"]*"[^>]*style="[^"]*line-height:/i', $heroBlocks)
        && ! preg_match('/<mark class="[^"]*text-5xl md:text-7xl[^"]*"[^>]*style="[^"]*line-height:/i', $heroBlocks),
    'the responsive marks do not inline a line-height the md: rules restate',
    $heroBlocks
);
$assert(
    str_contains($heroBlocks, 'text-6xl md:text-8xl') && str_contains($heroBlocks, 'text-5xl md:text-7xl'),
    'the authored responsive classes survive on the marks so the stylesheet keeps resolving them',
    $heroBlocks
);
$assert(
    'pass' === ( ( new BlockValidityValidator() )->validateBlocks($hero['blocks'] ?? array())['status'] ?? '' ),
    'the responsive hero heading remains Gutenberg-valid',
    $heroBlocks
);

$heroAuthorCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ( $asset['content'] ?? '' ),
    array_values(array_filter(
        is_array($hero['assets'] ?? null) ? $hero['assets'] : array(),
        static fn (array $asset): bool => 'author-css' === ( $asset['source'] ?? '' )
    ))
));
$conditional = substr($heroAuthorCss, max(0, (int) strpos($heroAuthorCss, 'min-width:768px')));
$assert(
    str_contains($heroAuthorCss, 'font-size: 3.75rem')
        && str_contains($heroAuthorCss, 'font-size: 3rem')
        && str_contains($conditional, 'font-size: 6rem')
        && str_contains($conditional, 'font-size: 4.5rem'),
    'the author stylesheet still owns the base and desktop values',
    $heroAuthorCss
);

// A responsive conflict must not strip unrelated projection. A static-only
// size class keeps its inline value, and a color with no conditional variant
// anywhere on the element is untouched.
$mixed = ( new HtmlTransformer() )->transform(
    $authorCss
    . '<main><h2 class="leading-none"><span class="text-xl">Fixed</span> <span class="accent">Accent</span></h2></main>'
)->toArray();
$mixedBlocks = (string) ( $mixed['serialized_blocks'] ?? '' );
$assert(
    (bool) preg_match('/<mark class="[^"]*text-xl[^"]*"[^>]*style="[^"]*font-size:1\.25rem/i', $mixedBlocks),
    'a static-only size class keeps its projected inline font-size',
    $mixedBlocks
);
$assert(
    (bool) preg_match('/<mark class="[^"]*accent[^"]*"[^>]*style="[^"]*color:rgb\(120, 20, 20\)/i', $mixedBlocks),
    'a color no conditional rule restates keeps its projected inline value',
    $mixedBlocks
);

// An element whose own inline style declares the size keeps that value: the
// author's explicit inline declaration is meant to win at every width.
$inline = ( new HtmlTransformer() )->transform(
    $authorCss
    . '<main><h2 class="leading-none"><span class="text-6xl md:text-8xl" style="font-size:2rem">Inline</span></h2></main>'
)->toArray();
$assert(
    str_contains((string) ( $inline['serialized_blocks'] ?? '' ), 'font-size:2rem'),
    'an inline authored font-size still wins over the responsive class set',
    (string) ( $inline['serialized_blocks'] ?? '' )
);

// An id-targeted desktop media query is the same shape as a responsive class:
// the static 24px must not freeze onto the mark, or every width renders the
// base size and the 23px desktop rule never wins.
$idTitle = ( new HtmlTransformer() )->transform(
    '<style>.logo #site-title{display:block;max-width:400px;font-size:24px;font-weight:600}'
    . '@media screen and (min-width:767px){#site-title{font-size:23px !important}}</style>'
    . '<main><p class="logo"><a href="/"><span id="site-title">Site Title</span></a></p></main>'
)->toArray();
$idBlocks = (string) ( $idTitle['serialized_blocks'] ?? '' );
$assert(
    ! preg_match('/<mark[^>]*style="[^"]*font-size:24px/i', $idBlocks),
    'an id-targeted desktop media query does not freeze the static 24px onto the mark',
    $idBlocks
);
$assert(
    str_contains($idBlocks, 'Site Title'),
    'the id-targeted title remains editable rich text',
    $idBlocks
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Rich-text responsive typography carriers: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Rich-text responsive typography carriers passed: {$passes} assertions\n");
