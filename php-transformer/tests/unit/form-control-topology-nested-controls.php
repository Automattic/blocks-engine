<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$formFrom = static function (string $html): DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $form = $document->getElementsByTagName('form')->item(0);
    if ( $form instanceof DOMElement ) {
        return $form;
    }
    throw new RuntimeException('No form parsed');
};

// Custom choice widgets carry the native value control inside the ARIA control
// the visitor clicks. Both are flat form controls.
$choice = static fn (string $role, string $type, string $name, string $value): string => '<button type="button" role="' . $role . '" aria-checked="false">'
    . '<input type="' . $type . '" name="' . $name . '" value="' . $value . '" tabindex="-1" aria-hidden="true" style="position:absolute;opacity:0">'
    . '<span>' . $value . '</span></button>';

$form = $formFrom(
    '<form><label><span>Name</span><input name="guest"></label>'
    . '<div role="radiogroup">' . $choice('radio', 'radio', 'attending', 'Yes') . $choice('radio', 'radio', 'attending', 'No') . '</div>'
    . '<div role="group"><div>' . $choice('checkbox', 'checkbox', 'meal', 'Fish') . '</div><div>' . $choice('checkbox', 'checkbox', 'meal', 'Vegetarian') . '</div></div>'
    . '<button type="submit">Send</button></form>'
);
$controls = FormControlClassifier::controlElements($form);
$assert(10 === count($controls), 'nested-native-controls-are-flat-controls');

$builder = new FormControlTopologyBuilder();
$topology = $builder->build($form);
$valid = true;
try {
    FormControlTopologyBuilder::assertValid($topology, count($controls));
} catch ( InvalidArgumentException $e ) {
    $valid = false;
}
$assert($valid, 'topology-with-nested-controls-is-valid');
$assert(false === $topology['truncated'], 'topology-is-not-truncated');

$controlNodes = array_values(array_filter($topology['nodes'], static fn (array $node): bool => 'control' === $node['kind']));
$indexes = array_map(static fn (array $node): int => $node['control'], $controlNodes);
$assert(range(0, count($controls) - 1) === $indexes, 'every-control-appears-once-in-document-order');

// A nested control follows its carrier as a sibling leaf under the same wrapper.
$byControl = array_column($controlNodes, null, 'control');
foreach ( $controls as $index => $control ) {
    if ( 'input' !== strtolower($control->tagName) || 'button' !== strtolower($control->parentNode->nodeName) ) {
        continue;
    }
    $carrier = $byControl[$index - 1] ?? array();
    $assert(($carrier['parent'] ?? null) === ($byControl[$index]['parent'] ?? false), 'nested-control-shares-carrier-parent-' . $index);
    $assert(($carrier['order'] ?? -1) + 1 === ($byControl[$index]['order'] ?? null), 'nested-control-follows-carrier-' . $index);
    $assert(($carrier['depth'] ?? -1) === ($byControl[$index]['depth'] ?? null), 'nested-control-shares-carrier-depth-' . $index);
}

// A carrier control is not a wrapper of the control it carries, matching the
// topology, which never places a control under another control.
foreach ( $builder->exclusiveWrapperAncestors($form) as $index => $ancestors ) {
    foreach ( $ancestors as $ancestor ) {
        $assert(! FormControlClassifier::isControlElement($ancestor), 'exclusive-wrapper-is-not-a-control-' . $index);
    }
}

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form control topology nested control tests: ' . $assertions . " passed\n";
