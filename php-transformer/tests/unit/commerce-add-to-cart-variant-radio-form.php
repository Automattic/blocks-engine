<?php
declare(strict_types=1);

/**
 * #6442: an add-to-cart form whose only visitor input is variant radio
 * choices keeps native/authored blocks and reports the commerce controls
 * finding instead of being frozen as core/html with an html_form_fallback.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeRequirementAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$blockNames = static function (array $blocks) use (&$blockNames): array {
    $names = array();
    foreach ($blocks as $block) {
        $names[] = (string) ($block['blockName'] ?? '');
        $names = array_merge($names, $blockNames($block['innerBlocks'] ?? array()));
    }
    return $names;
};
$page = static fn (string $form): string => '<!DOCTYPE html><html lang="en"><head><title>x</title></head><body><main><section><h2>Coffee</h2>' . $form . '</section></main></body></html>';
$radios = '<input type="radio" name="opt" id="o1" value="1" checked> <label for="o1">Small</label><input type="radio" name="opt" id="o2" value="2"> <label for="o2">Large</label>';
$purchase = static fn (string $choices): string => '<form method="post" action="/cart/add"><input type="hidden" name="id" value="101">' . $choices . '<button type="submit" name="add">Add to cart</button></form>';

foreach (array(
    'fieldset' => '<fieldset><legend>Size</legend>' . $radios . '</fieldset>',
    'bare radios' => $radios,
) as $case => $choices) {
    $html = $page($purchase($choices));
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $assert('commerce_add_to_cart' === FormRuntimeRequirementAnalyzer::formIntent($dom->getElementsByTagName('form')->item(0)), "$case: form intent is commerce_add_to_cart.");

    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = (string) $result['serialized_blocks'];
    $assert(!in_array('core/html', $blockNames($result['blocks'] ?? array()), true) && !str_contains($markup, '<!-- wp:html'), "$case: no core/html block is emitted.");
    $assert(1 === preg_match('~<!-- wp:custom/authored-input \{[^}]*"type":"radio"[^}]*"id":"o1"~', $markup)
        && 1 === preg_match('~<!-- wp:custom/authored-input \{[^}]*"type":"radio"[^}]*"id":"o2"~', $markup), "$case: both radios survive as authored inputs.");
    $assert(str_contains($markup, 'name="opt" value="1" checked'), "$case: the checked variant stays checked.");
    $assert(1 === preg_match('~<!-- wp:custom/authored-button \{"type":"submit","name":"add"~', $markup), "$case: the submit survives as an authored button.");

    $fallbacks = $result['fallbacks'] ?? array();
    $forms = array_filter($fallbacks, static fn (array $f): bool => 'html_form_fallback' === ($f['diagnostic_code'] ?? null));
    $assert(array() === $forms, "$case: no html_form_fallback is reported.");
    $commerce = array_values(array_filter($fallbacks, static fn (array $f): bool => 'html_commerce_controls_fallback' === ($f['diagnostic_code'] ?? null)));
    $assert(1 === count($commerce), "$case: one commerce controls finding is reported.");
    $finding = $commerce[0];
    $assert('commerce_add_to_cart' === ($finding['form_intent'] ?? null), "$case: finding carries the commerce_add_to_cart intent.");
    $assert('commerce_cart_runtime' === ($finding['runtime_requirement'] ?? null), "$case: finding requires the cart runtime.");
    $assert('commerce_add_to_cart_form' === ($finding['binding']['role'] ?? null) && '' !== trim($finding['binding']['search_block_markup'] ?? ''), "$case: finding binds the native purchase form.");
    $assert(str_contains($markup, (string) $finding['binding']['search_block_markup']), "$case: binding anchor occurs in the page markup.");
    $assert(array('radio', 'radio', 'submit') === array_values(array_filter(array_column($finding['controls'] ?? array(), 'type'), static fn (string $type): bool => 'hidden' !== $type)), "$case: finding lists the variant radios and submit.");
}

// A poll answered by named radios is not a purchase and keeps its existing
// choice_submission behaviour: readable inactive controls, a form finding.
$poll = '<form method="post" action="/poll"><fieldset><legend>Favourite roast</legend><input type="radio" name="roast" id="r1" value="light"> <label for="r1">Light</label><input type="radio" name="roast" id="r2" value="dark"> <label for="r2">Dark</label></fieldset><button type="submit">Vote</button></form>';
$dom = new DOMDocument();
@$dom->loadHTML($page($poll));
$pollForm = $dom->getElementsByTagName('form')->item(0);
$assert('choice_submission' === FormRuntimeRequirementAnalyzer::formIntent($pollForm), 'Poll: intent stays choice_submission.');
$assert(!FormRuntimeRequirementAnalyzer::collectsOnlyPurchaseChoices($pollForm), 'Poll: not treated as purchase choices.');
$result = (new HtmlTransformer())->transform($page($poll))->toArray();
$codes = array_column($result['fallbacks'] ?? array(), 'diagnostic_code');
$assert(!in_array('html_commerce_controls_fallback', $codes, true), 'Poll: no commerce finding.');
$assert(str_contains((string) $result['serialized_blocks'], 'This form is not accepting responses yet.'), 'Poll: keeps its inactive choice_submission rendering.');

// A non-commerce choice form posted to a handler still freezes as before.
$survey = '<form method="post" action="/survey"><input type="radio" name="q" value="a"><input type="radio" name="q" value="b"><input type="checkbox" name="extra"><button type="submit">Send</button></form>';
$dom = new DOMDocument();
@$dom->loadHTML($page($survey));
$assert(!FormRuntimeRequirementAnalyzer::collectsOnlyPurchaseChoices($dom->getElementsByTagName('form')->item(0)), 'Survey: not treated as purchase choices.');
$result = (new HtmlTransformer())->transform($page($survey))->toArray();
$codes = array_column($result['fallbacks'] ?? array(), 'diagnostic_code');
$assert(in_array('html_form_fallback', $codes, true) && !in_array('html_commerce_controls_fallback', $codes, true), 'Survey: keeps its html_form_fallback.');

echo "commerce-add-to-cart-variant-radio-form: ok\n";
