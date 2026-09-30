<?php
declare(strict_types=1);

/**
 * Regression for containing blocks proven from authored stylesheet positioning.
 *
 * The inline positioning carrier requires a provable containing block before
 * an inline `position:absolute` shell is handed to the document. That proof
 * only consulted inline ancestor declarations, so a source section positioned
 * `relative` through its class — the idiomatic hero — failed the proof: the
 * unclassed image wrapper carrying inline `position:absolute` and a full
 * inset went to the document without either, returned to flow, and pushed
 * every following sibling beneath a full-width background image.
 *
 * The proof now resolves each ancestor with the same structural resolution
 * used everywhere else — non-conditional author rules matched by selector,
 * inline style merged last — so a class-owned `position:relative` anchors an
 * inline absolute child exactly as an inline declaration does. Positioning is
 * still preserved, never synthesized: a class token with no authored
 * position rule, or a position stated only inside a media query, proves
 * nothing, and the child stays in flow.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

$cssOf = static function (array $result): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(is_array($result['assets'] ?? null) ? $result['assets'] : array())
    ));
};

/** Every generated carrier rule body in the result, joined. */
$carrierRules = static function (array $result) use ($cssOf): string {
    $css = $cssOf($result);
    if ( ! preg_match_all('/(?<![\w-])\.(be-inline-geometry-[a-f0-9-]+)\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER) ) {
        return '';
    }

    return implode("\n", array_map(static fn (array $match): string => $match[2], $matches));
};

/** Carrier class names whose rule body carries the given declaration. */
$carriersCarrying = static function (array $result, string $declaration) use ($cssOf): array {
    $css = $cssOf($result);
    if ( ! preg_match_all('/(?<![\w-])\.(be-inline-geometry-[a-f0-9-]+)\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER) ) {
        return array();
    }

    $names = array();
    foreach ( $matches as $match ) {
        if ( str_contains($match[2], $declaration) ) {
            $names[] = $match[1];
        }
    }

    return $names;
};

// -- An inline absolute inset shell under a class-positioned section survives.
$hero = $transform(
    '<style>.hero{position:relative}</style>'
    . '<section class="hero">'
    . '<div style="position:absolute;border-radius:inherit;top:0;right:0;bottom:0;left:0">'
    . '<img src="/hero-bg.jpg" _width="1440" _height="1037" style="width:100%;height:100%;object-fit:cover">'
    . '</div>'
    . '<div class="hero-content"><h1>Wave hero</h1></div>'
    . '</section>'
);
$heroCss = $cssOf($hero);
$heroRules = $carrierRules($hero);

$absoluteCarriers = $carriersCarrying($hero, 'position:absolute');
$assert(
    1 === count($absoluteCarriers),
    'the inline absolute shell keeps its positioning when the parent is class-positioned',
    $heroRules
);
foreach ( array( 'top:0', 'right:0', 'bottom:0', 'left:0' ) as $inset ) {
    $assert(
        str_contains($heroRules, $inset),
        'the shell keeps the inset ' . $inset . ' that pins it to the section',
        $heroRules
    );
}
$assert(
    str_contains($heroCss, '.hero{position:relative}'),
    'the authored class rule that establishes the containing block survives conversion',
    $heroCss
);
$assert(
    '' !== (string) ( $hero['serialized_blocks'] ?? '' ) && str_contains((string) $hero['serialized_blocks'], (string) ( $absoluteCarriers[0] ?? "\0" )),
    'the positioned shell reaches the document as a block carrying its carrier class',
    (string) ( $absoluteCarriers[0] ?? '' )
);
$siblingRules = implode("\n", array_filter(
    explode("\n", $heroRules),
    static fn (string $rule): bool => ! str_contains($rule, 'position:absolute')
));
$assert(
    ! str_contains($siblingRules, 'position:'),
    'siblings of the shell gain no positioning of their own',
    $heroRules
);

// -- A class-positioned ancestor anchors through intermediate wrappers.
$nested = $transform(
    '<style>.stage{position:relative}</style>'
    . '<div class="stage"><div class="frame">'
    . '<div style="position:absolute;top:0;left:0;width:100%;height:100%"><p>Fill</p></div>'
    . '</div></div>'
);
$nestedRules = $carrierRules($nested);
$assert(
    str_contains($nestedRules, 'position:absolute') && str_contains($nestedRules, 'top:0') && str_contains($nestedRules, 'left:0'),
    'the proof walks ancestors, so a positioned grandparent anchors the shell too',
    $nestedRules
);

// -- A class token without an authored position rule proves nothing.
$unanchored = $transform(
    '<style>.hero{color:#111}</style>'
    . '<section class="hero">'
    . '<div style="position:absolute;top:0;right:0;bottom:0;left:0;opacity:.7"><p>Layer</p></div>'
    . '<h1>Wave hero</h1>'
    . '</section>'
);
$unanchoredRules = $carrierRules($unanchored);
$assert(
    ! str_contains($unanchoredRules, 'position:absolute'),
    'an inline absolute shell whose parent class carries no authored position stays in flow',
    $unanchoredRules
);
$assert(
    ! str_contains($unanchoredRules, 'top:0') && ! str_contains($unanchoredRules, 'left:0'),
    'the unanchored shell keeps no insets either',
    $unanchoredRules
);

// -- A position stated only inside a media query proves nothing.
$mediaOnly = $transform(
    '<style>@media (min-width:600px){.hero{position:relative}}</style>'
    . '<section class="hero">'
    . '<div style="position:absolute;top:0;right:0;bottom:0;left:0"><p>Layer</p></div>'
    . '<h1>Wave hero</h1>'
    . '</section>'
);
$mediaOnlyRules = $carrierRules($mediaOnly);
$assert(
    ! str_contains($mediaOnlyRules, 'position:absolute'),
    'media-query-only positioning does not anchor an inline absolute shell at rest',
    $mediaOnlyRules
);

$overridden = $transform(
    '<style>.hero{position:relative}</style>'
    . '<section class="hero" style="position:static">'
    . '<div style="position:absolute;inset:0"><p>Layer</p></div>'
    . '</section>'
);
$assert(
    ! str_contains($carrierRules($overridden), 'position:absolute'),
    'an inline static override removes the authored containing-block proof'
);

if ( 0 < $failures ) {
    fwrite(STDERR, sprintf('class-positioned containing block contract FAILED: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

echo sprintf('Class-positioned containing block contract passed: %d assertions%s', $passes, PHP_EOL);
