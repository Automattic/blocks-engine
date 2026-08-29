<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormControlMetadataBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\PseudoFormAnalyzer;

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
    foreach ( $document->getElementsByTagName('body')->item(0)->childNodes as $node ) {
        if ( $node instanceof DOMElement ) {
            return $node;
        }
    }
    throw new RuntimeException('No element parsed');
};

$selector = static function (DOMElement $element): string {
    $id = $element->getAttribute('id');
    return '' !== $id ? '#' . $id : strtolower($element->tagName);
};
$metadataBuilder = new FormControlMetadataBuilder($selector);
$analyzer = new PseudoFormAnalyzer($metadataBuilder, $selector);

$local = $elementFrom('<div id="signup"><label>Email<input name="email"></label><button type="submit">Join</button></div>');
$assert($analyzer->isPseudoForm($local), 'labeled-entry-and-submit-is-pseudo-form');

$plainButton = $elementFrom('<div><label>Email<input name="email"></label><button type="button">Join</button></div>');
$assert(! $analyzer->isPseudoForm($plainButton), 'explicit-button-has-no-submit-ownership');

$containerAction = $elementFrom('<div data-action="subscribe"><label>Email<input name="email"></label><button type="button">Join</button></div>');
$assert($analyzer->isPseudoForm($containerAction), 'container-action-owns-plain-button');

$search = $elementFrom('<div><input type="search" aria-label="Search"><button type="submit">Go</button></div>');
$assert(! $analyzer->isPseudoForm($search), 'standalone-search-is-not-pseudo-form');

$landmark = $elementFrom('<div><nav>Menu</nav><label>Email<input name="email"></label><button type="submit">Join</button></div>');
$assert(! $analyzer->isPseudoForm($landmark), 'unrelated-landmark-rejects-page-shell');

$nested = $elementFrom('<div id="outer"><div id="inner"><label>Email<input name="email"></label><button type="submit">Join</button></div></div>');
$inner = $nested->getElementsByTagName('div')->item(0);
$assert($inner instanceof DOMElement && $analyzer->isPseudoForm($inner), 'tightest-container-is-pseudo-form');
$assert(! $analyzer->isPseudoForm($nested), 'ancestor-defers-to-tightest-container');

$boundary = $inner instanceof DOMElement ? $analyzer->boundaryMetadata($inner) : array();
$assert('#inner' === ($boundary['selector'] ?? ''), 'boundary-identifies-selected-container');
$assert('contains_nested_coherent_form' === ($boundary['rejected_ancestors'][0]['reason'] ?? ''), 'boundary-explains-rejected-ancestor');

$form = $elementFrom('<form><label>Email<input name="email"></label><button type="submit">Join</button></form>');
$assert(! $analyzer->isPseudoForm($form), 'real-form-is-not-pseudo-form');

$formOwner = $elementFrom('<form><div><label>Email<input name="email"></label><button type="submit">Join</button></div></form>');
$ownedContainer = $formOwner->getElementsByTagName('div')->item(0);
$assert($ownedContainer instanceof DOMElement && ! $analyzer->isPseudoForm($ownedContainer), 'real-form-ancestor-owns-controls');

$searchRegion = $elementFrom('<div role="search"><input type="text"></div>');
$searchInput = $searchRegion->getElementsByTagName('input')->item(0);
$assert($searchInput instanceof DOMElement && $analyzer->hasStandaloneSearchSignal($searchRegion, $searchInput), 'search-role-identifies-standalone-search');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Pseudo-form analyzer tests: ' . $assertions . " passed\n";
