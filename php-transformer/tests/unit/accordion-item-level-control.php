<?php
declare(strict_types=1);

/**
 * Unit tests for accordion items whose own element is the disclosure control.
 *
 * Plain-PHP test script — no PHPUnit. A captured click-to-expand list where
 * the whole row is clickable marks the item element with `aria-controls` /
 * `aria-expanded`, naming the answer panel the item contains. The item is a
 * disclosure item: its title is the heading inside it, its panel is the
 * controlled element, and the item's `aria-expanded` decides openByDefault.
 *
 * @see https://github.com/Automattic/blocks-engine/issues/3049
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
$collect = static function (array $blocks, string $name) use (&$collect): array {
    $found = array();
    foreach ( $blocks as $block ) {
        if ( $name === ( $block['blockName'] ?? null ) ) {
            $found[] = $block;
        }
        $found = array_merge($found, $collect($block['innerBlocks'] ?? array(), $name));
    }
    return $found;
};

$check = static function (string $case, string $html, array $expectedOpen, ?array $panelClasses = null) use ($assert, $collect): void {
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $blocks = $result['blocks'] ?? array();
    $serialized = (string) ( $result['serialized_blocks'] ?? '' );

    $accordions = $collect($blocks, 'core/accordion');
    $assert(1 === count($accordions), $case . ': converts to one core/accordion', $serialized);
    $assert(array() === $collect($blocks, 'core/html'), $case . ': produces no core/html', $serialized);
    if ( 1 !== count($accordions) ) {
        return;
    }

    $items = array_values(array_filter($accordions[0]['innerBlocks'] ?? array(), static fn (array $b): bool => 'core/accordion-item' === ( $b['blockName'] ?? null )));
    $assert(count($expectedOpen) === count($items), $case . ': accordion holds one core/accordion-item per row', $serialized);

    foreach ( $items as $index => $item ) {
        $headings = $collect(array( $item ), 'core/accordion-heading');
        $panels = $collect(array( $item ), 'core/accordion-panel');
        $assert(1 === count($headings) && str_contains((string) ( $headings[0]['attrs']['title'] ?? '' ), 'Question ' . $index . '?'), $case . ': item ' . $index . ' heading carries the question', $serialized);
        $assert(1 === count($panels) && str_contains(serialize($panels[0]), 'Answer ' . $index . '.'), $case . ': item ' . $index . ' answer sits inside core/accordion-panel', $serialized);
        if ( null !== $panelClasses && 1 === count($panels) ) {
            // Native core/accordion owns the open/closed state, so the saved
            // panel presents its recorded open-state classes; a closed-only
            // class such as `hidden` would keep it invisible when opened.
            $panelHtml = (string) ( $panels[0]['innerHTML'] ?? '' ) . implode('', array_filter($panels[0]['innerContent'] ?? array(), 'is_string'));
            $panelTag = preg_match('/<div[^>]*wp-block-accordion-panel[^>]*>/', $panelHtml, $pm) ? $pm[0] : '';
            $panelClassList = preg_match('/class="([^"]*)"/', $panelTag, $cm) ? preg_split('/\s+/', trim($cm[1])) : array();
            $savedClasses = array_merge($panelClassList ?: array(), preg_split('/\s+/', trim((string) ( $panels[0]['attrs']['className'] ?? '' ))) ?: array());
            foreach ( $panelClasses['present'] as $class ) {
                $assert(in_array($class, $savedClasses, true), $case . ': item ' . $index . ' panel keeps class ' . $class, $panelTag . ' ' . json_encode($panels[0]['attrs'] ?? array()));
            }
            foreach ( $panelClasses['absent'] as $class ) {
                $assert(! in_array($class, $savedClasses, true), $case . ': item ' . $index . ' panel drops closed-state class ' . $class, $panelTag . ' ' . json_encode($panels[0]['attrs'] ?? array()));
            }
            $panelShell = json_encode($panels[0]['attrs'] ?? array()) . $panelTag;
            $assert(! str_contains($panelShell, 'data-blocks-engine-capture-disclosure-'), $case . ': item ' . $index . ' panel carries no capture disclosure attributes', $panelShell);
        }
        $assert(1 === count($headings) && ! str_contains(serialize($headings[0]), 'Answer ' . $index . '.'), $case . ': item ' . $index . ' answer is not part of the heading', $serialized);
        $assert($expectedOpen[$index] === ( true === ( $item['attrs']['openByDefault'] ?? false ) ), $case . ': item ' . $index . ' openByDefault follows the item aria-expanded', json_encode($item['attrs'] ?? array()) ?: '');

        $itemOpenTag = preg_match('/<div class="wp-block-accordion-item[^>]*>/', (string) ( $item['innerHTML'] ?? '' ) . (string) implode('', array_filter($item['innerContent'] ?? array(), 'is_string')), $m) ? $m[0] : '';
        $itemShell = json_encode($item['attrs'] ?? array()) . $itemOpenTag;
        $assert(! str_contains($itemShell, 'aria-controls') && ! str_contains($itemShell, 'tabindex'), $case . ': item ' . $index . ' keeps no aria-controls/tabindex', $itemShell);
    }
    $assert(! preg_match('/<div class="wp-block-accordion-item[^>]*(?:aria-controls|tabindex)/', $serialized), $case . ': no saved accordion item carries aria-controls/tabindex', $serialized);
};

$reducedItem = static fn (int $n, string $expanded): string =>
    '<div class="faq-item" aria-controls="p' . $n . '" aria-expanded="' . $expanded . '" tabindex="0">'
    . '<div class="row"><h3>Question ' . $n . '?</h3><span class="faq-icon">+</span></div>'
    . '<div class="faq-answer hidden" id="p' . $n . '" role="region" data-blocks-engine-capture-local-disclosure="true" hidden><p>Answer ' . $n . '.</p></div>'
    . '</div>';

$reduced = static fn (string $first, string $second): string =>
    '<style>.hidden{display:none}</style><section><h2>FAQ</h2><div class="list">'
    . $reducedItem(0, $first) . $reducedItem(1, $second)
    . '</div></section>';

$check('reduced fixture', $reduced('false', 'false'), array( false, false ));
$check('reduced fixture, second item expanded', $reduced('false', 'true'), array( false, true ));

$capturedItem = static fn (int $n, string $expanded): string =>
    '<div class="faq-item" onclick="toggleFaq(this)" aria-controls="faq-panel-' . $n . '" aria-expanded="' . $expanded . '" tabindex="0">'
    . '<div class="faq-question"><h3>Question ' . $n . '?</h3>'
    . '<span class="faq-icon" data-blocks-engine-capture-disclosure-open-class="faq-icon rotate" data-blocks-engine-capture-disclosure-closed-class="faq-icon">+</span></div>'
    . '<div class="faq-answer mt-4 text-gray-600 hidden" id="faq-panel-' . $n . '" role="region"'
    . ' data-blocks-engine-capture-local-disclosure="true"'
    . ' data-blocks-engine-capture-hydrated-disclosure="true"'
    . ' data-blocks-engine-capture-disclosure-open-class="faq-answer mt-4 text-gray-600"'
    . ' data-blocks-engine-capture-disclosure-closed-class="faq-answer mt-4 text-gray-600 hidden"'
    . ' hidden><p>Answer ' . $n . '.</p></div>'
    . '</div>';

$captured = '<style>.hidden{display:none}.mt-4{margin-top:1rem}.text-gray-600{color:#4b5563}.faq-icon.rotate{transform:rotate(45deg)}</style>'
    . '<section><h2>Frequently asked</h2><div class="list">'
    . $capturedItem(0, 'false') . $capturedItem(1, 'false') . $capturedItem(2, 'true') . $capturedItem(3, 'false')
    . '</div></section>';

$check('full captured shape', $captured, array( false, false, true, false ), array(
    'present' => array( 'faq-answer', 'mt-4', 'text-gray-600' ),
    'absent' => array( 'hidden' ),
));

// The same rule holds when the disclosure control is a title button.
$buttonItem = static fn (int $n, string $expanded): string =>
    '<div class="faq-item"><h3><button type="button" aria-controls="faq-btn-panel-' . $n . '" aria-expanded="' . $expanded . '">Question ' . $n . '?'
    . '<span class="faq-icon" data-blocks-engine-capture-disclosure-open-class="faq-icon rotate" data-blocks-engine-capture-disclosure-closed-class="faq-icon">+</span></button></h3>'
    . '<div class="faq-answer mt-4 hidden" id="faq-btn-panel-' . $n . '" role="region"'
    . ' data-blocks-engine-capture-local-disclosure="true"'
    . ' data-blocks-engine-capture-disclosure-open-class="faq-answer mt-4"'
    . ' data-blocks-engine-capture-disclosure-closed-class="faq-answer mt-4 hidden"'
    . ' hidden><p>Answer ' . $n . '.</p></div>'
    . '</div>';

$check('title button control', '<style>.hidden{display:none}.mt-4{margin-top:1rem}</style><section><div class="list">'
    . $buttonItem(0, 'false') . $buttonItem(1, 'true') . '</div></section>', array( false, true ), array(
    'present' => array( 'faq-answer', 'mt-4' ),
    'absent' => array( 'hidden' ),
));

// A disclosure must own the whole item's content, not just a read-more label.
foreach ( array(
    'review card' => array(
        'item' => '<article><div class="card-top"><div><span>AL</span><div>Alex Lee</div><div>Customer review</div></div><img src="/brand.svg" alt="Brand"></div>'
            . '<div role="img" aria-label="Rating"><span><svg viewBox="0 0 10 10"><path d="M0 0L10 10"/></svg></span><span>5 out of 5</span></div>'
            . '<div class="card-body"><p>Helpful service.</p></div><div><span>7 months ago</span><button aria-expanded="false">Read review</button></div></article>',
        'accordion' => false,
        'text' => array( 'Alex Lee', '5 out of 5', '7 months ago', 'Helpful service.' ),
    ),
    'unlinked toggle with visible body' => array(
        'item' => '<div><button aria-expanded="false">Read more</button><div class="body">Visible content.</div></div>',
        'accordion' => false,
        'text' => array( 'Read more', 'Visible content.' ),
    ),
    'classic FAQ' => array(
        'item' => '<div class="faq-item"><h3><button aria-expanded="false" aria-controls="a1">Question?</button></h3><div id="a1" hidden>Answer.</div></div>',
        'accordion' => true,
        'text' => array( 'Question?', 'Answer.' ),
    ),
) as $case => $fixture ) {
    // Two items exercise collection recognition as well as item conversion.
    $html = '<section>' . $fixture['item'] . str_replace('a1', 'a2', $fixture['item']) . '</section>';
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $serialized = (string) ( $result['serialized_blocks'] ?? '' );
    $items = $collect($result['blocks'] ?? array(), 'core/accordion-item');
    $assert($fixture['accordion'] ? 2 === count($items) : array() === $items, $case . ': only complete disclosures become accordion items', $serialized);
    $assert($fixture['accordion'] || array() === $collect($result['blocks'] ?? array(), 'core/accordion'), $case . ': no accordion container for cards', $serialized);
    foreach ( $fixture['text'] as $text ) {
        $assert(str_contains($serialized, $text), $case . ': retains ' . $text, $serialized);
    }
}

if ( $failures > 0 ) {
    fwrite(STDERR, "Accordion item-level control: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Accordion item-level control passed: {$passes} assertions\n");
