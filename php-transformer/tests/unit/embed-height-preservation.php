<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

/** @param array<string, mixed> $result */
$combinedAssetCss = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        $css .= (string) ($asset['content'] ?? '') . "\n";
    }
    return $css;
};

/**
 * Reproduces the reported bug: https://harrykahanhai.lovable.app/ sizes its
 * Spotify artist embed at 1022x520 (~6 tracks visible). Spotify's oEmbed
 * response ignores that and returns its own default height="352" (~3
 * tracks) because `WP_Embed::autoembed_callback()` calls `shortcode()` with
 * an empty attribute array — nothing the block stores can influence the
 * provider's own oEmbed response.
 *
 * An authored ABSOLUTE height carries exactly, not as a proportional
 * approximation: a generated stylesheet rule fixes `.wp-block-embed__wrapper`
 * to the source's literal 520px, and `wp-has-aspect-ratio` (core's own
 * `.wp-has-aspect-ratio iframe{position:absolute;inset:0;width:100%;
 * height:100%}`) stretches *whatever* iframe autoembed() ends up injecting
 * to fill that exact box — independent of the provider's own returned
 * markup or its own `height` attribute.
 */
$authoredHeight = ( new HtmlTransformer() )->transform(
    '<main><iframe title="Harrykahanhai on Spotify" src="https://open.spotify.com/embed/artist/46aKqTxrSund2Ccj4oPRsq?utm_source=generator&theme=0" width="1022" height="520" loading="lazy" class="block w-full border-0"></iframe></main>'
)->toArray();
$authoredHeightBlock = $authoredHeight['blocks'][0] ?? array();
$authoredHeightClassName = (string) ($authoredHeightBlock['attrs']['className'] ?? '');
$authoredHeightMarkup = (string) ($authoredHeight['serialized_blocks'] ?? '');
$assert('core/embed' === ($authoredHeightBlock['blockName'] ?? ''), 'authored-height Spotify iframe converts to core/embed');
$assert('spotify' === ($authoredHeightBlock['attrs']['providerNameSlug'] ?? ''), 'authored-height Spotify iframe records its provider slug');
$assert(
    str_contains($authoredHeightClassName, 'wp-has-aspect-ratio')
    && ! str_contains($authoredHeightClassName, 'wp-embed-aspect-'),
    'an authored absolute height carries wp-has-aspect-ratio WITHOUT a proportional wp-embed-aspect-* preset — the box is fixed, not approximated'
);
$assert(
    1 === preg_match('/\bbe-inline-geometry-[0-9a-f]{20,}\b/', $authoredHeightClassName, $carrierMatch),
    'an authored absolute height mints a generated-stylesheet carrier class on the figure'
);
$assert(
    str_contains($authoredHeightMarkup, '<figure class="wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify block w-full border-0 ' . $carrierMatch[0] . ' wp-has-aspect-ratio blocks-engine-synthetic-embed-figure">'),
    'the carrier and wp-has-aspect-ratio classes land on the saved <figure>, which autoembed() never touches'
);
$assert(
    str_contains($combinedAssetCss($authoredHeight), '.' . $carrierMatch[0] . ' .wp-block-embed__wrapper{height:520px!important}'),
    'the generated stylesheet fixes .wp-block-embed__wrapper to the source\'s literal 520px — an exact box, not a 16:9-family approximation'
);
$assert(
    1 === preg_match('|^(\s*)(https?://[^\s<>"]+)(\s*)$|im', $authoredHeightMarkup, $matches)
    && 'https://open.spotify.com/artist/46aKqTxrSund2Ccj4oPRsq' === $matches[2],
    'the #1909 canonical newline URL shape is unaffected: WP_Embed::autoembed() still matches the bare URL line'
);

/**
 * The mechanism is generic, not Spotify-specific: a YouTube iframe authored
 * at an exact 1022x520-shaped absolute height still resolves to its own
 * literal pixel value (315px here), not a preset — proving genericity
 * across providers without relying on any one provider's numbers.
 */
$youtubeAbsolute = ( new HtmlTransformer() )->transform(
    '<main><iframe title="Demo" src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315"></iframe></main>'
)->toArray();
$youtubeAbsoluteBlock = $youtubeAbsolute['blocks'][0] ?? array();
$youtubeAbsoluteClassName = (string) ($youtubeAbsoluteBlock['attrs']['className'] ?? '');
$assert(
    str_contains($youtubeAbsoluteClassName, 'wp-has-aspect-ratio') && ! str_contains($youtubeAbsoluteClassName, 'wp-embed-aspect-'),
    'a 560x315 authored YouTube iframe also carries its absolute height, not the wp-embed-aspect-16-9 preset it happens to be exact for'
);
$assert(
    str_contains($combinedAssetCss($youtubeAbsolute), '.wp-block-embed__wrapper{height:315px!important}'),
    'the YouTube absolute-height carrier rule fixes the wrapper to the source\'s own literal 315px'
);

/**
 * "Authored ratio only": the source expresses a proportion (a CSS
 * `aspect-ratio` declaration) but no absolute height at all — a real,
 * generic pattern for responsive embeds (`iframe{aspect-ratio:16/9;
 * width:100%}`). #1918's fallback still owns this case: the nearest of
 * core's seven `wp-embed-aspect-*` presets, since there is no concrete
 * pixel height to carry exactly.
 */
$ratioOnly = ( new HtmlTransformer() )->transform(
    '<main><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" style="aspect-ratio: 16 / 9; width: 100%"></iframe></main>'
)->toArray();
$ratioOnlyBlock = $ratioOnly['blocks'][0] ?? array();
$ratioOnlyClassName = (string) ($ratioOnlyBlock['attrs']['className'] ?? '');
$assert(
    str_contains($ratioOnlyClassName, 'wp-embed-aspect-16-9') && str_contains($ratioOnlyClassName, 'wp-has-aspect-ratio'),
    'a bare CSS aspect-ratio (16/9), with no absolute height authored, falls back to the nearest native preset — #1918\'s mechanism, unregressed'
);

/**
 * When the source expresses no explicit height and no ratio at all, the
 * provider default must still win — the fix must not force a height that
 * was never authored.
 */
$noAuthoredHeight = ( new HtmlTransformer() )->transform(
    '<main><iframe src="https://open.spotify.com/embed/track/4iV5W9uYEdYUVa79Axb7Rh"></iframe></main>'
)->toArray();
$noAuthoredHeightBlock = $noAuthoredHeight['blocks'][0] ?? array();
$assert(
    ! array_key_exists('className', $noAuthoredHeightBlock['attrs'] ?? array()),
    'a Spotify iframe with no authored sizing at all gets no className; the provider default is left alone'
);
$assert(
    array() === ($noAuthoredHeight['assets'] ?? array()),
    'no sizing signal means no generated stylesheet carrier is minted at all'
);

/**
 * Percentage geometry cannot anchor a real absolute height (it describes the
 * iframe relative to an unknown container), so it must be treated the same
 * as "no authored sizing" rather than producing a nonsensical fixed height.
 */
$percentageDimensions = ( new HtmlTransformer() )->transform(
    '<main><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="100%" height="100%"></iframe></main>'
)->toArray();
$percentageDimensionsBlock = $percentageDimensions['blocks'][0] ?? array();
$assert(
    ! array_key_exists('className', $percentageDimensionsBlock['attrs'] ?? array()),
    'percentage-only iframe geometry does not fabricate a fixed height or an aspect-ratio class'
);

/**
 * The reported bug: https://harrykahanhai.lovable.app/ wraps its sized
 * Spotify iframe in an authored bordered div with no margin of its own.
 * core/embed's saved <figure> carries a default bottom margin the source
 * has no equivalent for — a WordPress block-structure artifact, not an
 * authored wrapper — which made the containing section render 16px too
 * tall. Marking the figure once an authored sizing intent exists (#1918's
 * ratio or #1923's absolute height) lets engine-support CSS neutralize
 * that unauthored margin, the same primitive already used for a
 * synthesized paragraph's own unauthored margin.
 */
$inAuthoredContainer = ( new HtmlTransformer() )->transform(
    '<main><div class="overflow-hidden rounded-sm border border-ink/10"><iframe title="Harrykahanhai on Spotify" src="https://open.spotify.com/embed/artist/46aKqTxrSund2Ccj4oPRsq" width="1022" height="520" class="block w-full border-0"></iframe></div></main>'
)->toArray();
$inAuthoredContainerMarkup = (string) ($inAuthoredContainer['serialized_blocks'] ?? '');
$inAuthoredContainerCss = $combinedAssetCss($inAuthoredContainer);
$assert(
    1 === preg_match('/<figure class="wp-block-embed[^"]*\bblocks-engine-synthetic-embed-figure\b[^"]*"/', $inAuthoredContainerMarkup),
    'an embed sized inside an authored container marks its unauthored figure margin for neutralization'
);
$assert(
    str_contains($inAuthoredContainerCss, ':root :where(.blocks-engine-synthetic-embed-figure){margin-top:0;margin-bottom:0}'),
    'engine-support CSS carries the zero-specificity reset for the marked embed figure, ordered before author CSS'
);
$assert(
    str_contains($inAuthoredContainerMarkup, '<!-- wp:group {"className":"overflow-hidden rounded-sm border border-ink/10"} -->'),
    'the authored bordered wrapper survives as its own group around the embed (unaffected by the margin fix)'
);

/**
 * Nuance: neutralize only where the block structure introduced the margin.
 * A margin the source genuinely authored right on the iframe is captured as
 * core/embed's own native spacing support and renders as an inline style on
 * the same <figure> — which any class-based reset cannot outrank — so it
 * must still win.
 */
$authoredMargin = ( new HtmlTransformer() )->transform(
    '<main><div class="overflow-hidden rounded-sm border border-ink/10"><iframe title="Harrykahanhai on Spotify" src="https://open.spotify.com/embed/artist/46aKqTxrSund2Ccj4oPRsq" width="1022" height="520" style="margin-bottom:24px" class="block w-full border-0"></iframe></div></main>'
)->toArray();
$authoredMarginMarkup = (string) ($authoredMargin['serialized_blocks'] ?? '');
$assert(
    1 === preg_match('/<figure class="wp-block-embed[^"]*\bblocks-engine-synthetic-embed-figure\b[^"]*" style="margin-bottom:24px">/', $authoredMarginMarkup),
    'a genuinely authored embed margin still renders as an inline style on the marked figure and keeps winning over the reset'
);

echo "Embed height preservation tests passed ({$assertions} assertions)\n";
