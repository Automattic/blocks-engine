<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * Contract coverage for a storefront Support page: an order-lookup form
 * (order number, the email used at checkout, a submit control).
 *
 * The producer must declare the form once, under `generic/order-lookups/v1`
 * for the shop capability, with its order and email fields, its submit label
 * and one `order_lookup` binding finalized on the form's emitted block — and
 * never also as a generic `forms` entity, so the form never reaches a form
 * provider that cannot answer an order lookup. The `html_form_fallback`
 * finding stays reported with its fallback identity, so a consumer can
 * reconcile the source form against its materialization. Hidden inputs do
 * not count toward the two fields. A contact form (name + email
 * + message) and an email-only newsletter form keep their generic `forms`
 * declaration: only the exact order-lookup shape leaves the generic path.
 * Runs the full `ArtifactCompiler` -> `WordPressSitePlan` pipeline because
 * bindings are finalized and projected at site-plan composition.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$orderLookupForm = <<<'HTML'
<form class="wq-form" novalidate>
  <div class="wq-row">
    <label><span>Order #</span><input type="text" inputmode="numeric" placeholder="#1234" required name="orderNumber"></label>
    <label><span>Email used at checkout</span><input type="email" autocomplete="email" required name="email"></label>
  </div>
  <button class="btn btn--primary wq-submit" type="submit">Check my order</button>
</form>
HTML;

$page = static fn (string $title, string $body): string => '<!doctype html><html><head><title>' . $title . '</title></head><body><main><h1>' . $title . '</h1>' . $body . '</main></body></html>';

$compile = static fn (string $body): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'support.html', 'files' => array('support.html' => $page('Support', $body))))->toArray();
$findings = static function (array $result, string $code): array {
    $found = array();
    $walk = static function (mixed $node) use (&$walk, &$found, $code): void {
        if (! is_array($node)) {
            return;
        }
        if ($code === ($node['diagnostic_code'] ?? null) && 'support.html' === ($node['source_path'] ?? $node['source'] ?? null)) {
            $found[($node['selector'] ?? '') . "\n" . ($node['id'] ?? '')] = $node;
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    $walk($result['source_reports']['html_diagnostics'] ?? $result);
    return array_values($found);
};
$declaration = static fn (array $plan, string $type): ?array => current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => $type === ($declaration['type'] ?? null))) ?: null;
$dependency = static fn (array $plan, string $capability): ?array => current(array_filter($plan['runtime_declarations'] ?? array(), static fn (array $declaration): bool => 'dependency' === ($declaration['kind'] ?? null) && $capability === ($declaration['capability'] ?? null))) ?: null;

// Case A: the Support page's order-lookup form is declared for the shop capability.
$result = $compile($orderLookupForm);
$assert('failed' !== $result['status'] && ! isset($result['source_reports']['wordpress_site_plan_diagnostics']), 'The Support page composes cleanly: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();

$formFallbacks = $findings($result, 'html_form_fallback');
$assert(1 === count($formFallbacks), 'The order-lookup form keeps exactly one html_form_fallback finding: ' . json_encode(array_column($formFallbacks, 'selector')));
$assert(1 === preg_match('/^[a-f0-9]{64}$/', (string) ($formFallbacks[0]['fallback_identity'] ?? '')), 'The html_form_fallback finding keeps its fallback identity: ' . json_encode($formFallbacks[0]['fallback_identity'] ?? null));

$orderLookups = $declaration($plan, 'order_lookups');
$assert(null !== $orderLookups && 'generic/order-lookups/v1' === ($orderLookups['payload']['schema'] ?? null), 'A generic/order-lookups/v1 entity collection is declared.');
$entities = $orderLookups['payload']['entities'] ?? array();
$assert(1 === count($entities), 'Exactly one order lookup is declared: ' . json_encode(array_column($entities, 'selector')));
$entity = $entities[0];
$assert('support.html' === ($entity['source_path'] ?? null) && 'main:nth-of-type(1) > form:nth-of-type(1)' === ($entity['selector'] ?? null), 'The order lookup names the form page and selector: ' . json_encode(array('source_path' => $entity['source_path'] ?? null, 'selector' => $entity['selector'] ?? null)));
$assert(array('label' => 'Order #', 'placeholder' => '#1234') === ($entity['fields']['order'] ?? null), 'The order field carries its label and placeholder: ' . json_encode($entity['fields']['order'] ?? null));
$assert(array('label' => 'Email used at checkout') === ($entity['fields']['email'] ?? null), 'The email field carries its label and no placeholder: ' . json_encode($entity['fields']['email'] ?? null));
$assert('Check my order' === ($entity['submit_label'] ?? null), 'The submit label is declared: ' . json_encode($entity['submit_label'] ?? null));

$binding = $entity['bindings'][0] ?? array();
$assert('generic/block-binding/v1' === ($binding['schema'] ?? null) && 'order_lookup' === ($binding['role'] ?? null) && 1 === ($binding['occurrence'] ?? null), 'The order lookup carries an order_lookup block binding: ' . json_encode(array('schema' => $binding['schema'] ?? null, 'role' => $binding['role'] ?? null, 'occurrence' => $binding['occurrence'] ?? null)));
$position = $binding['position'] ?? null;
$assert(is_array($position) && 'blocks-engine/runtime-binding-position/v1' === ($position['schema'] ?? null) && is_int($position['block_index'] ?? null) && is_int($position['offset'] ?? null) && is_int($position['length'] ?? null), 'The binding is finalized to one emitted block position: ' . json_encode($position));
$canonical = (string) (current(array_filter($plan['pages'] ?? array(), static fn (array $page): bool => 'support.html' === ($page['source_path'] ?? null)))['canonical_block_markup'] ?? '');
$search = (string) ($binding['search_block_markup'] ?? '');
$assert('' !== $search && 1 === substr_count($canonical, $search), 'The binding anchor appears exactly once in the page markup.');
$assert(str_contains($search, 'Check my order') && str_contains($search, 'wq-form'), 'The anchor is the order-lookup form block.');

$assert(null === $declaration($plan, 'forms'), 'The order-lookup form is not also declared in forms.');
$shopDependency = $dependency($plan, 'shop');
$assert(null !== $shopDependency && in_array('entity_collection:order_lookups', $shopDependency['required_for'] ?? array(), true), 'The shop dependency is declared for the order lookup: ' . json_encode($shopDependency['required_for'] ?? null));

// Case B: a hidden input does not count toward the two fields.
$guarded = $compile(str_replace('<button class="btn btn--primary wq-submit" type="submit">', '<input type="hidden" name="wq_nonce" value="1"><button class="btn btn--primary wq-submit" type="submit">', $orderLookupForm));
$guardedPlan = $guarded['source_reports']['wordpress_site_plan'] ?? array();
$guardedEntities = $declaration($guardedPlan, 'order_lookups')['payload']['entities'] ?? array();
$assert(1 === count($guardedEntities) && array('label' => 'Order #', 'placeholder' => '#1234') === ($guardedEntities[0]['fields']['order'] ?? null) && array('label' => 'Email used at checkout') === ($guardedEntities[0]['fields']['email'] ?? null), 'A hidden nonce does not count as an order-lookup field: ' . json_encode($guardedEntities[0]['fields'] ?? null));
$assert(null === $declaration($guardedPlan, 'forms'), 'The guarded order-lookup form is still not declared in forms.');

// Case C: a contact form (name + email + message) stays a generic form.
$contact = '<form class="contact-form"><label>Name<input type="text" name="name"></label><label>Email<input type="email" name="email"></label><label>Message<textarea name="message" rows="4"></textarea></label><button type="submit">Send</button></form>';
$contactResult = $compile($contact);
$contactPlan = $contactResult['source_reports']['wordpress_site_plan'] ?? array();
$assert(null === $declaration($contactPlan, 'order_lookups'), 'A contact form is not declared as an order lookup.');
$contactEntities = $declaration($contactPlan, 'forms')['payload']['entities'] ?? array();
$assert(1 === count($contactEntities), 'The contact form is declared in forms: ' . json_encode(array_column($contactEntities, 'selector')));

// Case D: an email-only newsletter form stays a generic form.
$newsletter = '<form class="newsletter-form"><label>Email<input type="email" name="email"></label><button type="submit">Subscribe</button></form>';
$newsletterResult = $compile($newsletter);
$newsletterPlan = $newsletterResult['source_reports']['wordpress_site_plan'] ?? array();
$assert(null === $declaration($newsletterPlan, 'order_lookups'), 'An email-only newsletter form is not declared as an order lookup.');
$newsletterEntities = $declaration($newsletterPlan, 'forms')['payload']['entities'] ?? array();
$assert(1 === count($newsletterEntities), 'The newsletter form is declared in forms: ' . json_encode(array_column($newsletterEntities, 'selector')));

echo "order-lookup-form: ok\n";
