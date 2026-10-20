<?php
declare(strict_types=1);

/**
 * Authored child combinators that cross a layout shell's one Gutenberg-owned
 * editor layer must keep matching in the editor canvas.
 *
 * A layout shell restores the exact source wrapper chain in its editor DOM,
 * but Gutenberg renders the shell's inner blocks inside one extra
 * `.blocks-engine-layout-shell-editor-inner-blocks` div that the saved
 * front-end markup does not have. An authored `wrapper > nested` selector
 * therefore matches the same elements on the front end and silently stops
 * matching in the editor, so authored cascade decisions — such as a scaled
 * headline forced to inherit its wrapper's fluid font-size — revert to the
 * engine's projected typography and the canvas renders the wrong size. The
 * projector must emit an editor-scoped variant whose child combinator
 * reaches through the marked layer, without touching front-end matching.
 *
 * The variant is one nested rule whose every crossing step tolerates the
 * layer (`&>x,&>:where(.layer)>x`); see EditorShellBoundary.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EngineSupportCss;

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

$cssFor = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= "\n" . (string) ( $asset['content'] ?? '' );
        }
    }

    return $css;
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html)->toArray();

$layer = EngineSupportCss::LAYOUT_SHELL_EDITOR_INNER_BLOCKS_CLASS;
$step = static fn (string $child): string => '&>' . $child . ',&>:where(.' . EngineSupportCss::LAYOUT_SHELL_EDITOR_INNER_BLOCKS_CLASS . ')>' . $child;

$scaledHeadline = $transform(
    '<style>.sqs-html-content .scaled-text-container > .scaled-text h1{font-size:inherit !important}</style>'
    . '<div class="sqs-html-content"><div class="scaled-text-container"><div class="scaled-text"><h1>Headline</h1></div></div></div>'
);
$scaledCss = $cssFor($scaledHeadline);

$assert(
    str_contains($scaledCss, '.sqs-html-content .scaled-text-container > .scaled-text h1{font-size:inherit !important}'),
    'the front-end rule keeps its original child combinator',
    $scaledCss
);
$assert(
    str_contains(
        $scaledCss,
        ':where(:root .editor-styles-wrapper) .sqs-html-content .scaled-text-container{' . $step('.scaled-text h1') . '{font-size:inherit !important}}'
    ),
    'the editor variant reaches through the layout shell inner-blocks layer',
    $scaledCss
);
$assert(
    ! str_contains($scaledCss, '>.scaled-text h1{font-size:inherit !important}:root'),
    'the variant is emitted as its own rule, not glued to the original',
    $scaledCss
);

$multiEdge = $transform(
    '<style>.wrap > .box > .label{color:red}</style><div class="wrap"><div class="box"><div class="label"><p>Copy</p></div></div></div>'
);
$multiCss = $cssFor($multiEdge);
$assert(
    str_contains($multiCss, ':where(:root .editor-styles-wrapper) .wrap{' . $step('.box') . '{' . $step('.label') . '{color:red}}}'),
    'each child combinator of a multi-edge selector tolerates the layer once, in one rule',
    $multiCss
);

// The layer is the direct child of its host block element. A descendant step
// before it would let `.wrap > .box > .label` reach a `.label` block nested at
// any depth under `.box`, so a body-text rule could restyle nested headings.
$assert(
    1 !== preg_match('/[^>\s]\s*:where\(\.' . preg_quote($layer, '/') . '\)/', $multiCss . $scaledCss),
    'a relaxed combinator stays a child step into the layer',
    $multiCss
);

$mediaVariant = $transform(
    '<style>@media (min-width:768px){.wrap > .box{color:red}}</style><div class="wrap"><div class="box"><p>Copy</p></div></div>'
);
$mediaCss = $cssFor($mediaVariant);
$assert(
    str_contains($mediaCss, '@media (min-width:768px){.wrap > .box{color:red}:where(:root .editor-styles-wrapper) .wrap{' . $step('.box') . '{color:red}}}'),
    'the variant stays inside the authored media condition',
    $mediaCss
);

$descendantOnly = $transform(
    '<style>.wrap .box{color:red}</style><div class="wrap"><div class="box"><p>Copy</p></div></div>'
);
$descendantCss = $cssFor($descendantOnly);
$assert(
    ! str_contains($descendantCss, ':where(:root .editor-styles-wrapper) .wrap'),
    'descendant-only authored selectors need no shell variant',
    $descendantCss
);

$attributeSafe = $transform(
    '<style>.wrap > .box::after{content:"a>b"}</style><div class="wrap"><div class="box"><p>Copy</p></div></div>'
);
$attributeCss = $cssFor($attributeSafe);
$assert(
    str_contains($attributeCss, ':where(:root .editor-styles-wrapper) .wrap{' . $step('.box::after') . '{content:"a>b"}}'),
    'quoted combinator characters inside declaration values are left alone',
    $attributeCss
);

$frontEndInert = $transform(
    '<style>.wrap > .box{color:red}</style><div class="wrap"><div class="box"><p>Copy</p></div></div>'
);
$frontEndCss = $cssFor($frontEndInert);
$assert(
    str_contains(
        $frontEndCss,
        '.wrap > .box{color:red}:where(:root .editor-styles-wrapper) .wrap{' . $step('.box') . '{color:red}}'
    ),
    'the original rule keeps a plain front-end selector and the variant is editor-scoped',
    $frontEndCss
);

// A variant follows its source rule, so it must carry the same specificity:
// on the front end `.col-6` overrides `.row > *` by source order, and an
// editor variant that outranks `.col-6` would widen every column.
$gridCss = $cssFor($transform(
    '<style>.row > *{width:100%}.col-6{width:50%}</style><div class="row"><div class="col-6"><p>A</p></div><div class="col-6"><p>B</p></div></div>'
));
// `&` weighs what its parent does, so the nested rule weighs its prelude
// plus the step it nests.
$variantSelector = null;
( new \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer() )->visitStyleRules($gridCss, static function (string $prelude, string $body) use (&$variantSelector, $layer): void {
    if ( null === $variantSelector && str_contains($body, $layer) && str_contains($prelude, '.row') && 1 === preg_match('/^,?&>:where\(\.' . preg_quote($layer, '/') . '\)>([^{,]+)\{/', substr($body, strpos($body, ',')), $step) ) {
        $variantSelector = trim($prelude) . ' > :where(.' . $layer . ') > ' . $step[1];
    }
});
$specificity = static fn (string $selector): array => \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::specificityCounts(\Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::parse($selector));
$assert(
    null !== $variantSelector && $specificity($variantSelector) === $specificity('.row > *'),
    'the editor variant ties with its source rule, so later equal-specificity rules still win',
    (string) $variantSelector . ' in ' . $gridCss
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Author shell child combinator editor variants: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Author shell child combinator editor variants passed: {$passes} assertions\n");
