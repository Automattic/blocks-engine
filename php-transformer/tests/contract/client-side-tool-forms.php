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
    $forms = array_values(array_filter($result['diagnostics'], static fn (array $finding): bool => 'html_form_fallback' === ($finding['code'] ?? null)));
    $assert(count($forms) === $count, $name . ': one diagnostic per form, got ' . count($forms));
    foreach ($forms as $form) {
        $assert($intent === ($form['form_intent'] ?? ''), $name . ': intent');
        if ('client_side_tool' !== $intent) continue;
        foreach (array('materialization_path', 'runtime_requirement', 'source_path', 'selector', 'reason_code') as $key) $assert('' !== trim((string) ($form[$key] ?? '')), $name . ': ' . $key);
        $assert('preserved_runtime_island' === $form['materialization_path'], $name . ': materialization path');
        $assert('preserved_runtime_island' === $form['loss_class'], $name . ': loss class');
        $assert('acceptable_preservation' === $form['acceptability'], $name . ': acceptability');
        $assert('fallback_metadata_with_readable_blocks' === $form['preservation_strategy'], $name . ': strategy');
        $assert(!empty($form['controls']), $name . ': editable controls');
        $assert(str_contains(json_encode($form['required_scripts'] ?? array()), 'site.js'), $name . ': source script required');
    }
}
echo "Client-side tool forms contract passed.\n";
