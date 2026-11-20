<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceStyleResolutionState;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$state = new SourceStyleResolutionState();
$analysis = array(
    'static' => array(
        array( 'selector' => '.original', 'declarations' => array( 'color' => 'red' ) ),
        array( 'selector' => '.replacement', 'declarations' => array( 'color' => 'blue' ) ),
    ),
    'conditional' => array(),
    'navigation_state' => array(),
    'reveal_state' => array(),
    'image_shape' => array(),
    'pseudo' => array(),
    'custom_properties' => array(),
);
$state->installStylesheetAnalysis(array(), $analysis);
$document = new DOMDocument();
$document->loadHTML('<body><div><span class="original">Original</span></div></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
$element = $document->getElementsByTagName('span')->item(0);
$derivations = 0;
$derive = static function (array $rules) use (&$derivations): array {
    ++$derivations;
    return $rules[0]['declarations'] ?? array();
};
$assert(array('.original') === array_column($state->matchingRules($element, 'static'), 'selector'), 'original node matches its rule');
$assert(array('color' => 'red') === $state->derivedFromMatchingRules($element, 'static', 'color', $derive), 'derive from original matches');
$hits = $state->selectorMatchCache->matchHits;
$executions = $state->selectorMatchCache->matchExecutions;
$keyHits = $state->selectorMatchCache->connectedElementKeyHits;
$fetched = $document->getElementsByTagName('span')->item(0);
$assert($element->isSameNode($fetched), 'freshly fetched wrapper represents the same native node');
$assert(array('.original') === array_column($state->matchingRules($fetched, 'static'), 'selector'), 'fresh wrapper reuses matching rules');
$assert(array('color' => 'red') === $state->derivedFromMatchingRules($fetched, 'static', 'color', $derive), 'fresh wrapper reuses derived value');
$assert(1 === $derivations && $hits === $state->selectorMatchCache->matchHits && $executions === $state->selectorMatchCache->matchExecutions, 'same native node does not repeat rule matching or derivation');

$assert($state->selectorMatchCache->connectedElementKeyHits > $keyHits, 'remembered lookups reuse the selector cache element key');

$path = $element->getNodePath();
$replacement = $document->createElement('span', 'Replacement');
$replacement->setAttribute('class', 'replacement');
$element->parentNode->replaceChild($replacement, $element);
$assert($path === $replacement->getNodePath(), 'replacement occupies the same path');
$assert(array('.replacement') === array_column($state->matchingRules($replacement, 'static'), 'selector'), 'same-path replacement matches its new rule, not the remembered rule');
$assert(array('color' => 'blue') === $state->derivedFromMatchingRules($replacement, 'static', 'color', $derive) && 2 === $derivations, 'replacement recomputes derived value');

$state->setFormLayoutCss('span { color: green; }');
$assert(array('color' => 'blue') === $state->derivedFromMatchingRules($replacement, 'static', 'color', $derive) && 3 === $derivations, 'form layout change drops derivations but keeps matches');
$state->installStylesheetAnalysis(array(), $analysis);
$assert(array('color' => 'blue') === $state->derivedFromMatchingRules($replacement, 'static', 'color', $derive) && 4 === $derivations, 'installing analysis drops matches and derivations without resetting identities');
$state->retainMatchableRules(static fn (array $rule): bool => true);
$assert(array('.replacement') === array_column($state->matchingRules($replacement, 'static'), 'selector'), 'retained rules still match');
$assert(array('color' => 'blue') === $state->derivedFromMatchingRules($replacement, 'static', 'color', $derive) && 5 === $derivations, 'retaining rules drops derived values without resetting identities');
$state->invalidateSelectorMatches();
$assert(array('color' => 'blue') === $state->derivedFromMatchingRules($replacement, 'static', 'color', $derive) && 6 === $derivations, 'selector invalidation drops identities and derived values together');

$detached = $document->createElement('span');
$assert(array() === $state->matchingRules($detached, 'static'), 'detached elements still compute matches');
$state->derivedFromMatchingRules($detached, 'static', 'color', $derive);
$state->derivedFromMatchingRules($detached, 'static', 'color', $derive);
$assert(8 === $derivations, 'detached elements never remember derived values');
fwrite(STDOUT, "Source style matched-rule identity passed\n");
