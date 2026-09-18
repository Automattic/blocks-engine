<?php
declare(strict_types=1);

/**
 * RichTextMaterializer::contentWithInlineSafeButtonsLowered() lowers a safe
 * inline `<button>` into a `<mark>` RichText carrier instead of leaving the
 * literal `<button>` tag in a paragraph's RichText content. The two failure
 * modes this guards against are the original bug (PR #1992) and the
 * regression that reverted it (PR #1993):
 *
 *  - Before any fix: `RichTextMaterializer::requiresHtmlFallback()` rejects
 *    any `<button>` by tag name, so the whole paragraph degrades to
 *    core/html.
 *  - #1992's fix: the fallback *check* stopped tripping, but the *content*
 *    still carried the literal `<button class="...">` tag into RichText,
 *    which `Contract\EditabilityPolicy`'s zero-tolerance
 *    `structural_rich_text_attribute_count` threshold rejects — a
 *    materialization-aborting regression caught only by a real end-to-end
 *    import, not by any parity fixture.
 *
 * This suite exercises the fix through `HtmlTransformer` end to end and
 * asserts the engine's own `EditabilityPolicy` — not just the parity
 * fixture's markup expectations — accepts the result.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;

$assertions = 0;
$failures   = array();
$assert     = static function (bool $condition, string $label, string $detail = '') use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']' . ('' !== $detail ? ': ' . $detail : '');
    }
};

$transform = static fn (string $html, string $css = ''): array =>
    ( new HtmlTransformer() )->transform($html, '' === $css ? array() : array( 'static_css' => $css ))->toArray();

$editabilityStatus = static function (array $result): string {
    $report = ( new EditabilityReport() )->fromBlocks(
        is_array($result['blocks'] ?? null) ? $result['blocks'] : array(),
        'inline-button-richtext-lowering',
        (string) ($result['serialized_blocks'] ?? '')
    );
    return (string) ( ( new EditabilityPolicy() )->evaluate($report)['status'] ?? '' );
};

// The exact regression shape (posts/page-sellerregister.post_content): a
// plain typeless button labelling an inline control, no form ancestor, no
// wired behavior, and a class the source stylesheet resolves to a real color.
$result = $transform(
    '<main><p class="text-center text-xs text-muted-foreground mt-4">Already a seller? <button class="text-primary hover:underline">Sign in</button></p></main>',
    '.text-primary { color: #0a5b3d; }'
);
$serialized = (string) ($result['serialized_blocks'] ?? '');

$assert('success' === ($result['status'] ?? ''), 'transform-succeeds');
$assert(0 === substr_count($serialized, 'wp:html'), 'no-core-html-fallback');
$assert(0 === substr_count($serialized, '<button'), 'no-literal-button-tag-survives');
$assert(1 === substr_count($serialized, '<mark '), 'button-lowers-to-one-mark-carrier');
$assert(str_contains($serialized, 'Already a seller?') && str_contains($serialized, '>Sign in</mark>'), 'paragraph-text-preserved-exactly');
$assert(str_contains($serialized, 'role="button"') && str_contains($serialized, 'tabindex="0"'), 'mark-carrier-stays-labelled-and-keyboard-reachable');
$assert(str_contains($serialized, 'color:#0a5b3d'), 'buttons-resolved-author-color-is-baked-into-the-mark-style-attribute');
$assert(! str_contains($serialized, 'text-primary'), 'buttons-utility-class-is-not-carried-onto-the-inline-element');
$assert('passed' === $editabilityStatus($result), 'editability-policy-accepts-the-lowered-mark-carrier');

// A button whose class resolves to no author declarations at all still lowers
// cleanly, defaulting to a transparent/inherited mark so no accidental
// highlight box appears.
$plainResult = $transform('<main><p>Already a seller? <button class="text-primary hover:underline">Sign in</button></p></main>');
$plainSerialized = (string) ($plainResult['serialized_blocks'] ?? '');
$assert(str_contains($plainSerialized, '<mark style="background-color:transparent;color:inherit" role="button" tabindex="0">Sign in</mark>'), 'unresolved-class-defaults-to-transparent-inherit-mark');
$assert('passed' === $editabilityStatus($plainResult), 'editability-policy-accepts-the-default-mark-carrier');

// Negative guards mirrored from the parity fixtures: unsafe buttons still
// force core/html, and the engine's own policy has nothing to reject because
// no RichText content is produced for them at all.
foreach ( array(
    'form-submit-semantics' => '<main><p>Get updates from us. <button class="cta">Subscribe</button></p></main>',
    'runtime-handler'       => '<main><p>Toggle menu <button aria-expanded="false" aria-controls="menu">Menu</button></p></main>',
) as $label => $html ) {
    $unsafe = $transform($html);
    $unsafeSerialized = (string) ($unsafe['serialized_blocks'] ?? '');
    $assert(str_contains($unsafeSerialized, '<!-- wp:html -->'), 'unsafe-button-' . $label . '-still-falls-back-to-html');
    $assert(1 === substr_count($unsafeSerialized, '<button'), 'unsafe-button-' . $label . '-keeps-literal-button-tag');
}

// A standalone button (no enclosing paragraph) is untouched by this lowering
// and still dispatches through the native button converter.
$standalone = $transform('<main><button class="text-primary hover:underline">Sign in</button></main>');
$standaloneSerialized = (string) ($standalone['serialized_blocks'] ?? '');
$assert(str_contains($standaloneSerialized, 'wp:buttons'), 'standalone-button-still-becomes-core-buttons');
$assert(0 === substr_count($standaloneSerialized, '<mark'), 'standalone-button-is-never-lowered-to-mark');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Inline button RichText lowering tests: ' . $assertions . " passed\n";
