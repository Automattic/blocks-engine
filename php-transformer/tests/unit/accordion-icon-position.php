<?php
declare(strict_types=1);

/**
 * Accordion toggle icon placement follows the source's resolved order.
 *
 * Plain-PHP test script — no PHPUnit. core/accordion and core/accordion-heading
 * state the icon side as `iconPosition`. A disclosure list whose toggle row is
 * a `row-reverse` flex row renders its icon before the label, so the import
 * must say `iconPosition: "left"` and save the icon ahead of the title. A
 * source that keeps the icon at the inline end stays on core's default.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

/** @return list<array<string, mixed>> */
$find = static function (array $blocks, string $name) use (&$find): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ( $block['blockName'] ?? null ) ) {
            $found[] = $block;
        }
        $found = array_merge($found, $find($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};

$icon = '<span class="row__icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 14 14"><path d="M0 7h14M7 0v14" stroke="currentColor"/></svg></span>';
$document = static fn (string $rowCss, bool $iconFirst): string =>
    '<html><head><style>.row{display:flex;' . $rowCss . 'align-items:center;padding:12px 0}</style></head><body><main><ul class="disclosures">'
    . implode('', array_map(static fn (int $n): string =>
        '<li class="disclosure-item"><h3><button type="button" class="row" aria-expanded="false" aria-controls="panel-' . $n . '">'
        . ( $iconFirst ? '' : '<span class="row__label">Question ' . $n . '</span>' )
        . $icon
        . ( $iconFirst ? '<span class="row__label">Question ' . $n . '</span>' : '' )
        . '</button></h3><div id="panel-' . $n . '" role="region" hidden><p>Answer ' . $n . '</p></div></li>', array(1, 2)))
    . '</ul></main></body></html>';

$cases = array(
    'row-reverse with the label first in DOM' => array( 'flex-direction:row-reverse;', false, 'left' ),
    'row with the icon first in DOM' => array( '', true, 'left' ),
    'row with order moving the icon first' => array( 'flex-direction:row;}.row__icon{order:-1;', false, 'left' ),
    'row with the label first in DOM' => array( '', false, null ),
    'row-reverse with the icon first in DOM' => array( 'flex-direction:row-reverse;', true, null ),
);

foreach ( $cases as $case => [ $rowCss, $iconFirst, $expected ] ) {
    $result = ( new HtmlTransformer() )->transform($document($rowCss, $iconFirst))->toArray();
    $accordions = $find($result['blocks'] ?? array(), 'core/accordion');
    $headings = $find($result['blocks'] ?? array(), 'core/accordion-heading');
    $serialized = (string) ( $result['serialized_blocks'] ?? '' );

    $assert(1 === count($accordions) && 2 === count($headings), $case . ': lowers to one core/accordion with two headings', $serialized);
    $assert($expected === ( $accordions[0]['attrs']['iconPosition'] ?? null ), $case . ': core/accordion iconPosition', json_encode($accordions[0]['attrs'] ?? null) ?: '');
    foreach ( $headings as $heading ) {
        $html = (string) ( $heading['innerHTML'] ?? '' );
        $assert($expected === ( $heading['attrs']['iconPosition'] ?? null ), $case . ': core/accordion-heading iconPosition', json_encode($heading['attrs'] ?? null) ?: '');
        $iconAt = strpos($html, 'wp-block-accordion-heading__toggle-icon');
        $titleAt = strpos($html, 'wp-block-accordion-heading__toggle-title');
        $assert(false !== $iconAt && false !== $titleAt && ( 'left' === $expected ) === ( $iconAt < $titleAt ), $case . ': the saved icon renders on the source side of the label', $html);
        $assert(str_contains($html, 'left' === $expected ? 'has-icon-left' : 'has-icon-right'), $case . ': the heading carries core\'s icon-side class', $html);
    }
}

if ( $failures > 0 ) {
    fwrite(STDERR, "Accordion icon position: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Accordion icon position passed: {$passes} assertions\n");
