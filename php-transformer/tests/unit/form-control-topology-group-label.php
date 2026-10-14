<?php
declare(strict_types=1);

/**
 * A choice group built from generic elements names itself with an ARIA group
 * role and accessible name instead of a fieldset legend. The control topology
 * carries that role and name on the group wrapper, so a consumer can label the
 * group as one field instead of flattening it into anonymous choices.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormControlTopologyBuilder;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = 'FAIL [' . $label . ']';
    }
};

$topology = static function (string $html): array {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $form = $document->getElementsByTagName('form')->item(0);
    if ( ! $form instanceof DOMElement ) {
        throw new RuntimeException('No form parsed');
    }
    return (new FormControlTopologyBuilder())->build($form);
};
$groupNode = static function (array $topology): array {
    foreach ( $topology['nodes'] as $node ) {
        if ( isset($node['group_role']) ) {
            return $node;
        }
    }
    return array();
};

$pills = static fn (string $attributes, string $role = 'radio', string $type = 'radio'): string => '<form><section><div><span id="attend-caption">Will you attend?</span></div>'
    . '<div role="' . $role . 'group" ' . $attributes . '>'
    . '<button type="button" role="' . $role . '"><input type="' . $type . '" name="attending" value="Yes" aria-hidden="true" tabindex="-1"><span>Yes</span></button>'
    . '<button type="button" role="' . $role . '"><input type="' . $type . '" name="attending" value="No" aria-hidden="true" tabindex="-1"><span>No</span></button>'
    . '</div></section><input type="text" name="name"></form>';

$labelled = $topology($pills('aria-label="Will you attend?"'));
$node = $groupNode($labelled);
$assert('radiogroup' === ($node['group_role'] ?? null), 'a radiogroup wrapper reports its ARIA group role');
$assert('Will you attend?' === ($node['group_label'] ?? null), 'a radiogroup wrapper carries its aria-label as the group label');
$assert('div' === ($node['tag'] ?? null) && ! isset($node['legend']) && ! isset($node['fieldset_semantics']), 'an ARIA group is not reported as a fieldset');

$byReference = $groupNode($topology($pills('aria-labelledby="attend-caption" aria-label="ignored"')));
$assert('Will you attend?' === ($byReference['group_label'] ?? null), 'aria-labelledby names the group ahead of aria-label');

$checkboxes = $groupNode($topology(str_replace('role="checkboxgroup"', 'role="group"', $pills('aria-label="Which events?"', 'checkbox', 'checkbox'))));
$assert('group' === ($checkboxes['group_role'] ?? null) && 'Which events?' === ($checkboxes['group_label'] ?? null), 'a generic group of checkboxes carries its role and name');

$unnamed = $groupNode($topology($pills('')));
$assert('radiogroup' === ($unnamed['group_role'] ?? null) && ! array_key_exists('group_label', $unnamed), 'an unnamed group reports its role without a label');

$oversized = $groupNode($topology($pills('aria-label="' . str_repeat('x', 201) . '"')));
$assert(! array_key_exists('group_label', $oversized), 'an oversized group name is dropped, not truncated');

$plain = $topology(str_replace('role="radiogroup"', '', $pills('aria-label="Will you attend?"')));
$assert(array() === $groupNode($plain), 'a wrapper without a group role carries no group label');

// The producer contract admits the new keys and rejects a label without a role.
$valid = true;
try {
    FormControlTopologyBuilder::assertValid($labelled, 5);
} catch ( InvalidArgumentException ) {
    $valid = false;
}
$assert($valid, 'a topology carrying a group role and label satisfies the producer contract');
$orphanLabel = $labelled;
foreach ( $orphanLabel['nodes'] as &$candidate ) {
    if ( isset($candidate['group_role']) ) {
        unset($candidate['group_role']);
    }
}
unset($candidate);
$rejected = false;
try {
    FormControlTopologyBuilder::assertValid($orphanLabel, 5);
} catch ( InvalidArgumentException ) {
    $rejected = true;
}
$assert($rejected, 'a group label without an ARIA group role violates the producer contract');

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo 'Form control topology group label tests: ' . $assertions . " passed\n";
