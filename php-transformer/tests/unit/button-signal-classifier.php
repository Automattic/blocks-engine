<?php
declare(strict_types=1);

/**
 * Unit coverage for the shared button signal classifier used by HTML transform
 * button promotion and visual parity probes.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonSignalClassifier;

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

$element = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $body = $document->getElementsByTagName('body')->item(0);
    if ( ! $body instanceof DOMElement || ! $body->firstElementChild instanceof DOMElement ) {
        throw new RuntimeException('Unable to parse fixture element.');
    }

    return $body->firstElementChild;
};

$classifier = new ButtonSignalClassifier();

$assert($classifier->hasClassSignal($element('<a class="hero-btn" href="#">Learn more</a>')), '1: class signal detects btn substring');
$assert($classifier->hasClassSignal($element('<a id="actionButton" href="#">Learn more</a>')), '2: id signal detects button substring');
$assert($classifier->hasTransformSignal($element('<a role="button" href="#">Learn more</a>')), '3: role=button is a transform signal');
$assert(! $classifier->hasTransformSignal($element('<a class="cta" href="#">Learn more</a>')), '4: CTA class alone is not a button surface');
$assert(! $classifier->hasTransformSignal($element('<a class="primary-action" href="#">Learn more</a>')), '5: action class alone is not a button surface');
$assert(! $classifier->hasTransformSignal($element('<a href="#">Buy now</a>')), '6: action text alone is not a button surface');
$assert($classifier->hasStyleSignal($element('<a style="padding:12px 18px;background:#135e96" href="#">Learn more</a>')), '7: padding plus filled background is a style signal');
$assert($classifier->hasStyleSignal($element('<a style="padding:12px 18px;border-radius:999px" href="#">Learn more</a>')), '8: padding plus radius is a style signal');
$assert(! $classifier->hasStyleSignal($element('<a style="padding:12px 18px;background:transparent" href="#">Learn more</a>')), '9: transparent background alone is not a style signal');
$assert(! $classifier->hasStyleSignal($element('<a style="padding-bottom:8px;border-bottom:1px solid currentColor" href="#">Learn more</a>')), '10: underline border and padding are not a button surface');
$assert(! $classifier->hasTransformSignal($element('<a href="#">Learn more</a>')), '11: plain link has no transform signal');
$assert(! $classifier->hasStyleSignal($element('<a style="padding:0;background:#135e96" href="#">Learn more</a>')), '12: zero reset padding plus a background is not a button surface');
$assert(! $classifier->hasStyleSignal($element('<a style="padding:0px 0rem 0%;border-radius:999px" href="#">Learn more</a>')), '13: zero unit padding plus rounding is not a button surface');
$assert($classifier->hasStyleSignal($element('<a style="padding:0 0 1px;background:#135e96" href="#">Learn more</a>')), '14: any non-zero shorthand padding retains the style signal');
$assert($classifier->hasStyleSignal($element('<a style="padding:var(--control-padding);background:#135e96" href="#">Learn more</a>')), '15: unresolved authored padding retains the style signal');

$result = ( new HtmlTransformer() )->transform('<a style="padding:12px 18px;background:#135e96;color:#fff" href="/buy">Buy tickets</a>', array())->toArray();
$button = $result['blocks'][0]['innerBlocks'][0] ?? array();
$assert('core/buttons' === ($result['blocks'][0]['blockName'] ?? ''), '12: styled anchor is promoted to core/buttons', json_encode($result['blocks'] ?? array()));
$assert('core/button' === ($button['blockName'] ?? ''), '13: styled anchor inner block is core/button', json_encode($button));
$assert('/buy' === ($button['attrs']['url'] ?? ''), '14: styled anchor promotion preserves URL', json_encode($button['attrs'] ?? array()));

$stylesheetButton = ( new HtmlTransformer() )->transform('<style>.primary-control{padding:12px 18px;border:2px solid #135e96;border-radius:6px;color:#135e96}</style><a class="primary-control" href="/buy">Buy tickets</a>', array())->toArray();
$stylesheetButtonCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $stylesheetButton['assets'] ?? array()));
$assert('core/button' === ($stylesheetButton['blocks'][0]['innerBlocks'][0]['blockName'] ?? ''), '15: resolved author CSS with a visible surface promotes an anchor', json_encode($stylesheetButton['blocks'] ?? array()));
$assert(str_contains($stylesheetButtonCss, '> :where(.wp-block-button__link){padding:12px 18px') && str_contains($stylesheetButtonCss, 'border:2px solid #135e96'), '16: true button author selectors style the rendered inner anchor', $stylesheetButtonCss);
$assert('pass' === ($stylesheetButton['source_reports']['wp_block_validity']['status'] ?? ''), '17: true button conversion remains editor-valid', json_encode($stylesheetButton['source_reports']['wp_block_validity'] ?? array()));

$skipLink = ( new HtmlTransformer() )->transform('<style>.skip-link{position:fixed;top:-200px;left:0;padding:12px 18px;background:#135e96;color:#fff;border-radius:999px}.skip-link:focus{top:0}</style><a class="skip-link" href="#content">Skip to content</a><main id="content">Content</main>', array())->toArray();
$skipLinkMarkup = (string) ($skipLink['serialized_blocks'] ?? '');
$skipLinkCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $skipLink['assets'] ?? array()));
$assert('core/paragraph' === ($skipLink['blocks'][0]['blockName'] ?? '') && ! str_contains($skipLinkMarkup, '<!-- wp:button') && str_contains($skipLinkMarkup, '<a class="skip-link" href="#content">Skip to content</a>'), '18: fixed fragment skip links retain native anchor content instead of gaining button wrappers', $skipLinkMarkup);
$assert(str_contains($skipLinkMarkup, 'blocks-engine-positioned-fragment-link-carrier') && str_contains($skipLinkCss, ':where(.blocks-engine-positioned-fragment-link-carrier){display:contents!important}') && str_contains($skipLinkCss, '.skip-link:focus{top:0}'), '19: positioned fragment links use a zero-flow valid carrier while focus styling remains on the native anchor', $skipLinkMarkup . $skipLinkCss);
$assert('pass' === ($skipLink['source_reports']['wp_block_validity']['status'] ?? ''), '20: positioned fragment link serialization remains Gutenberg-valid', json_encode($skipLink['source_reports']['wp_block_validity'] ?? array()));

$fragmentCta = ( new HtmlTransformer() )->transform('<a class="primary-cta" href="#pricing" style="display:inline-flex;padding:12px 18px;background:#135e96;color:#fff">See pricing</a>', array())->toArray();
$assert('core/button' === ($fragmentCta['blocks'][0]['innerBlocks'][0]['blockName'] ?? '') && '#pricing' === ($fragmentCta['blocks'][0]['innerBlocks'][0]['attrs']['url'] ?? ''), '21: ordinary fragment CTAs retain native core/button conversion', json_encode($fragmentCta['blocks'] ?? array()));

$streamRow = ( new HtmlTransformer() )->transform('<style>.stream-btn{display:inline-flex;padding:10px 16px;background:#135e96;color:#fff;border-radius:4px}</style><div><a class="stream-btn" href="/listen">Listen live</a><a class="stream-btn" href="/schedule">View schedule</a></div>', array())->toArray();
$streamRowCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $streamRow['assets'] ?? array()));
$assert('core/buttons' === ($streamRow['blocks'][0]['blockName'] ?? '') && 2 === count($streamRow['blocks'][0]['innerBlocks'] ?? array()), '22: direct anchor CTA rows group explicit stylesheet surfaces as buttons', json_encode($streamRow['blocks'] ?? array()));
$assert(2 === substr_count($streamRowCss, '> :where(.wp-block-button__link)') && str_contains($streamRowCss, '{display:inline-flex!important;padding:10px 16px!important;background:#135e96'), '23: direct anchor CTA stylesheet selectors remain on both rendered button links', $streamRowCss);

$buttonResult = ( new HtmlTransformer() )->transform('<button style="padding:12px 18px;background:#135e96;color:#fff">Buy tickets</button>', array())->toArray();
$nativeButton = $buttonResult['blocks'][0]['innerBlocks'][0] ?? array();
$assert('core/buttons' === ($buttonResult['blocks'][0]['blockName'] ?? ''), '20: native button is dispatched to core/buttons', json_encode($buttonResult['blocks'] ?? array()));
$assert('core/button' === ($nativeButton['blockName'] ?? ''), '21: native button inner block is core/button', json_encode($nativeButton));
$assert('button' === ($nativeButton['attrs']['tagName'] ?? ''), '22: native button keeps button tagName', json_encode($nativeButton['attrs'] ?? array()));

$roleButton = ( new HtmlTransformer() )->transform('<a role="button" aria-label="Open player" href="/player">Play</a>', array())->toArray();
$roleButtonFallbacks = array_values(array_filter($roleButton['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_stylable_button_accessible_name_fallback' === ($fallback['diagnostic_code'] ?? null)));
$assert('core/html' === ($roleButton['blocks'][0]['blockName'] ?? '') && str_contains((string) ($roleButton['blocks'][0]['attrs']['content'] ?? ''), 'aria-label="Open player"') && 1 === count($roleButtonFallbacks), '23: role=button with a materially different accessible name remains a diagnostic fallback', json_encode($roleButton['blocks'] ?? array()));

$plainLinkResult = ( new HtmlTransformer() )->transform('<a href="/about">About us</a>', array())->toArray();
$plainLink = $plainLinkResult['blocks'][0] ?? array();
$assert('core/paragraph' === ($plainLink['blockName'] ?? ''), '24: plain anchor stays paragraph rich text', json_encode($plainLinkResult['blocks'] ?? array()));
$assert(str_contains((string) ($plainLink['innerHTML'] ?? ''), 'href="/about"'), '25: plain anchor preserves href in content', json_encode($plainLink ?? array()));

$textualCta = ( new HtmlTransformer() )->transform('<style>.hero__cta{padding-bottom:8px;border-bottom:1px solid currentColor;color:#135e96}</style><section><a class="hero__cta" href="/explore">Explore the collection</a></section>', array())->toArray();
$textualMarkup = (string) ($textualCta['serialized_blocks'] ?? '');
$assert(! str_contains($textualMarkup, 'wp:button') && str_contains($textualMarkup, 'hero__cta') && str_contains($textualMarkup, 'href="/explore"'), '26: fixture-37 underlined hero CTA remains an authored text anchor', $textualMarkup);
$assert(! str_contains($textualMarkup, 'wp-block-buttons'), '27: fixture-37 textual CTA has no synthetic buttons wrapper or wrapper layout drift', $textualMarkup);

$legalLinks = ( new HtmlTransformer() )->transform('<footer><a class="legal-link" href="/impressum">Impressum</a><a class="legal-link" href="/privacy">Datenschutz</a><a class="legal-link" href="/terms">AGB</a></footer>', array())->toArray();
$legalMarkup = (string) ($legalLinks['serialized_blocks'] ?? '');
$assert(! str_contains($legalMarkup, 'wp:button') && ! str_contains($legalMarkup, 'wp-block-buttons') && str_contains($legalMarkup, 'href="/impressum"') && str_contains($legalMarkup, 'href="/privacy"') && str_contains($legalMarkup, 'href="/terms"'), '28: fixture-37 legal link rows retain anchor semantics rather than becoming buttons', $legalMarkup);

$linkedPhoto = ( new HtmlTransformer() )->transform('<style>*,*::before,*::after{padding:0}.photo{display:block;width:100%;aspect-ratio:21/9;background:#232224}</style><a href="/work" aria-label="View work"><div class="photo" role="img" aria-label="Work"><span>Installation view</span></div></a>', array())->toArray();
$linkedPhotoMarkup = (string) ($linkedPhoto['serialized_blocks'] ?? '');
$assert(! str_contains($linkedPhotoMarkup, 'wp:button') && str_contains($linkedPhotoMarkup, 'class="photo ') && str_contains($linkedPhotoMarkup, 'href="/work"'), '29: reset-padded linked visual media retains its authored surface and non-button link', $linkedPhotoMarkup);

if ( $failures > 0 ) {
    fwrite(STDERR, "ButtonSignalClassifier unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "ButtonSignalClassifier unit tests: {$passes} passed\n");
