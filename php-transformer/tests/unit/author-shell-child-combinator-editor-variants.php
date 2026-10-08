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
        ':root .editor-styles-wrapper .sqs-html-content .scaled-text-container :where(.' . $layer . ')> .scaled-text h1{font-size:inherit !important}'
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
    str_contains($multiCss, ':root .editor-styles-wrapper .wrap :where(.' . $layer . ')> .box > .label')
        && str_contains($multiCss, ':root .editor-styles-wrapper .wrap > .box :where(.' . $layer . ')> .label'),
    'each child combinator of a multi-edge selector gets its own relaxation',
    $multiCss
);

$mediaVariant = $transform(
    '<style>@media (min-width:768px){.wrap > .box{color:red}}</style><div class="wrap"><div class="box"><p>Copy</p></div></div>'
);
$mediaCss = $cssFor($mediaVariant);
$assert(
    str_contains($mediaCss, '@media (min-width:768px){.wrap > .box{color:red}:root .editor-styles-wrapper .wrap :where(.' . $layer . ')> .box{color:red}}'),
    'the variant stays inside the authored media condition',
    $mediaCss
);

$descendantOnly = $transform(
    '<style>.wrap .box{color:red}</style><div class="wrap"><div class="box"><p>Copy</p></div></div>'
);
$descendantCss = $cssFor($descendantOnly);
$assert(
    ! str_contains($descendantCss, ':root .editor-styles-wrapper .wrap'),
    'descendant-only authored selectors need no shell variant',
    $descendantCss
);

$attributeSafe = $transform(
    '<style>.wrap > .box::after{content:"a>b"}</style><div class="wrap"><div class="box"><p>Copy</p></div></div>'
);
$attributeCss = $cssFor($attributeSafe);
$assert(
    str_contains($attributeCss, ':root .editor-styles-wrapper .wrap :where(.' . $layer . ')> .box::after{content:"a>b"}'),
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
        '.wrap > .box{color:red}:root .editor-styles-wrapper .wrap :where(.' . $layer . ')> .box{color:red}'
    ),
    'the original rule keeps a plain front-end selector and the variant is editor-scoped',
    $frontEndCss
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Author shell child combinator editor variants: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Author shell child combinator editor variants passed: {$passes} assertions\n");
