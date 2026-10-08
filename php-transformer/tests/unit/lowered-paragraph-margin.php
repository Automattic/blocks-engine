<?php
declare(strict_types=1);

/**
 * A text-only source element that renders no user-agent block margins (for
 * example `<div class="mt-12 pt-6 text-sm">© …</div>`) is lowered to
 * core/paragraph. The emitted `<p>` would gain the UA 1em paragraph margins,
 * making the box taller than the source whenever author CSS is silent on
 * that side (a real import rendered a footer 14px taller on every page).
 *
 * The lowered paragraph is marked, and a before-author engine-support rule
 * zeroes its block margins, so authored margin utilities still win. Real
 * `<p>` sources keep their UA margins because those are part of the source.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};
$beforeAuthorCss = static fn (array $result): string => implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_values(array_filter(
        is_array($result['assets'] ?? null) ? $result['assets'] : array(),
        static fn (array $asset): bool => 'engine-support' === ($asset['source'] ?? '')
            && 'before-author' === ($asset['stylesheet_placement'] ?? '')
    ))
));
$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();
$class = HtmlCompilation::LOWERED_PARAGRAPH_CLASS;
$rule = ':root :where(p.' . $class . '){margin-block-start:0;margin-block-end:0}';

$lowered = $transform('<style>.mt-12{margin-top:3rem}.pt-6{padding-top:1.5rem}</style><footer><div class="mt-12 pt-6">© 2026 <span>Example.</span></div></footer>');
$markup = (string) ($lowered['serialized_blocks'] ?? '');
$assert(
    (bool) preg_match('/<p class="[^"]*\bmt-12\b[^"]*\b' . preg_quote($class, '/') . '\b/', $markup),
    'a text-only div lowered to core/paragraph carries the lowered-paragraph marker next to its source classes',
    $markup
);
$assert(str_contains($beforeAuthorCss($lowered), $rule), 'the zero-margin rule is emitted before author CSS', $beforeAuthorCss($lowered));

$real = $transform('<style>.lead{font-size:1.25rem}</style><div class="wrap"><p class="lead">Real paragraph.</p><h2>Heading</h2></div>');
$realMarkup = (string) ($real['serialized_blocks'] ?? '');
$assert(! str_contains($realMarkup, $class), 'a real <p> source keeps its UA margins and is never marked', $realMarkup);
$assert(! str_contains($beforeAuthorCss($real), $class), 'no lowered paragraph means no rule is emitted', $beforeAuthorCss($real));

$quote = $transform('<blockquote>Quoted text only.</blockquote>');
$quoteMarkup = (string) ($quote['serialized_blocks'] ?? '');
$assert(! str_contains($quoteMarkup, $class), 'sources with their own UA block margins are never marked', $quoteMarkup);

if ( $failures > 0 ) {
    fwrite(STDERR, "lowered paragraph margin: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "lowered paragraph margin: {$passes} passed\n";
