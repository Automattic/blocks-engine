<?php
/**
 * A script that inserts DOM nodes references no asset; one that builds or
 * assigns a URL does. Only the second leaves dynamic client assets unproven,
 * which a materializer may reject.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0; $passed = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passed): void {
    if ($condition) { ++$passed; return; }
    ++$failures; fwrite(STDERR, "FAIL: {$message}\n");
};
$status = static function (string $script, bool $inline): array {
    $page = '<!doctype html><html><body><main><button class="copy">Copy</button><ul class="list"><li>One</li></ul></main>'
        . ($inline ? '<script>' . $script . '</script>' : '<script src="js/site.js"></script>') . '</body></html>';
    $files = array('index.html' => $page);
    if (!$inline) $files['js/site.js'] = $script;
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    $codes = array_column(array_filter($plan['diagnostics'], 'is_array'), 'code');
    return array($plan['reference_semantics']['dynamic_client_assets']['status'] ?? null, in_array('wordpress_site_plan_script_dynamic_references', $codes, true));
};

$insertion = <<<'JS'
document.querySelector('.copy').addEventListener('click', function () {
  var ta = document.createElement('textarea');
  ta.value = 'copied'; document.body.appendChild(ta); ta.select();
  document.execCommand('copy'); document.body.removeChild(ta);
});
var list = document.querySelector('.list'), item = document.createElement('li');
item.textContent = 'Zero'; list.insertBefore(item, list.firstChild);
JS;
foreach (array(false, true) as $inline) {
    $where = $inline ? 'An inline script' : 'A linked script';
    [$state, $flagged] = $status($insertion, $inline);
    $assert('proven' === $state && !$flagged, "{$where} that only inserts DOM nodes leaves dynamic client assets proven.");
    foreach (array(
        'an injected script' => 'var s = document.createElement("script"); document.head.appendChild(s);',
        'an assigned src' => 'var i = new Image(); i.src = "img/" + name + ".png"; document.body.appendChild(i);',
        'a set src attribute' => 'var i = document.createElement("img"); i.setAttribute("src", "img/a.png"); document.body.appendChild(i);',
        'a dynamic import' => 'import("./chunk.js");',
        'a constructed URL' => 'fetch(new URL("data.json", location.href));',
    ) as $label => $script) {
        [$state, $flagged] = $status($script, $inline);
        $assert('not_proven' === $state && $flagged, "{$where} with {$label} leaves dynamic client assets unproven.");
    }
}

if (0 !== $failures) { fwrite(STDERR, "Script DOM insertion proof: {$failures} failed\n"); exit(1); }
echo "Script DOM insertion proof: {$passed} passed\n";
