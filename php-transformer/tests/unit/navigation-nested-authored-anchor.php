<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' === $detail ? '' : ' - ' . $detail) . PHP_EOL);
};

$fixture = file_get_contents(dirname(__DIR__) . '/fixtures/rma-nested-mobile-navigation.html');
if ( false === $fixture ) {
    fwrite(STDERR, "FAIL: unable to load nested navigation fixture\n");
    exit(1);
}

$result = ( new HtmlTransformer() )->transform($fixture)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$parity = $result['source_reports']['semantic_parity'] ?? array();
$metrics = $result['source_reports']['editability_report']['metrics'] ?? array();

$assert(1 === substr_count($markup, '<!-- wp:navigation '), 'RMA-shaped nested mobile menu emits one core navigation block', $markup);
$assert(str_contains($markup, '"label":"Programs"') && str_contains($markup, '"url":"/programs"'), 'nested authored anchor retains its URL and label', $markup);
$assert(str_contains($markup, 'programs-link') && str_contains($markup, 'authored-item'), 'nested authored anchor retains link and item presentation classes', $markup);
$navigationInner = preg_match('/<!-- wp:navigation .*?<!-- \/wp:navigation -->/s', $markup, $navigationRegion) ? $navigationRegion[0] : '';
$assert('' !== $navigationInner && ! str_contains($navigationInner, '<!-- wp:group') && ! str_contains($navigationInner, '<!-- wp:paragraph') && ! str_contains($markup, 'authored-label'), 'nested anchor carriers do not remain as anonymous group and paragraph blocks', $markup);
// The carrier's own class is still carried onto the native item, because source
// rules commonly style a menu through the wrappers core replaces.
$assert(str_contains($navigationInner, 'anchor-carrier'), 'a replaced carrier keeps its class on the native item so source rules still match', $markup);
$assert(! str_contains($markup, 'item-support'), 'empty item support does not prevent native navigation conversion', $markup);
$assert(! str_contains($markup, 'scroll-support'), 'hidden scroll support does not prevent native navigation conversion', $markup);
$assert('pass' === ($parity['status'] ?? null), 'nested authored menu preserves semantic item-count parity', json_encode($parity));
$assert(1 === count($parity['navigation_menus']['source'] ?? array()), 'outer navigation carrier is not inventoried as an empty menu', json_encode($parity));
$assert(20 >= ($metrics['max_nesting_depth'] ?? PHP_INT_MAX), 'nested authored menu remains within the required maximum nesting depth', json_encode($metrics));

$card = ( new HtmlTransformer() )->transform(
    '<div class="linked-card"><div class="card-body"><a href="/programs"><p>Programs</p></a><p>Individual lesson plans.</p></div></div>'
)->toArray();
$cardMarkup = (string) ($card['serialized_blocks'] ?? '');
$assert(! str_contains($cardMarkup, '<!-- wp:navigation '), 'unrelated nested linked cards do not become navigation', $cardMarkup);
$assert(str_contains($cardMarkup, 'Individual lesson plans.'), 'unrelated nested linked card content remains intact', $cardMarkup);

if ( 0 < $failures ) {
    fwrite(STDERR, "Nested authored navigation contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Nested authored navigation contract passed: {$passes} assertions\n";
