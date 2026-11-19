<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Contract scripts are discovered automatically by tests/run.php.
$cases = array(
    'consent' => array('attributes' => '', 'control' => '<input id="consent" type="checkbox" name="consent"><label for="consent">Consent</label>', 'group' => true),
    'row' => array('attributes' => '', 'control' => '<label for="email">Email</label><input id="email" type="email" name="email">', 'group' => true),
    // A data attribute nothing on the page addresses is inert, value or not:
    // the Group drops it instead of keeping a generated shell to carry it.
    'data-row' => array('attributes' => ' data-x="keep"', 'control' => '<label for="extra">Extra</label><input id="extra" name="extra">', 'group' => true, 'carried' => false),
    // A Group carries an id as its anchor; only attributes it cannot carry
    // need the generated shell.
    'id-row' => array('attributes' => ' id="keep"', 'control' => '<label for="other">Other</label><input id="other" name="other">', 'group' => true),
);
$html = '<html><body><form method="post">';
foreach ($cases as $class => $case) $html .= '<div class="' . $class . '"' . $case['attributes'] . '>' . $case['control'] . '</div>';
$html .= '<div class="outer"><div class="inner"><label for="nested">Nested</label><input id="nested" name="nested"></div></div>';
$html .= '<label for="phone">Phone</label><div class="phone-root"><div class="phone-frame"><button type="button" aria-label="Select a country code" aria-haspopup="listbox">+1</button><input id="phone" name="phone" type="tel"></div></div>';
$html .= '<label class="agree" for="agree"><input id="agree" type="checkbox" name="agree" required> I agree</label>';
$html .= '<button type="submit">Send</button></form></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$form = null;
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('forms' === ($declaration['type'] ?? null)) $form = $declaration['payload']['entities'][0] ?? null;
}
$assert(null !== $form, 'Compiler emits the forms runtime declaration.');
$assert(array('consent', 'email', 'extra', 'other') === array_column(array_slice($form['controls'] ?? array(), 0, 4), 'name'), 'Runtime controls retain source order and names.');
$readable = array();
$find = static function (array $value) use (&$find, &$readable): void {
    if ('html_form_fallback' === ($value['diagnostic_code'] ?? null)) $readable = $value['readable_blocks'] ?? array();
    foreach ($value as $child) if (is_array($child)) $find($child);
};
$find($result);
$blocks = array();
$walk = static function (array $tree) use (&$walk, &$blocks): void {
    foreach ($tree as $block) {
        $classes = ($block['attrs']['className'] ?? '') . ' ' . ($block['attrs']['wrappers'][0]['attributes']['class'] ?? '');
        foreach (preg_split('/\s+/', trim($classes)) ?: array() as $class) $blocks[$class] = $block;
        $walk($block['innerBlocks'] ?? array());
    }
};
$walk($readable);
foreach ($cases as $class => $case) {
    $block = $blocks[$class] ?? array();
    $assert($case['group'] ? 'core/group' === ($block['blockName'] ?? null) : str_ends_with($block['blockName'] ?? '', '/layout-shell'), $class . ' has the expected wrapper block.');
    $assert(1 === count($block['innerBlocks'] ?? array()), $class . ' encloses one control.');
    $assert(isset($block['innerBlocks'][0]['form_control_index']), $class . ' retains the SSI control index.');
    if ($case['group']) {
        $assert(!str_contains(json_encode($block), 'layout-shell'), $class . ' contains no layout-shell.');
        $assert(str_contains($block['innerContent'][0] ?? '', $class), $class . ' survives in readable wrapper markup.');
        $assert(!isset($block['innerBlocks'][0]['_form_field_box']), $class . ' does not duplicate the consumed field box.');
        $assert('' === $case['attributes'] || false === ($case['carried'] ?? true) || str_contains($block['innerContent'][0] ?? '', $case['attributes']), $class . ' retains its source attribute in readable markup.');
    } else {
        $assert(str_contains($block['innerContent'][0] ?? '', $case['attributes']), $class . ' retains its source attribute in readable markup.');
    }
}
// Consecutive source boxes must remain independently editable through the
// public artifact compiler, without changing their parent-child edge.
$outer = $blocks['outer'] ?? array();
$inner = $outer['innerBlocks'][0] ?? array();
$assert('core/group' === ($outer['blockName'] ?? null) && 'core/group' === ($inner['blockName'] ?? null), 'Class-only form chains use native groups.');
$assert(str_contains($inner['attrs']['className'] ?? '', 'inner'), 'The inner source box remains directly inside the outer box.');
$assert(isset($inner['innerBlocks'][0]['form_control_index']), 'The native chain retains its control binding.');
$assert(!str_contains(json_encode($outer), 'layout-shell'), 'No generated shell remains anywhere in the native chain.');
$assert(str_contains($outer['innerContent'][0] ?? '', 'wp-block-group outer')
    && str_contains($inner['innerContent'][0] ?? '', 'wp-block-group inner'), 'Both source classes survive in saved native wrapper markup.');
$assert(!isset($inner['innerBlocks'][0]['_form_field_box']), 'The consumed inner field box is not duplicated below the native chain.');
foreach (array($outer, $inner) as $frame) {
    $assert(str_contains($frame['attrs']['className'] ?? '', 'blocks-engine-css-owned-layout')
        && str_contains($frame['attrs']['className'] ?? '', 'blocks-engine-css-owned-flow'), 'Every native form frame preserves source flow without introducing WordPress block gaps.');
}
$phone = $blocks['phone-root'] ?? array();
$assert(str_ends_with($phone['blockName'] ?? '', '/layout-shell'), 'Composite phone frames retain the provider shell transport.');
$assert(2 === count($phone['attrs']['wrappers'] ?? array()), 'The phone shell transports both source frames together.');
// A class-only label framing its own checkbox is the control's field box.
$agree = $blocks['agree'] ?? array();
$assert('core/group' === ($agree['blockName'] ?? null) && !str_contains(json_encode($agree), 'layout-shell'), 'A class-only label field box becomes a native group.');
$assert(isset($agree['innerBlocks'][0]['form_control_index']) && str_contains($agree['innerContent'][0] ?? '', 'wp-block-group agree'), 'The label field box group keeps its class and the SSI control index.');
echo "Class-only form wrapper group contract passed.\n";
