<?php
declare(strict_types=1);

/**
 * A whole-card link is propagated into each text block as an inline anchor
 * that wraps all of that block's source children. When the source element is
 * a flex row (an inline-flex "Listen now" pill: icon + label with a gap), the
 * anchor becomes the row's only flex item and the icon stacks above the label.
 * The propagated anchor is marked and made layout-transparent.
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
$supportCss = static fn (array $result): string => implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_values(array_filter(
        is_array($result['assets'] ?? null) ? $result['assets'] : array(),
        static fn (array $asset): bool => 'engine-support' === ($asset['source'] ?? '')
    ))
));
$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();
$class = HtmlCompilation::PROPAGATED_LINK_CARRIER_CLASS;
$rule = ':root :where(.' . $class . ')>a:only-child{display:contents}';

$card = $transform(
    '<style>.card{display:block;position:relative}.pill{display:inline-flex;align-items:center;gap:.5rem}</style>'
    . '<a href="https://example.com/" class="card"><img src="card.png" alt=""><div class="body"><h3>Show</h3><p>Description.</p>'
    . '<span class="pill"><svg width="14" height="14" viewBox="0 0 24 24"><path d="M5 3l14 9-14 9V3z"/></svg><span>Listen Now</span></span></div></a>'
);
$markup = (string) ($card['serialized_blocks'] ?? '');
$assert(
    (bool) preg_match('/<p class="[^"]*\bpill\b[^"]*\b' . preg_quote($class, '/') . '\b[^"]*"><a href="https:\/\/example\.com\/"/', $markup),
    'the flex pill paragraph that received the propagated card link is marked',
    $markup
);
$assert(str_contains($supportCss($card), $rule), 'the propagated anchor is made layout-transparent', $supportCss($card));

$plain = $transform('<p>Just text with <a href="/x">an authored link</a>.</p>');
$assert(! str_contains((string) ($plain['serialized_blocks'] ?? ''), $class), 'authored links are never marked');
$assert(! str_contains($supportCss($plain), $rule), 'no propagated link means no rule');

if ( $failures > 0 ) {
    fwrite(STDERR, "propagated link layout transparency: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "propagated link layout transparency: {$passes} passed\n";
