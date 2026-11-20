<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceStyleResolutionState;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$document = new DOMDocument();
$document->loadHTML('<!doctype html><ul>' . str_repeat('<li><div><p class="price">Price</p></div></li>', 200) . '</ul>');
$state = new SourceStyleResolutionState();
$state->installStylesheetAnalysis(array(), array(
    'static' => array(array('selector' => '.price', 'declarations' => array('color' => 'red'))),
    'conditional' => array(), 'navigation_state' => array(), 'reveal_state' => array(),
    'image_shape' => array(), 'pseudo' => array(), 'custom_properties' => array(),
));
$read = static function (DOMElement $element) use ($state, $assert): void {
    $assert(1 === count($state->matchingRules($element, 'static')), 'sibling card matches its source rule');
    $assert(array('color' => 'red') === $state->derivedFromMatchingRules($element, 'static', 'color', static fn (array $rules): array => $rules[0]['declarations']), 'derived declarations remain identical');
    $assert(1 === count($state->matchingRulesInCascadeOrder($element, 'static')), 'cascade shares matched rules');
    $state->cascadedDeclarations($element, static fn (DOMElement $node): array => array('--price' => $node->getAttribute('style')));
    $state->cascadedCustomProperties($element, false, static fn (array $chain): array => array('--price' => $chain[0]->getAttribute('style')));
    $state->stateStyleFamilies($element, static fn (DOMElement $node): array => array('color' => true));
};
foreach ( $document->getElementsByTagName('p') as $element ) {
    for ( $repeat = 0; $repeat < 5; ++$repeat ) {
        $read($element);
    }
}
$assert(200 === $state->selectorMatchCache->connectedElementKeyBuilds, 'all style memos share one node-path build per sibling element, not per lookup');
$element = $document->getElementsByTagName('p')->item(0);
$assert($element instanceof DOMElement, 'fixture has a price');
$element->setAttribute('style', 'changed');
$assert('changed' === $state->cascadedDeclarations($element, static fn (DOMElement $node): array => array('--price' => $node->getAttribute('style')))['custom']['--price'], 'inline changes recompute declarations');
$assert('changed' === $state->cascadedCustomProperties($element, false, static fn (array $chain): array => array('--price' => $chain[0]->getAttribute('style')))['--price'], 'inline changes recompute inherited custom properties');
$replacement = $document->createElement('p');
$element->parentNode->replaceChild($replacement, $element);
$assert(array() === $state->matchingRules($replacement, 'static'), 'replacement at the same path does not reuse prior rules');
$assert(array('--detached' => 'fresh') === $state->cascadedDeclarations($element, static fn (DOMElement $node): array => array('--detached' => 'fresh'))['declarations'], 'detached declarations are evaluated afresh');
$replacement->setAttribute('class', 'price');
$builds = $state->selectorMatchCache->connectedElementKeyBuilds;
$state->invalidateSelectorMatches();
$read($replacement);
$assert($builds + 1 === $state->selectorMatchCache->connectedElementKeyBuilds, 'invalidation clears shared element identities with style memos');
echo "Source style element identity passed.\n";
