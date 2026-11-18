<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Tests\Support\RuntimeDomTargetsFixture;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeRequirementAnalyzer;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$elementFrom = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $element = $document->getElementsByTagName('form')->item(0);
    if ( $element instanceof DOMElement ) {
        return $element;
    }
    throw new RuntimeException('No form parsed');
};

$analyzer = new FormRuntimeRequirementAnalyzer(
    new RuntimeDomTargetsFixture(static fn (DOMElement $element): bool => $element->hasAttribute('data-runtime'))
);

$assert(! $analyzer->requiresPreservation($elementFrom('<form><input name="email"></form>')), 'plain-form-needs-no-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form><script>submit()</script></form>')), 'script-requires-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form data-event="1"></form>')), 'form-event-requires-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form action="/subscribe"></form>')), 'action-requires-runtime');
$assert(! $analyzer->requiresPreservation($elementFrom('<form action="#"></form>')), 'hash-action-is-inert');
$assert($analyzer->requiresPreservation($elementFrom('<form method="post"></form>')), 'method-without-action-requires-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form target="_blank"></form>')), 'target-requires-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form><button type="submit">Checkout</button></form>')), 'commerce-submit-requires-runtime');
$assert(! $analyzer->requiresPreservation($elementFrom('<form><button type="submit">Send</button></form>')), 'generic-submit-needs-no-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form data-runtime="1"></form>')), 'form-runtime-target-requires-preservation');
$assert($analyzer->requiresPreservation($elementFrom('<form><input data-runtime="1"></form>')), 'control-runtime-target-requires-preservation');
$assert($analyzer->requiresPreservation($elementFrom('<form class="signup js-form"></form>')), 'form-js-class-requires-runtime');
$assert($analyzer->requiresPreservation($elementFrom('<form><input class="field js-validate"></form>')), 'control-js-class-requires-runtime');

// A POST search form keeps search intent ahead of the path-only donation rule;
// hidden server tokens are not authored controls.
$postSearch = static fn (string $action, string $submit = '<input type="submit" name="op" value="Search">'): string =>
    '<form action="' . $action . '" method="post" id="search-block-form"><input type="text" name="search_block_form">' . $submit
    . '<input type="hidden" name="form_build_id" value="token"><input type="hidden" name="form_id" value="search_block_form"></form>';
$assert('search' === FormRuntimeRequirementAnalyzer::formIntent($elementFrom($postSearch('/giving'))), 'post-search-at-giving-path-is-search');
$assert('search' === FormRuntimeRequirementAnalyzer::formIntent($elementFrom($postSearch('/'))), 'post-search-at-root-is-search');
$assert('donation' === FormRuntimeRequirementAnalyzer::formIntent($elementFrom(
    '<form action="/giving" method="post"><label><input type="radio" name="amount" value="25"> $25</label><label><input type="radio" name="amount" value="50"> $50</label><button type="submit">Donate</button></form>'
)), 'post-donation-with-amounts-stays-donation');
$assert('donation' === FormRuntimeRequirementAnalyzer::formIntent($elementFrom($postSearch('/giving', '<button type="submit">Donate</button>'))), 'search-shaped-form-with-donate-submit-stays-donation');
$assert('contact' === FormRuntimeRequirementAnalyzer::formIntent($elementFrom(
    '<form action="/" method="post"><input type="text" name="name"><input type="email" name="email"><textarea name="message"></textarea><button type="submit">Send</button></form>'
)), 'post-contact-form-stays-contact');
$assert('login' === FormRuntimeRequirementAnalyzer::formIntent($elementFrom(
    '<form action="/search" method="post"><input type="text" name="search"><input type="password" name="pass"><button type="submit">Search</button></form>'
)), 'password-keeps-login-precedence');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form runtime requirement analyzer tests: ' . $assertions . " passed\n";
