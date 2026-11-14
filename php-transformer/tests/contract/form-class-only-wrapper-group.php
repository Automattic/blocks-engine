<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Contract scripts are discovered automatically by tests/run.php.
$cases = array(
    'consent' => array('attributes' => '', 'control' => '<input id="consent" type="checkbox" name="consent"><label for="consent">Consent</label>', 'group' => true),
    'row' => array('attributes' => '', 'control' => '<label for="email">Email</label><input id="email" type="email" name="email">', 'group' => true),
    'data-row' => array('attributes' => ' data-x="keep"', 'control' => '<label for="extra">Extra</label><input id="extra" name="extra">', 'group' => false),
    'id-row' => array('attributes' => ' id="keep"', 'control' => '<label for="other">Other</label><input id="other" name="other">', 'group' => false),
);
$html = '<html><body><form method="post">';
foreach ($cases as $class => $case) $html .= '<div class="' . $class . '"' . $case['attributes'] . '>' . $case['control'] . '</div>';
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
    } else {
        $assert(str_contains($block['innerContent'][0] ?? '', $case['attributes']), $class . ' retains its source attribute in readable markup.');
    }
}
// A class-only label framing its own checkbox is the control's field box.
$agree = $blocks['agree'] ?? array();
$assert('core/group' === ($agree['blockName'] ?? null) && !str_contains(json_encode($agree), 'layout-shell'), 'A class-only label field box becomes a native group.');
$assert(isset($agree['innerBlocks'][0]['form_control_index']) && str_contains($agree['innerContent'][0] ?? '', 'wp-block-group agree'), 'The label field box group keeps its class and the SSI control index.');
echo "Class-only form wrapper group contract passed.\n";
