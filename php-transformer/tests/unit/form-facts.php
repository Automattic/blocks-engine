<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormFacts;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\FormRuntimeRequirementAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

foreach ( array(
    '<form method="post"><input type="radio" name="q" value="a"><input type="radio" name="q" value="b"><button>Send</button></form>',
    '<form><select name="choice"><option>A</option></select></form>',
    '<form action="/cart/add"><input type="hidden" name="id" value="42"><select name="variant"><option>A</option></select><button type="button">Go</button></form>',
    '<form><input type="email"><button type="button">Go</button><button type="button">Back</button></form>',
    '<form><input type="hidden" name="secret"><input name="name"><button type="reset">Reset</button></form>',
) as $html ) {
    $document = HtmlSerialization::parseFragment($html);
    $form = $document->getElementsByTagName('form')->item(0);
    $assert($form instanceof DOMElement, 'The form parses.');
    $facts = new FormFacts($form);
    $expectedControls = array_values(FormControlClassifier::controlElements($form));
    $expectedSoleAction = FormControlClassifier::hasSoleActionControl($form);
    $expectedIntent = FormRuntimeRequirementAnalyzer::formIntent($form);
    $expectedChoices = FormRuntimeRequirementAnalyzer::collectsOnlyChoices($form);
    $expectedPurchaseChoices = FormRuntimeRequirementAnalyzer::collectsOnlyPurchaseChoices($form);
    $expectedSubmits = array_map(FormControlClassifier::isSubmitLikeControl(...), $expectedControls);
    $before = FormControlClassifier::controlScanVisits();
    for ( $repeat = 0; $repeat < 3; ++$repeat ) {
        $assert($facts->controlElements() === $expectedControls, 'The authored inventory is unchanged.');
        $assert($facts->hasSoleActionControl() === $expectedSoleAction, 'The sole-action fact is unchanged.');
        $assert($facts->intent() === $expectedIntent, 'Intent is unchanged.');
        $assert($facts->collectsOnlyChoices() === $expectedChoices, 'Choice-only status is unchanged.');
        $assert($facts->collectsOnlyPurchaseChoices() === $expectedPurchaseChoices, 'Purchase-choice status is unchanged.');
        $assert(array_map($facts->isSubmitLikeControl(...), $expectedControls) === $expectedSubmits, 'Every submit classification is unchanged.');
        $after = FormControlClassifier::controlScanVisits();
        if ( 0 < $repeat ) {
            $assert($after === $before, 'Repeated reads of whole-form facts do not scan the form again.');
        }
        $before = $after;
    }
}

// No document/global cache: a fresh scope sees mutations of the very same
// form element. A caller holds facts only while it treats the form as fixed.
$document = HtmlSerialization::parseFragment('<form><button type="button">Go</button></form>');
$form = $document->getElementsByTagName('form')->item(0);
$first = new FormFacts($form);
$assert($first->hasSoleActionControl(), 'One action is sole.');
$assert(1 === count($first->controlElements()), 'The first scope sees one control.');
$form->appendChild($document->createElement('button', 'Back'));
$second = new FormFacts($form);
$assert(! $second->hasSoleActionControl(), 'A later scope sees the added action.');
$assert(2 === count($second->controlElements()), 'A later scope sees the changed inventory.');

// Equal forms in one document have separate identities and facts.
$other = $document->createElement('form');
$other->appendChild($document->createElement('button', 'Send'));
$form->parentNode->appendChild($other);
$assert((new FormFacts($other))->hasSoleActionControl(), 'A separate form has its own sole action.');

echo "form-facts: ok\n";
