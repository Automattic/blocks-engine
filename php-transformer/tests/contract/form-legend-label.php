<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = <<<'HTML'
<!doctype html><html><body><main><form>
<fieldset class="form-item fields phone" id="ph">
<legend id="ph-w"><div class="title"><div><span>Phone</span><span class="description required">(required)</span></div></div><p class="description">Phone</p></legend>
<div class="field"><label class="caption" for="ph-in"><div><div class="caption-text"></div></div></label><input id="ph-in" aria-labelledby="ph-w" aria-required="true" type="text" name="fullPhone" placeholder="(###) ###-####"></div>
</fieldset>
<div class="form-item field email required"><label for="em" class="title"><div><div><span>Email</span><span class="description required">(required)</span></div></div></label><p class="description">Email</p><input id="em" aria-required="true" type="email" name="email"></div>
<button type="submit">Submit</button>
</form></main></body></html>
HTML;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$controls = array();
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('forms' !== ($declaration['type'] ?? null)) continue;
    foreach ($declaration['payload']['entities'] ?? array() as $entity) {
        foreach ($entity['controls'] ?? array() as $control) {
            $controls[$control['name'] ?? ''] = $control;
        }
    }
}
foreach (array('fullPhone' => 'Phone', 'email' => 'Email') as $name => $label) {
    if ($label !== ($controls[$name]['label'] ?? null) || true !== ($controls[$name]['required'] ?? null)) {
        fwrite(STDERR, 'Expected title-only required field ' . $name . '; got ' . json_encode($controls[$name] ?? null) . "\n");
        exit(1);
    }
}
$blocks = $result['serialized_blocks'];
// The fieldset caption retains both its title and description, but only once.
// The control summaries separately retain their visible required markers.
$text = strip_tags(preg_replace('/<!--.*?-->/s', '', $blocks) ?? '');
if (3 !== substr_count($text, 'Phone') || 3 !== substr_count($text, '(required)')) {
    fwrite(STDERR, 'Expected one legend caption plus the control label; got ' . $blocks . "\n");
    exit(1);
}
echo "Legend-labelled fields share label normalization and emit one caption.\n";
