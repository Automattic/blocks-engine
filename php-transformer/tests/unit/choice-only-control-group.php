<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    ++$count;
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};
$formFallbacks = static fn (array $result): array => array_values(array_filter(
    $result['fallbacks'],
    static fn (array $fallback): bool => 'form_requires_runtime' === ($fallback['reason'] ?? '')
));

// A client-side sort/filter panel: choice controls and type=button actions, no
// form, no submission target and no free-text field. The action labels carry
// the vocabulary of a submit-style control so the panel reads like a form.
$options = '<fieldset><legend>Sort by</legend>'
    . '<input type="radio" name="sort-by" value="recommended" aria-label="Recommended">'
    . '<input type="radio" name="sort-by" value="price_ascending" aria-label="Price (low to high)" checked>'
    . '<input type="checkbox" name="in-stock" value="1" aria-label="In stock only"></fieldset>';
$panel = '<section><h2>Filter and sort</h2><button type="button" aria-expanded="true">Filter</button>' . $options
    . '<button type="button">Clear filters</button><button type="button" class="send-results">Show results</button></section>';

$choice = (new HtmlTransformer())->transform('<main>' . $panel . '</main>')->toArray();
$assert(array() === $formFallbacks($choice), 'choice-only control group is not routed to a form provider');
$assert(array() === $choice['fallbacks'], 'choice-only control group converts without any fallback');
foreach ( array( 'Filter and sort', 'Filter', 'Clear filters', 'Show results', 'Recommended', 'Price (low to high)', 'In stock only' ) as $text ) {
    $assert(str_contains($choice['serialized_blocks'], $text), "visible text \"{$text}\" survives in the blocks");
}

// The same controls inside a real form with a submit control keep the form path.
$form = (new HtmlTransformer())->transform('<main><form action="/search">' . $options . '<button type="submit">Send</button></form></main>')->toArray();
$assert(str_contains($form['serialized_blocks'], 'authored-native-form') || array() !== $formFallbacks($form), 'a real form with a submit control keeps its form ownership');

// A free-text field turns the same group into data entry, which keeps the pseudo-form path.
$dataEntry = (new HtmlTransformer())->transform('<main><section>' . $options
    . '<label>Email<input type="email" name="email"></label><button type="button" class="send-results">Send</button></section></main>')->toArray();
$assert(array() !== $formFallbacks($dataEntry), 'a group with a free-text field still routes to the form provider');

echo "Choice-only control group tests passed ({$count} assertions)\n";
