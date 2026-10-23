<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if ( ! $condition ) $failures[] = 'FAIL: ' . $label;
};
$compile = static function (string $body): array {
    $html = '<!doctype html><html><head><title>Forms</title></head><body><main>' . $body . '</main></body></html>';
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
};
$forms = static fn (array $result): array => array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? '')));

// Reduced neutral fixture from #3255: neither a product grid nor vendor classes.
$result = $compile('<form method="post" action="/cart/add" data-type="add-to-cart-form"><input type="hidden" name="id" value="4242"><input type="number" name="quantity" value="1" min="1" step="1"><button type="submit" name="add">Add to cart</button></form>
<form method="get" action="/collections/all"><select name="sort_by" onchange="this.form.submit()"><option value="title">Title</option></select></form>
<form method="post"><input name="name"><input type="email" name="email"><textarea name="message"></textarea><button type="submit">Send</button></form>');
$findings = $forms($result);
$assert(3 === count($findings), 'all three forms retain their findings');
$assert(array('commerce_add_to_cart', 'filter_sort', 'contact') === array_column($findings, 'form_intent'), 'cart, sort and contact intents');
$assert('commerce_cart_runtime' === ($findings[0]['materialization_target']['provider_role'] ?? ''), 'standalone cart targets commerce runtime');
$assert('shop' === ($findings[0]['materialization_target']['capability'] ?? '') && 'commerce_controls' === ($findings[0]['materialization_target']['entity'] ?? ''), 'cart uses existing commerce target contract');
$assert('form_provider' === ($findings[2]['materialization_target']['provider_role'] ?? ''), 'contact retains provider target');
$assert(!empty($result['blocks']), 'page still emits blocks');
$cartMarkup = $findings[0]['binding']['search_block_markup'] ?? '';
$assert('' !== $cartMarkup && str_contains($result['serialized_blocks'] ?? '', $cartMarkup), 'cart binding anchors on the emitted preserved block');
$assert(str_contains($cartMarkup, 'name="quantity"') && str_contains($cartMarkup, 'step="1"') && str_contains($cartMarkup, 'value="4242"'), 'cart quantity constraints and hidden item survive rendering');
foreach ( $findings as $index => $finding ) {
    $assert(!empty($finding['controls']) && !empty($finding['form']) && !empty($finding['binding']) && !empty($finding['fallback_identity']), 'existing metadata and binding retained: ' . $index);
}

$cases = array(
    'action' => array('<form method="post" action="https://example.test/cart/add"><input name="item"><button>Submit</button></form>', 'commerce_add_to_cart'),
    'item-and-quantity' => array('<form method="post"><input type="hidden" name="item_id" value="42"><input type="number" name="quantity"><button>Submit</button></form>', 'commerce_add_to_cart'),
    'submit' => array('<form method="post"><input name="item"><button>Add to cart</button></form>', 'commerce_add_to_cart'),
    'contact-with-select-default-get' => array('<form name="contact" onsubmit="send()"><input type="email" name="email"><select name="subject"><option>Sales</option><option>Support</option></select><textarea name="message"></textarea><button>Send</button></form>', 'contact'),
    'contact-with-phone-and-select-no-method' => array('<form><input type="text" name="name"><input type="tel" name="phone"><select name="preference"><option>Email</option><option>Phone</option></select><button type="submit">Send</button></form>', 'unknown'),
    'facet-with-choices-and-price-range' => array('<form><input type="checkbox" name="filter.size" value="m"><input type="number" name="filter.price.gte"><select name="sort_by"><option>Price</option></select></form>', 'filter_sort'),
    'filter-without-email' => array('<form onsubmit="apply()"><select name="sort_by"><option>Price</option><option>Name</option></select><button>Apply</button></form>', 'filter_sort'),
    'search' => array('<form method="get"><input type="search" name="q" oninput="lookup()"></form>', 'search'),
    'newsletter' => array('<form method="post" action="/newsletter/subscribe"><input type="email" name="email"><button>Subscribe</button></form>', 'newsletter'),
    'login' => array('<form method="post"><input name="username"><input type="password" name="password"><button>Log in</button></form>', 'login'),
    'unknown' => array('<form method="post"><input name="reference"><button>Continue</button></form>', 'unknown'),
    'quantity-alone' => array('<form method="post"><input type="number" name="quantity"><button>Send</button></form>', 'unknown'),
    'registration-not-cart' => array('<form method="post"><input name="name"><button>Register</button></form>', 'unknown'),
    'classes-not-intent' => array('<form method="post" class="cart"><input name="reference"><button class="add-to-cart">Continue</button></form>', 'unknown'),
);
foreach ( $cases as $label => [$html, $intent] ) {
    $finding = $forms($compile($html))[0] ?? array();
    $assert($intent === ($finding['form_intent'] ?? ''), $label . ' intent is ' . $intent);
    if ( 'commerce_add_to_cart' !== $intent ) $assert('form_provider' === ($finding['materialization_target']['provider_role'] ?? ''), $label . ' keeps legacy target');
}
$native = $compile('<form method="get" action="/items"><select name="sort"><option>Title</option></select><button>Apply</button></form>');
$assert(array() === $forms($native), 'native GET sorting without script remains native, not a fallback');
$assert(str_contains($native['serialized_blocks'] ?? '', 'wp:custom/authored-native-form'), 'native GET block is unchanged');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Form fallback intent: passed\n";
