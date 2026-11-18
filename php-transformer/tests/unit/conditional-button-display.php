<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

// A flattened header button the author hides from tablets up (Tailwind
// `md:hidden`) rests at the transferred inline display. That resting display
// must not be the !important one, or the projected `display:none` never wins
// and the menu label stays painted (and counted as text) on desktop.
$html = '<style>.bar{display:flex;justify-content:space-between;gap:12px}.menu{padding:4px 8px;border:1px solid #333}'
    . '@media (min-width:768px){.md-hidden{display:none}}</style>'
    . '<header><div class="bar"><a class="logo" href="/">Apex</a><button type="button" class="menu md-hidden" aria-label="Menu">Menu</button></div></header><main><p>Body</p></main>';
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = stripslashes((string) json_encode($result['assets'] ?? array()));
$modifier = 'blocks-engine-layout-child-button-conditional-display';

$assert(1 === preg_match('/wp:button \{"className":"[^"]*blocks-engine-layout-child-button[^"]*' . $modifier . '/', $markup), 'the flattened menu button records its conditional display');
$assert(1 === preg_match('/@media \(min-width:768px\)\{[^@]*wp-block-button__link[^{}]*\{display:none\}/', $css), 'the tablet-up display:none is projected onto the button link');
preg_match_all('/:where\(([^{}]*)\)>\.wp-block-button__link\{[^}]*display:[a-z-]+!important[^}]*\}/', $css, $important);
$arms = array_merge(...array_map(static fn (string $selector): array => explode(',', $selector), $important[1]));
$assert(array() !== $arms && array() === array_filter($arms, static fn (string $arm): bool => str_contains($arm, 'blocks-engine-layout-child-button') && ! str_contains($arm, ':not(.' . $modifier . ')')), 'every important transferred display spares the conditional button');
$assert(str_contains($css, ':where(.blocks-engine-layout-child-button.' . $modifier . ')>.wp-block-button__link.wp-block-button__link{display:inline;'), 'it rests inline through a default the author rule outranks');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'the header stays editor-valid');

// A display the author never switches keeps the shared !important reset.
$resting = (new HtmlTransformer())->transform(str_replace('@media (min-width:768px){.md-hidden{display:none}}', '', $html), array())->toArray();
$restingCss = stripslashes((string) json_encode($resting['assets'] ?? array()));
$assert(! str_contains((string) $resting['serialized_blocks'], $modifier), 'an unconditional display is not conditional');
$assert(! str_contains($restingCss, $modifier), 'without a conditional button the support CSS is unchanged');
fwrite(STDOUT, "Conditional button display tests passed\n");
