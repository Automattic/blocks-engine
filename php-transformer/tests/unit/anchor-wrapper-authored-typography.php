<?php
declare(strict_types=1);

/**
 * Authored typography on content nested inside a wrapping <a> must still
 * compute after the link is pushed onto inline layout carriers.
 *
 * A brand lockup often authors `<a href>` around wrapper `<div>`s that wrap
 * styled `<span>`s. Conversion flattens the anchor into a group, emits each
 * span as a `p.blocks-engine-inline-layout-carrier` leaf, and rebuilds the
 * href as a child `<a>` around that leaf. Selector projection used to target
 * `p.carrier > .leaf` — a child combinator the propagated anchor severs —
 * so font-family, font-size, and letter-spacing on the nested spans stopped
 * applying. The projector must also address `p.carrier > a > .leaf`.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

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

$css = ':root{--font-heading:"Anton",sans-serif}'
    . '.flex{display:flex}.flex-col{flex-direction:column}.items-center{align-items:center}'
    . '.gap-2\\.5{gap:.625rem}.leading-none{line-height:1}'
    . '.font-heading{font-family:var(--font-heading)}.text-sm{font-size:.875rem}'
    . '.tracking-wide{letter-spacing:.025em}'
    . '.text-\\[7px\\]{font-size:7px}.tracking-\\[0\\.25em\\]{letter-spacing:.25em}';

$lockup = $transform(
    '<style>' . $css . '</style>'
    . '<header><a href="/">'
    . '<div class="flex items-center gap-2.5">'
    . '<div class="flex flex-col leading-none">'
    . '<span class="font-heading text-sm tracking-wide">DISTRICT 21</span>'
    . '<span class="text-[7px] tracking-[0.25em]">FRIED CHICKEN · 100% HALAL</span>'
    . '</div></div></a></header>'
);
$markup = (string) ( $lockup['serialized_blocks'] ?? '' );
$projected = $cssFor($lockup);

$assert(
    str_contains($markup, 'class="font-heading text-sm tracking-wide"')
        && str_contains($markup, '>DISTRICT 21</span>'),
    'the heading leaf keeps its authored class hook inside the reconstructed link',
    $markup
);
$assert(
    str_contains($markup, 'class="text-[7px] tracking-[0.25em]"')
        && str_contains($markup, 'FRIED CHICKEN · 100% HALAL</span>'),
    'the tagline leaf keeps its authored class hook inside the reconstructed link',
    $markup
);
$assert(
    str_contains($markup, '<a href="/"><span class="font-heading text-sm tracking-wide">DISTRICT 21</span></a>')
        && str_contains($markup, '<a href="/"><span class="text-[7px] tracking-[0.25em]">FRIED CHICKEN · 100% HALAL</span></a>'),
    'each leaf is wrapped by the propagated anchor, not flattened onto it',
    $markup
);
$assert(
    str_contains($projected, 'p.blocks-engine-inline-layout-carrier > a > .font-heading,p.blocks-engine-inline-layout-carrier > .font-heading{font-family:var(--font-heading)}')
        && str_contains($projected, 'p.blocks-engine-inline-layout-carrier > a > .text-sm,p.blocks-engine-inline-layout-carrier > .text-sm{font-size:.875rem}')
        && str_contains($projected, 'p.blocks-engine-inline-layout-carrier > a > .tracking-wide,p.blocks-engine-inline-layout-carrier > .tracking-wide{letter-spacing:.025em}'),
    'heading typography still addresses the leaf through the propagated anchor',
    $projected
);
$assert(
    str_contains($projected, 'p.blocks-engine-inline-layout-carrier > a > .text-\\[7px\\],p.blocks-engine-inline-layout-carrier > .text-\\[7px\\]{font-size:7px}')
        && str_contains($projected, 'p.blocks-engine-inline-layout-carrier > a > .tracking-\\[0\\.25em\\],p.blocks-engine-inline-layout-carrier > .tracking-\\[0\\.25em\\]{letter-spacing:.25em}'),
    'tagline typography still addresses the leaf through the propagated anchor',
    $projected
);
$assert(
    'pass' === ( ( new BlockValidityValidator() )->validateBlocks($lockup['blocks'] ?? array())['status'] ?? '' ),
    'the reconstructed lockup stays Gutenberg-valid',
    $markup
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Anchor wrapper authored typography: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Anchor wrapper authored typography passed: {$passes} assertions\n");
