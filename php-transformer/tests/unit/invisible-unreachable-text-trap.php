<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

$parse = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $form = $document->getElementsByTagName('form')->item(0);
    if ( ! $form instanceof DOMElement ) {
        throw new RuntimeException('No form parsed');
    }
    return $form;
};

// Exact acceptance markup from #3370, without aria-hidden.
$fixture = '<div class="field" style="opacity:0;position:absolute;z-index:-999;pointer-events:none">'
    . '<label for="trap">trap</label><input type="text" name="trap" id="trap" tabindex="-1" autocomplete="off">'
    . '</div>';
$form = $parse('<form>' . $fixture . '</form>');
$assert(array() === FormControlClassifier::controlElements($form), 'acceptance fixture yields no extracted control');
$copy = FormControlClassifier::honeypotCopyElements($form);
$assert(in_array($form->getElementsByTagName('label')->item(0), $copy, true), 'trap owns its label instead of leaving it as copy');
$assert(in_array($form->getElementsByTagName('div')->item(0), $copy, true), 'trap owns its field container copy');

foreach (array(
    'tabbable' => str_replace('tabindex="-1"', 'tabindex="0"', $fixture),
    'no tabindex' => str_replace(' tabindex="-1"', '', $fixture),
    'pointer reachable' => str_replace(';pointer-events:none', '', $fixture),
    'visible' => str_replace('opacity:0;', '', $fixture),
    'checkbox proxy' => str_replace('type="text"', 'type="checkbox"', $fixture),
    'radio proxy' => str_replace('type="text"', 'type="radio"', $fixture),
) as $case => $markup) {
    $form = $parse('<form>' . $markup . '</form>');
    $assert(1 === count(FormControlClassifier::controlElements($form)), $case . ' stays authored');
    $assert(array() === FormControlClassifier::honeypotCopyElements($form), $case . ' keeps its label and copy');
}

foreach (array('text', 'email', 'url', 'tel', 'search', 'number', 'textarea') as $type) {
    $control = 'textarea' === $type ? '<textarea name="trap" tabindex="-2" style="opacity:0;pointer-events:none"></textarea>'
        : '<input type="' . $type . '" name="trap" tabindex="-2" style="opacity:0;pointer-events:none">';
    $form = $parse('<form>' . $control . '</form>');
    $assert(array() === FormControlClassifier::controlElements($form), $type . ' supports signals on the control itself and any negative tabindex');
}

foreach (array('form', 'fieldset', 'outer ancestor') as $boundary) {
    $style = ' style="opacity:0;pointer-events:none"';
    $control = '<input name="real" tabindex="-1">';
    $html = match ($boundary) {
        'form' => '<form' . $style . '>' . $control . '</form>',
        'fieldset' => '<form><fieldset' . $style . '>' . $control . '</fieldset></form>',
        default => '<div' . $style . '><form><div>' . $control . '</div></form></div>',
    };
    $assert(1 === count(FormControlClassifier::controlElements($parse($html))), $boundary . ' is not a field container signal');
}

// An untabbable free-text trap hidden by an aria-hidden wrapper inside its
// form: the wrapper, its label, and the control stay out of readable blocks.
$wrapper = '<div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden">'
    . '<label>Leave empty<input type="text" name="hp" tabindex="-1" autocomplete="off"></label></div>';
$contact = '<main><form method="post" action="/contact">'
    . '<p><label for="name">Name</label><input type="text" id="name" name="name"></p>'
    . '<p><label for="email">Email</label><input type="email" id="email" name="email"></p>'
    . '<p><label for="message">Message</label><textarea id="message" name="message"></textarea></p>'
    . $wrapper
    . '<button type="submit">Send</button></form></main>';

$form = $parse($contact);
$names = array_map(static fn (DOMElement $control): string => $control->getAttribute('name'), FormControlClassifier::controlElements($form));
$assert(! in_array('hp', $names, true) && array( 'name', 'email', 'message' ) === array_values(array_filter($names)), 'aria-hidden ancestor trap is not an authored control', json_encode($names));
$copy = FormControlClassifier::honeypotCopyElements($form);
$assert(in_array($form->getElementsByTagName('label')->item(3), $copy, true), 'aria-hidden ancestor trap owns its label');
$assert(in_array($form->getElementsByTagName('div')->item(0), $copy, true), 'aria-hidden ancestor trap owns its trap-only wrapper');

$result = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array( 'index.html' => $contact ),
))->toArray();
$readable = $result['fallbacks'][0]['readable_blocks'] ?? array();
$assert(array() !== $readable, 'contact form yields readable blocks');
$blockNames = array();
$emptyGroups = 0;
$walk = static function (array $blocks) use (&$walk, &$blockNames, &$emptyGroups): void {
    foreach ( $blocks as $block ) {
        $blockNames[] = $block['blockName'] ?? null;
        $inner = $block['innerBlocks'] ?? array();
        if ( 'core/group' === ( $block['blockName'] ?? null ) && array() === $inner ) {
            ++$emptyGroups;
        }
        $walk($inner);
    }
};
$walk($readable);
$assert(! in_array('core/html', $blockNames, true), 'aria-hidden wrapper trap leaves no core/html in readable blocks', json_encode($blockNames));
$assert(! str_contains((string) json_encode($readable), 'Leave empty'), 'no trap label text reaches readable blocks');
$assert(1 === count(array_keys($blockNames, 'core/group', true)) && 0 === $emptyGroups, 'the trap wrapper container is gone from readable blocks', json_encode($blockNames));
$layoutGraph = (string) json_encode($result['fallbacks'][0]['layout_graph'] ?? array());
$assert(! str_contains($layoutGraph, 'Leave empty') && ! str_contains($layoutGraph, '"hp"'), 'no trap markup reaches the form layout graph');

// Choice carriers under the same aria-hidden wrapper keep their behaviour.
$form = $parse('<form>' . str_replace('type="text"', 'type="checkbox"', $wrapper) . '</form>');
$assert(array() === FormControlClassifier::honeypotCopyElements($form), 'aria-hidden wrapper does not make a choice carrier a trap');

// The ancestor walk is bounded by the form.
$form = $parse('<div aria-hidden="true"><form><input type="text" name="hp" tabindex="-1"></form></div>');
$assert(array() === FormControlClassifier::honeypotCopyElements($form), 'aria-hidden outside the form does not make a text trap');

fwrite(STDOUT, "invisible unreachable text trap: {$failures} failures\n");
exit($failures > 0 ? 1 : 0);
