<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

/**
 * A common page-builder pattern (repeaters, slideshows, "fill" media layers)
 * nests several CSS Grid containers, each sized only by the CSS Grid default
 * `stretch` alignment cascading down from one genuinely definite ancestor
 * size, with `position:absolute` media layers deep inside -- including a
 * deliberately zero-height plain wrapper `position:absolute` escapes.
 *
 * `AuthorStyleRuleProjector::isIntrinsicallySizedGridContainer()` used to
 * treat every container in that chain as unsized (since none of them declares
 * an absolute-length height itself) and collapse its `1fr` row track to
 * `min-content`, discarding the stretch behavior the whole subtree depended
 * on and collapsing the image to zero height. It must now recognize the
 * stretch-derived chain instead, and must not carry the collapse when no
 * ancestor is genuinely resolvable.
 */

$assertions = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? "\n" . $detail : '' ) . "\n");
        exit(1);
    }
};

$css = static function (array $result): string {
    $out = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ($asset['kind'] ?? null) ) {
            $out .= (string) ($asset['content'] ?? '');
        }
    }
    return $out;
};

$stretchChainMarkup = static function (): string {
    return '<div id="viewport"><div class="track"><div class="slide"><div class="media-mount">'
        . '<div class="carrier"><img src="photo.jpg" alt="Photo" style="object-fit: cover; object-position: 51% 42%; width: 100%;"></div>'
        . '</div></div></div></div>';
};

// A genuinely definite size (a literal pixel height) at the top of the chain;
// every container below it is sized only by CSS Grid's default stretch
// alignment; `.media-mount`'s own child (the source of `.carrier`'s box) is a
// plain, non-grid `position:absolute` layer with no declared box of its own.
$resolved = ( new HtmlTransformer() )->transform(
    '<style>'
    . '#viewport{width:538px;height:402px;display:grid;grid-template-rows:1fr;grid-template-columns:1fr}'
    . '.track{grid-area:1/1/2/2;display:grid;grid-template-rows:1fr;grid-template-columns:1fr}'
    . '.slide{min-height:100%;grid-area:1/1/2/2;position:relative}'
    . '.media-mount{position:absolute;inset:0}'
    . '.carrier{width:100%;height:100%;position:absolute}'
    . '.carrier img{width:100%;height:100%;object-fit:cover;display:block}'
    . '</style><main>' . $stretchChainMarkup() . '</main>'
)->toArray();
$resolvedCss = $css($resolved);
$resolvedBlocks = (string) ($resolved['serialized_blocks'] ?? '');

$assert(
    str_contains($resolvedCss, '#viewport{width:538px;height:402px;display:grid;grid-template-rows:1fr;grid-template-columns:1fr}'),
    'the definite-size grid root keeps its own fractional row track',
    $resolvedCss
);
$assert(
    str_contains($resolvedCss, '.track{grid-area:1/1/2/2;display:grid;grid-template-rows:1fr;grid-template-columns:1fr}'),
    'a stretch-sized intermediate grid container (no absolute-length height of its own) keeps its fractional row track instead of collapsing to min-content',
    $resolvedCss
);
$assert(
    ! str_contains($resolvedCss, 'grid-template-rows:min-content'),
    'no fractional row track in the resolvable stretch chain is collapsed',
    $resolvedCss
);
$assert(
    str_contains($resolvedBlocks, '<!-- wp:image') && 1 === substr_count($resolvedBlocks, '<!-- wp:image'),
    'the deeply nested media layer still converts to a native core/image block'
);
$imageFallbacks = array_filter(
    $resolved['fallbacks'] ?? array(),
    static fn (array $fallback): bool => 'img' === ($fallback['tag'] ?? '') || 'picture' === ($fallback['tag'] ?? '')
);
$assert(
    ! str_contains($resolvedBlocks, '<!-- wp:html') && array() === $imageFallbacks,
    'the CSS-sized image subtree produces zero fallback blocks and zero core/html'
);
$assert('pass' === ($resolved['source_reports']['wp_block_validity']['status'] ?? ''), 'the resolved subtree remains Gutenberg-valid');

// Same nested-stretch structure, but with no definite size anywhere in the
// chain: every `1fr` row genuinely is unresolvable, and must still collapse
// exactly as before. This proves the fix recognizes the stretch chain rather
// than simply disabling the collapse.
$unresolved = ( new HtmlTransformer() )->transform(
    '<style>'
    . '.viewport{display:grid;grid-template-rows:1fr;grid-template-columns:1fr}'
    . '.track{grid-area:1/1/2/2;display:grid;grid-template-rows:1fr;grid-template-columns:1fr}'
    . '.slide{min-height:100%;grid-area:1/1/2/2;position:relative}'
    . '.media-mount{position:absolute;inset:0}'
    . '.carrier{width:100%;height:100%;position:absolute}'
    . '.carrier img{width:100%;height:100%;object-fit:cover;display:block}'
    . '</style><main><div class="viewport"><div class="track"><div class="slide"><div class="media-mount">'
    . '<div class="carrier"><img src="photo.jpg" alt="Photo"></div>'
    . '</div></div></div></div></main>'
)->toArray();
$unresolvedCss = $css($unresolved);
$assert(
    str_contains($unresolvedCss, '.viewport{display:grid;grid-template-columns:1fr;grid-template-rows:min-content}')
        && str_contains($unresolvedCss, '.track{grid-area:1/1/2/2;display:grid;grid-template-columns:1fr;grid-template-rows:min-content}'),
    'a stretch chain with no definite size anywhere still collapses its fractional row tracks, unchanged from prior behavior',
    $unresolvedCss
);

// A `--token:unset` "no override" sentinel gating `display` behind
// `var(--token, var(--fallback))` -- a common design-system idiom -- must not
// hide a real grid container from the same stretch-chain recognition. Per the
// CSS custom-properties substitution algorithm, a CSS-wide keyword arising
// from substitution (rather than authored directly) does not carry its
// special meaning, so the declaration falls back to `--fallback`, here also
// declared on the very same rule.
$sentinel = ( new HtmlTransformer() )->transform(
    '<style>'
    . '#viewport{width:538px;height:402px;--l_display:unset;--container-display:grid;display:var(--l_display,var(--container-display));grid-template-rows:1fr;grid-template-columns:1fr}'
    . '.track{grid-area:1/1/2/2;--l_display:unset;--container-display:grid;display:var(--l_display,var(--container-display));grid-template-rows:1fr;grid-template-columns:1fr}'
    . '.slide{min-height:100%;grid-area:1/1/2/2;position:relative}'
    . '.media-mount{position:absolute;inset:0}'
    . '.carrier{width:100%;height:100%;position:absolute}'
    . '</style><main>' . $stretchChainMarkup() . '</main>'
)->toArray();
$sentinelCss = $css($sentinel);
$assert(
    ! str_contains($sentinelCss, 'grid-template-rows:min-content'),
    'a var()-gated display carrying a `--token:unset` "no override" sentinel still resolves to its declared fallback (grid), and the stretch chain is recognized',
    $sentinelCss
);

// An element that opts out of the default stretch alignment must not be
// rescued: it genuinely does not receive a size from its parent's track.
$optedOut = ( new HtmlTransformer() )->transform(
    '<style>'
    . '#viewport{width:538px;height:402px;display:grid;grid-template-rows:1fr;grid-template-columns:1fr}'
    . '.track{grid-area:1/1/2/2;align-self:start;display:grid;grid-template-rows:1fr;grid-template-columns:1fr}'
    . '</style><main><div id="viewport"><div class="track"><p>Content</p></div></div></main>'
)->toArray();
$optedOutCss = $css($optedOut);
$assert(
    str_contains($optedOutCss, '.track{grid-area:1/1/2/2;align-self:start;display:grid;grid-template-columns:1fr;grid-template-rows:min-content}'),
    'a grid item with a non-stretch align-self is not treated as stretch-sized',
    $optedOutCss
);

// The per-instance inline `object-position` on the source <img> -- a common
// carrier for an authored focal point -- has no core/image attribute to ride
// on and must survive as a generated rule targeting the <img> itself.
$objectPositionCss = $css($resolved);
$assert(
    (bool) preg_match('/\.[a-z0-9-]+>img\{object-position:51% 42%\}/', $objectPositionCss),
    'an authored object-position focal point is carried onto the generated <img> via a targeted rule',
    $objectPositionCss
);
$assert(
    str_contains($resolvedBlocks, 'style="object-fit:cover;width:100%"'),
    'the generated <img> tag itself carries no literal object-position (it rides the generated rule instead), matching how object-fit/width already serialize'
);

// The CSS initial value (`50% 50%`/`center`) is not carried forward via the
// dedicated carrier this fix adds: doing so would only add generated-CSS
// noise for the common, unauthored case. (Matches the source structure that
// actually motivated this fix: an <img> reached through a <picture> wrapper,
// same as `convertPictureElement()` routes a plain, unselected-source
// <picture><img></picture> pair.)
$defaultObjectPosition = ( new HtmlTransformer() )->transform(
    '<main><picture><img src="photo.jpg" alt="Photo" style="object-position: 50% 50%; width: 100%;"></picture></main>'
)->toArray();
$assert(
    ! preg_match('/>img\{object-position:/', $css($defaultObjectPosition)),
    'the CSS initial object-position value is not carried into the targeted <img> rule',
    $css($defaultObjectPosition)
);

fwrite(STDOUT, 'Stretch-derived image geometry unit tests: ' . $assertions . " passed\n");
