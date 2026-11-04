<?php
declare(strict_types=1);

/**
 * An element whose visible outline is drawn by an inline `box-shadow` (with
 * `border:0`) must keep the shadow after conversion. Gamma, Chakra and
 * Tailwind-style sources draw outline buttons this way
 * (`box-shadow: inset 0 0 0 1px <color>`), so a dropped shadow renders the
 * button borderless.
 *
 * Two lowerings are covered:
 *  - a link inside a paragraph keeps its inline box values on a generated
 *    anchor-owned class rule (`a.be-inline-geometry-*{...!important}`);
 *  - a link lowered to core/button keeps it on the projected control's
 *    generated carrier's link rule — including when the control carries its own geometry
 *    carrier already (e.g. an authored inline width), which used to unset the
 *    shadow from the fallback declarations.
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

$cssFor = static function (array $result): string {
    return implode("\n", array_column(
        array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ( $asset['kind'] ?? null )),
        'content'
    ));
};

// ---------------------------------------------------------------------------
// The reduced regression page: an inline shadow-drawn outline link in a
// paragraph. The shadow is painted by the link's own box, so it must ride the
// anchor-owned generated rule, not fall off with the inline style RichText
// strips from the anchor.
// ---------------------------------------------------------------------------
$result = ( new HtmlTransformer() )->transform(
    (string) file_get_contents(dirname(__DIR__) . '/fixtures/inline-box-shadow-outline.html')
)->toArray();
$markup = (string) ( $result['serialized_blocks'] ?? '' );
$css = $cssFor($result);

$assert(str_contains($markup, '<!-- wp:paragraph'), 'the outline link stays inside an editable paragraph', $markup);
$assert(1 === preg_match('/<a class="btn (be-inline-geometry-[0-9a-f]+)" href="#top"[^>]*>/', $markup, $anchorMatch), 'the link keeps its source class and a generated anchor class', $markup);
$anchorClass = (string) ( $anchorMatch[1] ?? '' );
$assert('' !== $anchorClass, 'the anchor owns a generated class for its box values', $markup);
$assert(1 === preg_match('/a\\.' . $anchorClass . '\\{[^}]*box-shadow:inset 0 0 0 1px #383838!important[^}]*\\}/', $css), 'the anchor rule carries the inline box-shadow with inline priority', $css);
$assert(1 === preg_match('/a\\.' . $anchorClass . '\\{[^}]*background-color:#fff!important[^}]*\\}/', $css), 'the anchor rule still carries the inline background', $css);
$assert(! str_contains($css, 'box-shadow:none'), 'no generated rule cancels the shadow', $css);
$assert('pass' === ( $result['source_reports']['wp_block_validity']['status'] ?? '' ), 'the compiled blocks remain valid', (string) json_encode($result['source_reports']['wp_block_validity'] ?? array()));

// ---------------------------------------------------------------------------
// core/button lowering: the resolved shadow claims the button's shadow support
// (which skips serialization), so the normalized fallback must keep projecting
// it onto a generated carrier — even when the control already carries its own
// geometry carrier (an authored inline width).
// ---------------------------------------------------------------------------
$buttonSource = '<!doctype html><html><head><style>'
    . '.btn{display:inline-block;padding:8px 16px;color:#383838;text-decoration:none;border-radius:4px}'
    . '</style></head><body><div class="wp-block-buttons">'
    . '<a class="btn" href="#one" style="border:0px;box-shadow:inset 0 0 0 1px #383838;background-color:#fff;width:180px">Button one</a>'
    . '<a class="btn" href="#two" style="border:0px;box-shadow:inset 0 0 0 1px #383838;background-color:#fff">Button two</a>'
    . '</div></body></html>';
$buttonResult = ( new HtmlTransformer() )->transform($buttonSource)->toArray();
$buttonMarkup = (string) ( $buttonResult['serialized_blocks'] ?? '' );
$buttonCss = $cssFor($buttonResult);

$assert(2 === substr_count($buttonMarkup, '<!-- wp:button '), 'both shadow-drawn links lower to core/button', $buttonMarkup);
foreach ( array( '#one', '#two' ) as $href ) {
    $link = strpos($buttonMarkup, 'href="' . $href . '"');
    $assert(false !== $link, 'the ' . $href . ' control keeps its destination', $buttonMarkup);
}
$assert(2 === preg_match_all('/\\.be-inline-geometry-[0-9a-f]+>\\.wp-block-button__link\\{box-shadow:inset 0 0 0 1px #383838 !important\\}/', $buttonCss), 'both controls paint the shadow on their links', $buttonCss);
$assert(1 === preg_match('/\\.be-inline-geometry-[0-9a-f]+\\{width:180px !important;max-width:100% !important\\}/', $buttonCss), 'the existing width geometry stays on its carrier', $buttonCss);
$assert(2 === substr_count($buttonCss, 'box-shadow:inset 0 0 0 1px #383838'), 'both controls keep their shadow exactly once in the generated CSS', $buttonCss);
$assert('pass' === ( $buttonResult['source_reports']['wp_block_validity']['status'] ?? '' ), 'the lowered buttons remain valid', (string) json_encode($buttonResult['source_reports']['wp_block_validity'] ?? array()));

// A standalone control paints its background on the link, not the carrier div.
$standaloneResult = ( new HtmlTransformer() )->transform(
    '<style>.btn{display:inline-flex;padding:8px 16px;color:#383838;text-decoration:none}</style><div class="row"><a class="btn" href="/" style="border:0px;box-shadow:inset 0 0 0 1px #383838;background-color:#fff">Back to start</a></div>'
)->toArray();
$standaloneMarkup = (string) ( $standaloneResult['serialized_blocks'] ?? '' );
$standaloneCss = $cssFor($standaloneResult);
$assert(1 === preg_match('/<div class="wp-block-button ([^"]+)"[^>]*><a class="wp-block-button__link [^"]*"[^>]*style="[^"]*background-color:#fff/', $standaloneMarkup, $buttonMatch), 'the standalone link lowers to a button with its own background', $standaloneMarkup);
$carriers = array();
preg_match_all('/be-inline-geometry-[0-9a-f]+/', $buttonMatch[1] ?? '', $carriers);
$linkShadowRules = 0;
foreach ( $carriers[0] as $carrier ) {
    $linkShadowRules += preg_match('/\\.' . $carrier . '\\s*>\\s*\\.wp-block-button__link\\{[^}]*box-shadow:inset 0 0 0 1px #383838 !important/', $standaloneCss);
    $assert(0 === preg_match('/\\.' . $carrier . '\\{[^}]*box-shadow:/', $standaloneCss), 'the button wrapper does not paint the shadow', $standaloneCss);
}
$assert(1 === $linkShadowRules, 'the standalone shadow rule targets the background-owning button link exactly once', $standaloneCss);
$assert('pass' === ( $standaloneResult['source_reports']['wp_block_validity']['status'] ?? '' ), 'the standalone button remains valid');

if ( 0 !== $failures ) {
    fwrite(STDERR, "inline box-shadow outline contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Inline box-shadow outline contract passed\n");
