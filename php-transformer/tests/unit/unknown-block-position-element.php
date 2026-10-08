<?php
declare(strict_types=1);

// Issue #2181: an unknown or custom element in block position (`<bdt>`,
// `<x-panel>` wrapping headings, paragraphs and lists) reached the terminal
// unsupported-element recorder, which dropped its whole subtree and recorded
// `html_unsupported_element`. Such an element has no rendering of its own, so
// it now lowers the way a generic `div` does: its children convert to native
// blocks in place, it is kept as a Group carrying its class/id when it wraps
// several blocks or carries presentation, and it vanishes when empty and inert.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$transform = static fn (string $html, string $css = ''): array =>
    ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();

/** @param array<int, array<string, mixed>> $blocks @return array<int, array<string, mixed>> */
$flatten = static function (array $blocks) use (&$flatten): array {
    $flat = array();
    foreach ( $blocks as $block ) {
        $flat[] = $block;
        $flat = array_merge($flat, $flatten(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array()));
    }
    return $flat;
};

$blocksNamed = static fn (array $result, string $name): array => array_values(array_filter(
    $flatten(is_array($result['blocks'] ?? null) ? $result['blocks'] : array()),
    static fn (array $block): bool => $name === ($block['blockName'] ?? '')
));

$unsupported = static fn (array $result): array => array_values(array_filter(
    is_array($result['fallbacks'] ?? null) ? $result['fallbacks'] : array(),
    static fn (array $fallback): bool => 'html_unsupported_element' === ($fallback['diagnostic_code'] ?? '')
));

$text = static fn (string $html): string => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

$html = <<<'HTML'
<main>
  <h1>Notice</h1>
  <bdt class="policy-body" id="policy">
    <h2>What we collect</h2>
    <p>We collect the details you give us.</p>
    <ul><li>Contact details</li><li>Usage data</li></ul>
    <x-panel class="panel" data-kind="aside">
      <h3>How long we keep it</h3>
      <p>Only as long as we need it.</p>
      <ol><li>Account records</li><li>Backups</li></ol>
    </x-panel>
    <bdt class="block-component"></bdt>
    <h2>Do we use cookies?</h2>
    <p>We may use cookies to store information.</p>
    <bdt>Contact us at <strong>the address below</strong>.</bdt>
  </bdt>
</main>
HTML;
$css = '.policy-body { padding: 12px; } .panel { border-left: 2px solid rgb(0, 0, 0); }';

$result = $transform($html, $css);
$serialized = (string) ($result['serialized_blocks'] ?? '');
$outputText = $text($serialized);

$assert(array() === $unsupported($result), 'no html_unsupported_element fallback is recorded; got: ' . json_encode(array_column($unsupported($result), 'tag')));
foreach ( array(
    'What we collect', 'We collect the details you give us.', 'Contact details', 'Usage data',
    'How long we keep it', 'Only as long as we need it.', 'Account records', 'Backups',
    'Do we use cookies?', 'We may use cookies to store information.',
    'Contact us at the address below.',
) as $expected ) {
    $assert(str_contains($outputText, $expected), "the text \"{$expected}\" survives; got: {$outputText}");
}

$headings = array_map(static fn (array $block): string => $text((string) ($block['attrs']['content'] ?? $block['innerHTML'] ?? '')), $blocksNamed($result, 'core/heading'));
$assert(in_array('What we collect', $headings, true), 'the heading inside <bdt> becomes a native core/heading');
$assert(in_array('How long we keep it', $headings, true), 'the heading inside <x-panel> becomes a native core/heading');
$assert(2 <= count($blocksNamed($result, 'core/list')), 'both lists become native core/list blocks');
$paragraphTexts = array_map(static fn (array $block): string => $text((string) ($block['attrs']['content'] ?? $block['innerHTML'] ?? '')), $blocksNamed($result, 'core/paragraph'));
$assert(in_array('Contact us at the address below.', $paragraphTexts, true), 'a phrasing-only unknown element in block position is one paragraph, not split per inline child');

// The wrappers become core/group blocks that keep the class and id author CSS targets.
$groups = $blocksNamed($result, 'core/group');
$groupWith = static fn (string $class): array => array_values(array_filter($groups, static fn (array $block): bool => in_array($class, preg_split('/\s+/', (string) ($block['attrs']['className'] ?? '')) ?: array(), true)));
$policyGroup = $groupWith('policy-body')[0] ?? array();
$assert(array() !== $policyGroup, 'the <bdt> wrapper becomes a core/group that keeps its class');
$assert('policy' === ($policyGroup['attrs']['anchor'] ?? null), 'the <bdt> wrapper group keeps its id as the anchor');
$assert(array() !== $groupWith('panel'), 'the <x-panel> wrapper becomes a core/group that keeps its class');
$assert(! str_contains($serialized, '<bdt') && ! str_contains($serialized, '<x-panel'), 'no unknown tag is emitted into block markup');
$nonCore = array_values(array_filter(
    array_column($flatten($result['blocks'] ?? array()), 'blockName'),
    static fn (mixed $name): bool => ! str_starts_with((string) $name, 'core/')
));
$assert(array() === $nonCore, 'the repeated heading/paragraph sections stay native blocks, not a generated static block; got: ' . json_encode($nonCore));

$validity = ( new BlockValidityValidator() )->validateBlocks($result['blocks'] ?? array());
$assert('pass' === ($validity['status'] ?? ''), 'the converted blocks are Gutenberg-valid');

// An empty unknown element with no painted box produces nothing: no block and
// no fallback.
$empty = $transform('<main><p>Before</p><bdt class="block-component"></bdt><bdt></bdt><p>After</p></main>');
$assert(array() === $unsupported($empty), 'an empty unknown element records no fallback');
$assert(2 === count($flatten($empty['blocks'] ?? array())) - count($blocksNamed($empty, 'core/group')), 'an empty unknown element produces no block; got: ' . (string) ($empty['serialized_blocks'] ?? ''));
$assert(! str_contains((string) ($empty['serialized_blocks'] ?? ''), 'block-component'), 'the empty unknown element leaves no wrapper behind');

// A host that carries runtime ownership keeps the existing preservation path.
$runtime = $transform('<main><x-widget onclick="toggle()"><p>Light DOM</p></x-widget></main>');
$assert(1 === count($unsupported($runtime)), 'a custom element with its own event handler is still reported, not silently flattened');

if ( $failures > 0 ) {
    fwrite(STDERR, "{$failures} failure(s), {$passes} pass(es)\n");
    exit(1);
}

echo "unknown-block-position-element: {$passes} passed\n";
