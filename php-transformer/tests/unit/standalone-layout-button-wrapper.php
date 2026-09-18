<?php
declare(strict_types=1);

/**
 * A synthesized core/buttons wrapper around a standalone native <button> that
 * is already a direct child of an authored flex/grid container must not
 * generate a box. Gutenberg still requires the wrapper for validity.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$cssOf = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }

    return $css;
};

$neutralButtons = SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTONS_CLASS;
$neutralButton  = SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS;

$row = ( new HtmlTransformer() )->transform(
    '<style>.row{display:flex;flex-wrap:wrap;gap:12px;justify-content:center}.chip{padding:8px 20px;font-size:14px;line-height:20px;font-weight:600}</style>'
    . '<div class="row">'
    . '<button class="chip" type="button">All</button>'
    . '<button class="chip" type="button">Kitchens</button>'
    . '<button class="chip" type="button">Bathrooms</button>'
    . '<button class="chip" type="button">Whole-Home</button>'
    . '<button class="chip" type="button">Outdoor</button>'
    . '<button class="chip" type="button">Commercial</button>'
    . '</div>'
)->toArray();
$rowMarkup = (string) ( $row['serialized_blocks'] ?? '' );
$rowCss = $cssOf($row);
$rowBlock = $row['blocks'][0] ?? array();
$rowChildren = is_array($rowBlock['innerBlocks'] ?? null) ? $rowBlock['innerBlocks'] : array();

$assert(
    'core/group' === ( $rowBlock['blockName'] ?? '' ) && 6 === count($rowChildren),
    'the authored flex row remains one group with six direct block children',
    $rowMarkup
);
$assert(
    6 === count(array_filter($rowChildren, static fn (array $block): bool => 'core/buttons' === ( $block['blockName'] ?? '' ))),
    'each standalone button stays a valid core/buttons > core/button tree',
    $rowMarkup
);
$assert(
    6 === preg_match_all('/class="wp-block-buttons[^"]*\b' . preg_quote($neutralButtons, '/') . '\b/', $rowMarkup),
    'each synthesized buttons wrapper around a layout-child native button is marked layout-neutral',
    $rowMarkup
);
$assert(
    6 === preg_match_all('/class="wp-block-button [^"]*\b' . preg_quote($neutralButton, '/') . '\b/', $rowMarkup),
    'each synthesized inner button wrapper is marked layout-neutral',
    $rowMarkup
);
$assert(
    str_contains($rowCss, ':where(.' . $neutralButtons . '){display:contents!important}')
        && str_contains($rowCss, ':where(.' . $neutralButton . '){display:contents!important}'),
    'layout-neutral wrappers do not generate a box in the authored flex row',
    $rowCss
);
$assert(
    'pass' === ( $row['source_reports']['wp_block_validity']['status'] ?? '' ),
    'layout-child native buttons remain valid native blocks',
    (string) json_encode($row['source_reports']['wp_block_validity'] ?? array())
);
$assert(
    ! str_contains($rowMarkup, 'wp:html') && ! str_contains($rowMarkup, 'wp:freeform') && ! str_contains($rowMarkup, 'wp:missing'),
    'layout-child native buttons stay natively editable',
    $rowMarkup
);

$group = ( new HtmlTransformer() )->transform(
    '<style>.actions{display:flex;gap:16px;justify-content:center}.btn{padding:8px 16px;background:#111;color:#fff}</style>'
    . '<div class="actions">'
    . '<a class="btn" href="/one">One</a>'
    . '<a class="btn" href="/two">Two</a>'
    . '</div>'
)->toArray();
$groupMarkup = (string) ( $group['serialized_blocks'] ?? '' );

$assert(
    ! str_contains($groupMarkup, $neutralButtons) && ! str_contains($groupMarkup, $neutralButton),
    'a genuine source button group of anchors is not marked layout-neutral',
    $groupMarkup
);
$assert(
    1 === substr_count($groupMarkup, '<!-- wp:buttons') || 2 === preg_match_all('/class="wp-block-buttons /', $groupMarkup),
    'anchor controls in a flex row keep ordinary synthesized buttons wrappers',
    $groupMarkup
);

$lone = ( new HtmlTransformer() )->transform(
    '<main><button class="chip" type="button" style="padding:8px 16px;background:#135e96;color:#fff">Go</button></main>'
)->toArray();
$loneMarkup = (string) ( $lone['serialized_blocks'] ?? '' );
$assert(
    ! str_contains($loneMarkup, $neutralButtons) && ! str_contains($loneMarkup, $neutralButton) && str_contains($loneMarkup, 'wp-block-buttons'),
    'a standalone native button outside a layout container keeps a normal synthesized buttons wrapper',
    $loneMarkup
);

if ( $failures ) {
    fwrite(STDERR, $failures . " standalone-layout-button wrapper test(s) failed\n");
    exit(1);
}

echo 'Standalone-layout-button wrapper tests: ' . $passes . " passed\n";
