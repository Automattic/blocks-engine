<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Native summary inherits through details, never from its label descendants.
// Source classes/styles introduce a content carrier without changing that rule.
$fixture = <<<'HTML'
<style>body{font-family:PPMori,sans-serif;line-height:1.5;color:#111}.card{font-family:"DM Sans";line-height:1.6;color:#383838}.toggle{padding-inline-start:1.5em}summary{display:list-item}%s</style>
<div class="card"><details class="toggle"><summary%s>Class rules <span class="textColor" style="--text-color: #E04F00;color: var(--text-color)">(click)</span></summary><p>Body text.</p></details></div>
HTML;
$own = array('color' => 'red', 'font-family' => 'Georgia', 'line-height' => '2');
$inherit = array_fill_keys(array_keys($own), 'inherit');
$cases = array(
    'inherited only (issue fixture)' => array('', '', null),
    'inherited with content carrier' => array('', ' class="label"', null),
    'own element rule' => array('summary{color:red;font-family:Georgia;line-height:2}', '', $own),
    'own class rule with content carrier' => array('.label{color:red;font-family:Georgia;line-height:2}', ' class="label"', $own),
    'own inline style with content carrier' => array('', ' style="color:red;font-family:Georgia;line-height:2"', $own),
    'explicit inheritance stays live' => array('summary{color:inherit;font-family:inherit;line-height:inherit}', '', $inherit),
);
foreach ($cases as $name => [$rule, $attributes, $expected]) {
    $result = (new HtmlTransformer())->transform(sprintf($fixture, $rule, $attributes))->toArray();
    $blocks = (string) ($result['serialized_blocks'] ?? '');
    $css = implode('', array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));
    if (!preg_match('/<summary>(.*?)<\/summary>/s', $blocks, $summary)) {
        throw new RuntimeException($name . ': missing native summary');
    }
    if (!preg_match('/<(?:span|mark)\b[^>]*class="textColor"[^>]*style="[^"]*color:var\(--text-color\)[^"]*--text-color:#E04F00[^>]*>\(click\)<\/(?:span|mark)>/i', $summary[1])) {
        throw new RuntimeException($name . ': descendant must keep its own colour: ' . $summary[1]);
    }
    // Inspect every emitted direct-summary and content-carrier rule, including
    // preset-mapped twins. Neither target may acquire descendant typography.
    preg_match_all('/[^{}]*\.blocks-engine-disclosure-summary-[^{}]*>\s*summary[^{}]*\{([^{}]*)\}/', $css, $matches);
    $found = array();
    foreach ($matches[1] as $declarations) {
        foreach (explode(';', $declarations) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (2 !== count($parts)) continue;
            [$property, $value] = array_map('trim', $parts);
            if (!in_array($property, array('color', 'font-family', 'line-height'), true)) continue;
            $value = preg_replace('/\s*!important$/', '', $value);
            if (null === $expected) {
                if ('inherit' !== $value) throw new RuntimeException($name . ': baked inherited ' . $property . ':' . $value);
            } elseif ($expected[$property] !== $value) {
                throw new RuntimeException($name . ': unexpected ' . $property . ':' . $value);
            }
            $found[$property] = true;
        }
    }
    if (null !== $expected && count($found) !== count($expected)) {
        throw new RuntimeException($name . ': own summary typography was not carried');
    }
}
echo "Native summary typography contract passed\n";
