<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #4620: browser-verified fixture. :where() contributes no specificity.
$cases = array(
    'shorthand loses' => array('.w-p .button', 'background', '', '#E4F2D4'),
    'shorthand loses when declared first' => array('.w-p .button', 'background', '', '#E4F2D4', 'first'),
    'layered shorthand loses' => array('#main .w-p .button', 'background', '', '#E4F2D4', 'layer'),
    'layered important shorthand wins' => array('.w-p .button', 'background', ' !important', 'transparent', 'layer'),
    'shorthand wins' => array('#main .w-p .button', 'background', '', 'transparent'),
    'longhand control' => array('.w-p .button', 'background-color', '', '#E4F2D4'),
    'important shorthand wins' => array('.w-p .button', 'background', ' !important', 'transparent'),
    'equal specificity later wins' => array('#main :where(.button)', 'background', '', 'transparent'),
);
// These families use the same winner selector. Their existing expansion and
// mapping helpers must keep the winning component without losing other sides.
foreach (array(
    'padding' => array('padding: 2px', 'padding-left: 24px', array('spacing', 'padding', 'left'), '24px'),
    'border' => array('border: 2px solid #112233', 'border-left-width: 4px', array('border', 'left', 'width'), '4px'),
    'overlapping border shorthands' => array('border-width: 2px', 'border-top: 4px solid #112233', array('border', 'top', 'width'), '4px'),
    'font' => array('font: 12px Arial', 'font-size: 24px', array('typography', 'fontSize'), '24px'),
) as $family => [$shorthand, $longhand, $path, $value]) {
    $cases[$family . ' component wins'] = array('.w-p .button', 'background', '', '#E4F2D4', '',
        '#main .btn { ' . $longhand . ' } .w-p .button { ' . $shorthand . ' }', $path, $value);
}
// Button margins belong to the core/buttons shell, not its painted link.
$cases['margin component wins'] = array('.w-p .button', 'background', '', '#E4F2D4', '',
    '#main .btn { margin-top: 24px } .w-p .button { margin: 2px }', array('spacing', 'margin', 'top'), '24px', 'buttons');
foreach ($cases as $label => $case) {
    [$selector, $property, $importance, $expected] = $case;
    $variant = $case[4] ?? '';
    $competingRule = $selector . ' { ' . $property . ': transparent' . $importance . ' }';
    if ('layer' === $variant) $competingRule = '@layer reset { ' . $competingRule . ' }';
    $extraCss = $case[5] ?? '';
    $html = '<style>
a { background: transparent }
#main :where(.root) { --b: #E4F2D4 }
' . ('first' === $variant ? $competingRule : '') . '
#main :where(.btn) { background-color: var(--b); padding: 8px 16px; border-radius: 99px }
' . ('first' !== $variant ? $competingRule : '') . $extraCss . '
</style>
<div id="main"><div class="root"><header><div class="w-p"><a class="btn button" href="#c">Contact Us</a></div></header></div></div>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $serialized = (string) ($result['serialized_blocks'] ?? '');
    preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $serialized, $matches);
    $found = false;
    $shellStyle = array();
    if ('buttons' === ($case[8] ?? '')) {
        preg_match('/<!-- wp:buttons (\{.*?\}) -->/', $serialized, $shell);
        $shellStyle = json_decode($shell[1] ?? '{}', true)['style'] ?? array();
    }
    foreach ($matches[1] as $attributes) {
        $attrs = json_decode($attributes, true);
        if ($expected !== ($attrs['style']['color']['background'] ?? null)) continue;
        $component = 'buttons' === ($case[8] ?? '') ? $shellStyle : ($attrs['style'] ?? array());
        foreach ($case[6] ?? array() as $key) $component = $component[$key] ?? null;
        if (isset($case[7]) && $component !== $case[7]) continue;
        $found = true;
    }
    if (!$found) throw new RuntimeException($label . ': expected core/button style.color.background = ' . $expected . "\n" . $serialized);
}
echo "Shorthand and longhand button cascade contract passed.\n";
