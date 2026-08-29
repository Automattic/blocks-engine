<?php
declare(strict_types=1);

/**
 * `width:min-content` on a core/button link plus Gutenberg's
 * `word-break:break-word` collapses the label to one character (issue #1386).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$cssOf = static function (array $out): string {
    $css = '';
    foreach ( $out['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= (string) ( $asset['content'] ?? '' );
        }
    }
    return $css;
};
$hasCollapsingWidth = static fn (string $css): bool => 1 === preg_match('/(?:^|[;{])(?:width|min-width|max-width):min-content(?:!important)?(?:[;}])/i', $css);

$direct = ( new HtmlTransformer() )->transform(
    '<style>.action-grid{display:grid;grid-template-columns:max-content}.action{display:grid;width:min-content;min-width:min-content;height:min-content;padding:8px 16px;background:#111;color:#fff}</style>'
    . '<main><div class="action-grid"><a class="action" href="/start">Schedule a consultation</a></div></main>'
)->toArray();
$directCss = $cssOf($direct);
$directMarkup = (string) ( $direct['serialized_blocks'] ?? '' );

$assert(str_contains($directMarkup, 'wp:buttons') && str_contains($directMarkup, 'Schedule a consultation'), '1: grid CTA becomes core/buttons with its complete label', $directMarkup);
$assert(
    ! $hasCollapsingWidth($directCss)
        && ! str_contains($directCss, 'wp-block-button__link){width:100%!important'),
    '2: direct grid CTA keeps auto content-viable width instead of min-content or stretch-fill',
    $directCss
);
$assert(
    (bool) preg_match('/wp-block-button__link[^{]*\{(?![^}]*\b(?:width|min-width|max-width)\s*:)[^}]*padding:8px 16px/', $directCss)
        && str_contains($directCss, 'display:grid')
        && str_contains($directCss, 'height:min-content')
        && str_contains($directCss, 'background:#111'),
    '3: inner link keeps grid display, block-size, and chrome without an inline width',
    $directCss
);

$descendant = ( new HtmlTransformer() )->transform(
    '<style>.panel .action{width:min-content;max-width:min-content;padding:8px 16px;background:#111;color:#fff}</style>'
    . '<main><div class="panel"><a class="action" href="/offer">View available appointments</a></div></main>'
)->toArray();
$descendantCss = $cssOf($descendant);
$descendantMarkup = (string) ( $descendant['serialized_blocks'] ?? '' );

$assert(str_contains($descendantMarkup, 'wp:button') && str_contains($descendantMarkup, 'View available appointments'), '4: descendant-selector CTA becomes core/button with its complete label', $descendantMarkup);
$assert(
    ! $hasCollapsingWidth($descendantCss)
        && ! str_contains($descendantCss, 'wp-block-button__link){width:100%!important'),
    '5: empty-geometry rewrite keeps auto content-viable width instead of min-content or stretch-fill',
    $descendantCss
);
$assert(
    str_contains($descendantCss, 'padding:8px 16px') && str_contains($descendantCss, 'background:#111'),
    '6: descendant paint still projects onto the link',
    $descendantCss
);

$preset = ( new HtmlTransformer() )->transform(
    '<style>.preset .button{display:flex;width:min-content;min-width:var(--btn-min-width);--btn-min-width:min-content;padding:8px 16px;background:#111;color:#fff}</style>'
    . '<main><div class="preset"><a class="button" href="/offer">START YOUR JOURNEY TODAY</a></div></main>'
)->toArray();
$presetCss = $cssOf($preset);
$presetMarkup = (string) ( $preset['serialized_blocks'] ?? '' );

$assert(str_contains($presetMarkup, 'wp:buttons') && str_contains($presetMarkup, 'START YOUR JOURNEY TODAY'), '7: preset descendant CTA becomes core/buttons with its complete label', $presetMarkup);
$assert(
    ! $hasCollapsingWidth($presetCss)
        && ! str_contains($presetCss, '--btn-min-width:min-content')
        && ! str_contains($presetCss, 'wp-block-button__link){width:100%!important'),
    '8: min-content custom width and stretch-fill stay off the projected button',
    $presetCss
);
$assert(
    str_contains($presetCss, 'padding:8px 16px') && str_contains($presetCss, 'background:#111'),
    '9: preset paint still projects onto the link',
    $presetCss
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "button link min-content tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "button link min-content tests: {$passes} passed" . PHP_EOL);
