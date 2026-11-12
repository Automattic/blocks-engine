<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$calculator = '<form id="zakat-form" novalidate><label>Cash <input type="number" name="cash"></label><label>Gold (g) <input type="number" name="gold"></label><button type="submit">Calculate</button><button type="reset">Reset</button></form><div id="zakat-result" role="status" aria-live="polite"></div>';
$cases = array(
    'issue reduction' => array($calculator, 1, 'client_side_tool'),
    'three forms' => array('<form id="dates"><label>Date <input type="date" name="date"></label><button type="submit">Calculate</button><div role="status"></div></form>' . $calculator . '<form id="choice"><label>Period<select name="period"><option>Month</option></select></label><button>Calculate</button><output></output></form>', 3, 'client_side_tool'),
    'GET results' => array('<form action="/results/" method="get" onsubmit="return true"><select name="sort"><option>Price</option></select><button>Filter</button></form>', 1, 'filter_sort'),
    'navigating select' => array('<form><select name="sort" onchange="location.href=this.value"><option value="/results/">Price</option></select></form><div aria-live="polite"></div>', 1, 'filter_sort'),
);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
foreach ($cases as $name => [$html, $count, $intent]) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><body>' . $html . '<script src="/site.js"></script></body></html>', 'site.js' => 'document.addEventListener("submit",function(e){e.preventDefault();});')))->toArray();
    $forms = array();
    $walk = static function (array $value) use (&$walk, &$forms, $intent, $assert, $name): void {
        if ('client_side_tool' === $intent) $assert('core/html' !== ($value['blockName'] ?? ''), $name . ': no core/html');
        if ('html_form_fallback' === ($value['diagnostic_code'] ?? '') && isset($value['controls'])) $forms[$value['selector']] = $value;
        foreach ($value as $child) if (is_array($child)) $walk($child);
    };
    $walk($result);
    $assert(count($forms) === $count, $name . ': one diagnostic per form, got ' . count($forms));
    foreach ($forms as $form) {
        $assert($intent === ($form['form_intent'] ?? ''), $name . ': intent');
        if ('client_side_tool' !== $intent) continue;
        $assert('unsupported_html_fallback' === $form['type'], $name . ': diagnostic type');
        foreach (array('materialization_path', 'runtime_requirement', 'id', 'source_path', 'selector', 'reason_code') as $key) $assert('' !== trim((string) ($form[$key] ?? '')), $name . ': ' . $key);
        $assert('preserved_runtime_island' === $form['loss_class'], $name . ': loss class');
        $assert('acceptable_preservation' === $form['acceptability'], $name . ': acceptability');
        $assert('fallback_metadata_with_readable_blocks' === $form['preservation_strategy'], $name . ': strategy');
        $payload = $form['source_html_preview'] ?? $form['html_excerpt'] ?? '';
        $assert('' !== trim($payload) && strlen($payload) <= 8192, $name . ': bounded payload');
        $assert(1 !== preg_match('/<\s*(?:script|style)\b|\bon[a-z]+\s*=|(?:javascript|data)\s*:|\bsrcdoc\s*=|<!--\s*wp:(?:html|freeform)\b/i', $payload), $name . ': safe payload');
        $assert(!in_array($form['block_name'] ?? '', array('core/html', 'core/freeform'), true), $name . ': native diagnostic');
        $assert(!empty($form['readable_blocks']) && !empty($form['controls']), $name . ': editable controls');
        $assert(str_contains(json_encode($form['required_scripts'] ?? array()), 'site.js'), $name . ': source script required');
    }
}
echo "Client-side tool forms contract passed.\n";
