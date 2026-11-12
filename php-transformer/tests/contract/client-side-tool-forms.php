<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$calculator = '<form id="zakat-form" novalidate><label>Cash <input type="number" name="cash"></label><label>Gold (g) <input type="number" name="gold"></label><button type="submit">Calculate</button><button type="reset">Reset</button></form><div id="zakat-result" role="status" aria-live="polite"></div>';
$cases = array(
    'issue reduction' => array($calculator, 1, 'client_side_tool'),
    'inline validation reduction' => array('<form id="formPedido" novalidate><div class="field"><label for="n">Nombre completo *</label><input id="n" name="nombre" required><div class="error-msg" aria-live="polite"></div></div><div class="field"><label for="t">Celular *</label><input id="t" name="telefono" type="tel" required><div class="error-msg" aria-live="polite"></div></div><fieldset><legend>Pago</legend><label><input type="radio" name="pago" value="transferencia"> Transferencia</label><label><input type="radio" name="pago" value="whatsapp"> WhatsApp</label></fieldset><button type="submit">Enviar pedido</button></form>', 1, 'unknown'),
    'search reduction' => array('<form id="dua-form"><label for="q">Search duas</label><input id="q" type="search" placeholder="Search…"><button type="submit">Search</button></form><p role="status" aria-live="polite"></p><ul><li>Dua one</li></ul>', 1, 'client_side_tool'),
    'three forms' => array('<form id="dates"><label>Date <input type="date" name="date"></label><button type="submit">Calculate</button><div role="status"></div></form>' . $calculator . '<form id="choice"><label>Period<select name="period"><option>Month</option></select></label><button>Calculate</button><output></output></form>', 3, 'client_side_tool'),
    'GET results' => array('<form action="/results/" method="get" onsubmit="return true"><select name="sort"><option>Price</option></select><button>Filter</button></form>', 1, 'filter_sort'),
    'navigating select' => array('<form><select name="sort" onchange="location.href=this.value"><option value="/results/">Price</option></select></form><div aria-live="polite"></div>', 1, 'filter_sort'),
);
// Each exclusion must work independently of the other feedback signals.
foreach (array('aria-describedby', 'aria-errormessage') as $attribute) {
    $cases[$attribute . ' feedback'] = array('<form><input name="first" ' . $attribute . '="other note"><input name="second"><button>Send</button><output id="note"></output></form>', 1, 'unknown');
}
foreach (array('error', 'invalid', 'validation', 'hint', 'help', 'feedback') as $token) {
    foreach (array('class', 'id') as $attribute) {
        $cases[$attribute . ' ' . $token . ' identity'] = array('<form><input name="first"><input name="second"><button>Send</button><div ' . $attribute . '="field-' . $token . '-msg" role="status"></div></form>', 1, 'unknown');
    }
}
$cases['anonymous field wrapper'] = array('<form><div><label>Name<input name="first"></label><div><span aria-live="assertive"></span></div><input type="hidden" name="token"><input type="submit"></div><input name="second"><button>Send</button></form>', 1, 'unknown');
foreach (array('radio', 'checkbox') as $type) {
    $cases[$type . ' group feedback'] = array('<form><fieldset><input type="' . $type . '" name="option"><input type="' . $type . '" name="option"><div role="status"></div></fieldset><input name="first"><button>Send</button></form>', 1, 'unknown');
}
// A live form region announces its own submission, not a tool result.
$cases['live form region'] = array('<form aria-live="polite"><input name="first"><input name="second"><button type="submit">Send</button></form>', 1, 'unknown');
$cases['multiple fields result'] = array('<form><div><input name="first"><input name="second"><output></output></div><button>Calculate</button></form>', 1, 'client_side_tool');
$cases['distinct checkbox groups result'] = array('<form><div><input type="checkbox" name="first"><input type="checkbox" name="second"><output></output></div><button>Calculate</button></form>', 1, 'client_side_tool');
$cases['single wrapped date input'] = array('<form><label>Date<input type="date" name="date"></label><button>Calculate</button><output></output></form>', 1, 'client_side_tool');
$cases['identity substring is not a token'] = array('<form><input name="first"><input name="second"><output id="helper-errorless"></output></form>', 1, 'client_side_tool');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
foreach ($cases as $name => [$html, $count, $intent]) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<html><body>' . $html . '<script src="/site.js"></script></body></html>', 'site.js' => 'document.addEventListener("submit",function(e){e.preventDefault();});')))->toArray();
    $forms = array_values(array_filter($result['diagnostics'], static fn (array $finding): bool => 'html_form_fallback' === ($finding['code'] ?? null)));
    $assert(count($forms) === $count, $name . ': one diagnostic per form, got ' . count($forms));
    foreach ($forms as $form) {
        $assert($intent === ($form['form_intent'] ?? ''), $name . ': intent');
        if ('client_side_tool' !== $intent) {
            $assert('client_script_execution' !== ($form['runtime_requirement'] ?? ''), $name . ': no tool runtime requirement');
            continue;
        }
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
