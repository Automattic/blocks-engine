<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    global $failures, $passes;
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$menu = '<ul><li><a href="/about">About</a></li><li><a href="/contact">Contact</a></li></ul>';
$result = ( new HtmlTransformer() )->transform(
    '<nav aria-label="Primary">' . $menu
    . '<span style="position:absolute;overflow:hidden;clip:rect(0 0 0 0)">Primary navigation</span><div id="navigation-runtime"></div></nav>',
    array( 'runtime_dom_selectors' => array( '#navigation-runtime' ) )
)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');

$assert(1 === substr_count($markup, '<!-- wp:navigation '), 'a recognized list menu promotes to one core/navigation block', $markup);
$assert(str_contains($markup, '"url":"/about"') && str_contains($markup, '"url":"/contact"'), 'promoted navigation retains every list destination', $markup);
$assert('pass' === ($result['source_reports']['semantic_parity']['status'] ?? ''), 'inert support children retain semantic navigation parity', json_encode($result['source_reports']['semantic_parity'] ?? array()));
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'promoted navigation remains Gutenberg-valid', json_encode($result['source_reports']['wp_block_validity'] ?? array()));

$visible = ( new HtmlTransformer() )->transform(
    '<nav aria-label="Primary">' . $menu . '<span>Menu updated daily</span></nav>'
)->toArray();
$visibleMarkup = (string) ($visible['serialized_blocks'] ?? '');
$assert(! str_contains($visibleMarkup, '<!-- wp:navigation '), 'visible non-menu siblings still reject navigation promotion', $visibleMarkup);

$visibleAriaHidden = ( new HtmlTransformer() )->transform(
    '<nav aria-label="Primary">' . $menu . '<span aria-hidden="true">Menu updated daily</span></nav>'
)->toArray();
$visibleAriaHiddenMarkup = (string) ($visibleAriaHidden['serialized_blocks'] ?? '');
$assert(! str_contains($visibleAriaHiddenMarkup, '<!-- wp:navigation '), 'visible aria-hidden siblings still reject navigation promotion', $visibleAriaHiddenMarkup);

$visibleInert = ( new HtmlTransformer() )->transform(
    '<nav aria-label="Primary">' . $menu . '<span inert>Menu updated daily</span></nav>'
)->toArray();
$visibleInertMarkup = (string) ($visibleInert['serialized_blocks'] ?? '');
$assert(! str_contains($visibleInertMarkup, '<!-- wp:navigation '), 'visible inert siblings still reject navigation promotion', $visibleInertMarkup);

$presentationHidden = ( new HtmlTransformer() )->transform(
    '<nav aria-label="Primary">' . $menu . '<span aria-hidden="true" style="display:none">Primary navigation</span><span inert style="visibility:hidden">Menu support</span></nav>'
)->toArray();
$presentationHiddenMarkup = (string) ($presentationHidden['serialized_blocks'] ?? '');
$assert(1 === substr_count($presentationHiddenMarkup, '<!-- wp:navigation '), 'presentation-hidden aria and inert support children promote navigation', $presentationHiddenMarkup);

if ( $failures > 0 ) {
    fwrite(STDERR, "Navigation inert support children contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Navigation inert support children contract passed: {$passes} assertions\n";
