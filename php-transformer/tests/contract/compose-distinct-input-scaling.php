<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$baseline = null;
foreach (array(4, 16, 64) as $count) {
    $files = array();
    $css = '@namespace svg url("http://www.w3.org/2000/svg");.group>button{color:#123456}.sample:hover{color:#abcdef}';
    for ($i = 0; $i < 40; ++$i) $css .= '.tail-' . $i . '{color:#123456;padding:2px}';
    for ($i = 0; $i < $count; ++$i) {
        $files[] = array('path' => 'p' . $i . '.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><title>Page</title><link rel="stylesheet" href="base.css"></head><body><header><p>Shared chrome</p></header><main><div class="group"><button class="sample">Send</button></div></main><footer><p>Shared footer</p></footer></body></html>');
    }
    $files[] = array('path' => 'base.css', 'kind' => 'css', 'content' => $css);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'p0.html', 'files' => $files))->toArray();
    $work = array();
    foreach (array('rule_unit_collections', 'route_selector_matches', 'marker_carriers', 'state_matches') as $name) {
        if (!isset($result['metrics']['compose_' . $name])) throw new RuntimeException('Missing compose work counter: ' . $name);
        $work[$name] = $result['metrics']['compose_' . $name];
    }
    // Exercise the cascade fallback even when identical public projections
    // eliminate page deltas before selector proof is needed.
    $facts = new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ComposeFacts();
    $documents = array();
    for ($i = 0; $i < $count; ++$i) {
        $dom = new DOMDocument();
        $dom->loadHTML('<html><body><p class="probe">Shared</p></body></html>');
        $documents['route-' . $i] = $dom;
    }
    $index = new \Automattic\BlocksEngine\PhpTransformer\Css\RouteSelectorMatchIndex($documents, $facts);
    $transformer = new \Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer($facts);
    foreach ($documents as $owner => $_) {
        $delta = $transformer->rulesAbsentFrom(array('.probe{color:blue}.probe{color:red}'), array('.probe{color:red}'), true, $index, $owner);
        if ('.probe{color:blue}.probe{color:red}' !== $delta) throw new RuntimeException('Cascade bytes changed.');
    }
    $work['route_selector_matches'] += $facts->metrics()['compose_route_selector_matches'] ?? 0;
    if ($work['route_selector_matches'] < 1 || $work['state_matches'] < 1) throw new RuntimeException('Scaling fixture must exercise selector and state evaluation.');
    echo $count . ': ' . json_encode($work) . "\n";
    if (null !== $baseline && $baseline !== $work) {
        fwrite(STDERR, "FAIL: compose work must depend on distinct inputs, not route count\n");
        exit(1);
    }
    $baseline = $work;
}
