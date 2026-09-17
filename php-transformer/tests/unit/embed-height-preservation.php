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

/**
 * Reproduces the reported bug: https://harrykahanhai.lovable.app/ sizes its
 * Spotify artist embed at 1022x520 (~6 tracks visible). Spotify's oEmbed
 * response ignores that and returns its own default height="352" (~3
 * tracks) because `WP_Embed::autoembed_callback()` calls `shortcode()` with
 * an empty attribute array — nothing the block stores can influence the
 * provider's own oEmbed response. The only durable lever is a className
 * core's own block-library CSS already keys off: `wp-has-aspect-ratio`
 * makes the *eventual, provider-injected* iframe `position:absolute` and
 * `width/height:100%` of a box shaped by `wp-embed-aspect-*`, independent
 * of whatever height the provider's HTML carries.
 *
 * 1022/520 ≈ 1.9654 falls between the 18-9 (2.00) and 16-9 (1.78) presets.
 * Picking by closest absolute distance (0.0346 away from 18-9) — rather
 * than the block editor's own directional `>=` tolerance check in
 * `@wordpress/block-library`'s `embed/util.js`, which requires the ratio to
 * meet or exceed the preset and would reject this exact case — is what
 * lets this real-world non-preset ratio still resolve to its closest
 * native visual treatment instead of losing the author's sizing intent.
 */
$authoredHeight = ( new HtmlTransformer() )->transform(
    '<main><iframe title="Harrykahanhai on Spotify" src="https://open.spotify.com/embed/artist/46aKqTxrSund2Ccj4oPRsq?utm_source=generator&theme=0" width="1022" height="520" loading="lazy" class="block w-full border-0"></iframe></main>'
)->toArray();
$authoredHeightBlock = $authoredHeight['blocks'][0] ?? array();
$authoredHeightMarkup = (string) ($authoredHeight['serialized_blocks'] ?? '');
$assert('core/embed' === ($authoredHeightBlock['blockName'] ?? ''), 'authored-height Spotify iframe converts to core/embed');
$assert('spotify' === ($authoredHeightBlock['attrs']['providerNameSlug'] ?? ''), 'authored-height Spotify iframe records its provider slug');
$assert(
    str_contains((string) ($authoredHeightBlock['attrs']['className'] ?? ''), 'wp-embed-aspect-18-9')
    && str_contains((string) ($authoredHeightBlock['attrs']['className'] ?? ''), 'wp-has-aspect-ratio'),
    'a 1022x520 authored iframe carries the nearest native aspect-ratio classes (18-9), preserving the author\'s taller-than-default intent'
);
$assert(
    str_contains($authoredHeightMarkup, '<figure class="wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify block w-full border-0 wp-embed-aspect-18-9 wp-has-aspect-ratio">'),
    'the aspect-ratio classes land on the saved <figure>, which autoembed() never touches'
);
$assert(
    1 === preg_match('|^(\s*)(https?://[^\s<>"]+)(\s*)$|im', $authoredHeightMarkup, $matches)
    && 'https://open.spotify.com/artist/46aKqTxrSund2Ccj4oPRsq' === $matches[2],
    'the #1909 canonical newline URL shape is unaffected: WP_Embed::autoembed() still matches the bare URL line'
);

/**
 * The mechanism is generic, not Spotify-specific: a YouTube iframe authored
 * at an exact 16:9 (560x315, YouTube's own long-standing embed default)
 * gets the exact matching preset.
 */
$youtube = ( new HtmlTransformer() )->transform(
    '<main><iframe title="Demo" src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315"></iframe></main>'
)->toArray();
$youtubeBlock = $youtube['blocks'][0] ?? array();
$assert(
    'wp-embed-aspect-16-9 wp-has-aspect-ratio' === ($youtubeBlock['attrs']['className'] ?? ''),
    'an exact 16:9 YouTube iframe (560x315) resolves to the exact matching preset class'
);

/**
 * When the source expresses no explicit height at all, the provider default
 * must still win — the fix must not force a height that was never authored.
 */
$noAuthoredHeight = ( new HtmlTransformer() )->transform(
    '<main><iframe src="https://open.spotify.com/embed/track/4iV5W9uYEdYUVa79Axb7Rh"></iframe></main>'
)->toArray();
$noAuthoredHeightBlock = $noAuthoredHeight['blocks'][0] ?? array();
$assert(
    ! array_key_exists('className', $noAuthoredHeightBlock['attrs'] ?? array()),
    'a Spotify iframe with no authored width/height gets no aspect-ratio class; the provider default is left alone'
);

/**
 * Percentage geometry cannot anchor a real pixel ratio (it describes the
 * iframe relative to an unknown container), so it must be treated the same
 * as "no explicit height authored" rather than producing a nonsensical
 * ratio from two 100% values.
 */
$percentageDimensions = ( new HtmlTransformer() )->transform(
    '<main><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="100%" height="100%"></iframe></main>'
)->toArray();
$percentageDimensionsBlock = $percentageDimensions['blocks'][0] ?? array();
$assert(
    ! array_key_exists('className', $percentageDimensionsBlock['attrs'] ?? array()),
    'percentage-only iframe geometry does not fabricate an aspect-ratio class'
);

echo "Embed height preservation tests passed ({$assertions} assertions)\n";
