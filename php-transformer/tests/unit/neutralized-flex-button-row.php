<?php
declare(strict_types=1);

/**
 * A neutralized core/button that still carries a source flex container class
 * must emit that container onto the link. The inner .wp-block-button wrapper
 * is display:contents, so `flex items-center gap-*` on it cannot lay out
 * img+text; core's .wp-element-button padding then stacks them and adds a
 * constant document-height offset.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutParticipation;
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

$neutralButton = SourceBlockAttributeProjector::LAYOUT_NEUTRAL_BUTTON_CLASS;
$flexLinkRule = ':where(.' . $neutralButton . '.flex,.' . $neutralButton . '.inline-flex)>.wp-block-button__link{'
    . LayoutParticipation::transferredFlexContainerDeclarations()
    . '}';

$lockup = ( new HtmlTransformer() )->transform(
    '<a class="flex items-center gap-3" href="/" role="button">'
    . '<img src="logo.png" alt="" width="56" height="56">'
    . '<span>Brand Name</span>'
    . '</a>'
)->toArray();
$markup = (string) ( $lockup['serialized_blocks'] ?? '' );
$css = $cssOf($lockup);

$assert(
    str_contains($markup, $neutralButton) && str_contains($markup, 'flex items-center gap-3'),
    'a role=button brand lockup keeps its source flex classes on the neutralized inner wrapper',
    $markup
);
$assert(
    str_contains($css, $flexLinkRule),
    'engine-support CSS restores the source flex container on the link that still generates a box',
    $css
);
$assert(
    str_contains($css, 'padding:inherit!important') && str_contains($css, 'display:flex!important'),
    'the restored flex container inherits source padding instead of core .wp-element-button chrome',
    $css
);

$inlineFlex = ( new HtmlTransformer() )->transform(
    '<a class="inline-flex items-center gap-2" href="/" role="button">'
    . '<img src="icon.png" alt="" width="16" height="16">'
    . '<span>Label</span>'
    . '</a>'
)->toArray();
$inlineMarkup = (string) ( $inlineFlex['serialized_blocks'] ?? '' );
$inlineCss = $cssOf($inlineFlex);
$assert(
    str_contains($inlineMarkup, 'inline-flex') && str_contains($inlineCss, $flexLinkRule),
    'an inline-flex lockup uses the same transferred flex-container rule',
    $inlineMarkup . "\n" . $inlineCss
);

$chip = ( new HtmlTransformer() )->transform(
    '<style>.row{display:flex;flex-wrap:wrap;gap:12px}.chip{padding:8px 20px;font-size:14px;line-height:20px}</style>'
    . '<div class="row"><button class="chip" type="button">All</button></div>'
)->toArray();
$chipCss = $cssOf($chip);
$chipMarkup = (string) ( $chip['serialized_blocks'] ?? '' );
$assert(
    str_contains($chipMarkup, $neutralButton)
        && str_contains($chipCss, ':where(.' . $neutralButton . ')>.wp-block-button__link{' . LayoutParticipation::transferredItemDeclarations() . '}')
        && ( ! str_contains($chipMarkup, ' class="wp-block-button flex') && ! str_contains($chipMarkup, ' class="wp-block-button inline-flex') ),
    'a non-flex layout-child chip still uses the inline transferred-item rule, not the flex-container override',
    $chipMarkup
);

if ( $failures ) {
    fwrite(STDERR, $failures . " neutralized-flex-button-row test(s) failed\n");
    exit(1);
}

echo 'Neutralized-flex-button-row tests: ' . $passes . " passed\n";
