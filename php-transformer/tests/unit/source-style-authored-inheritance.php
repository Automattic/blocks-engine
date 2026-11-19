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
$document->loadHTML('<!doctype html><p style="color: red">Text</p>');
$element = $document->getElementsByTagName('p')->item(0);
$assert($element instanceof DOMElement, 'fixture has an element');
$state = new SourceStyleResolutionState();
$calls = 0;
$compute = static function (DOMElement $node) use (&$calls): array {
    ++$calls;
    return array('color' => $node->getAttribute('style'), 'font-weight' => '700');
};
$read = static fn (bool $navigation = false, array $conditions = array(), bool $skip = false): array => $state->authoredInheritedDeclarations($element, $navigation, $conditions, $skip, $compute);
$expected = array('color' => 'color: red', 'font-weight' => '700');
$assert($expected === $read(), 'first query returns the computed declarations unchanged');
$assert($expected === $read() && 1 === $calls, 'repeated property queries reuse the whole cascade');
$element->setAttribute('style', 'color: blue');
$assert('color: blue' === $read()['color'] && 2 === $calls, 'inline style changes recompute');
$read();
$assert(2 === $calls, 'new inline style result is reused');
$state->invalidateSelectorMatches();
$assert('color: blue' === $read()['color'] && 3 === $calls, 'selector invalidation recomputes');
$read(true);
$read(false, array('@media screen'));
$read(false, array(), true);
$assert(6 === $calls, 'every argument participates in the key');
$read(true);
$assert(6 === $calls, 'argument variants are remembered independently');
$state->setFormLayoutCss('');
$read();
$assert(7 === $calls, 'form stylesheet changes clear the memo');
$state->retainMatchableRules(static fn (array $rule): bool => true);
$read();
$assert(8 === $calls, 'retaining rules clears the memo');
$state->installStylesheetAnalysis(array(), array(
    'static' => array(), 'conditional' => array(), 'navigation_state' => array(),
    'reveal_state' => array(), 'image_shape' => array(), 'pseudo' => array(),
    'custom_properties' => array(),
));
$read();
$assert(9 === $calls, 'installing stylesheet analysis clears the memo');
$element->parentNode->removeChild($element);
$assert('color: blue' === $read()['color'], 'detached queries still compute');
$read();
$assert(11 === $calls, 'detached elements never reuse a memo');

echo "Source style authored inheritance passed.\n";
