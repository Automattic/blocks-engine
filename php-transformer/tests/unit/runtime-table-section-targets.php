<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$cases = array(
    'empty tbody by id' => array('<tbody id="incTable"></tbody>', 'document.getElementById("incTable").appendChild(document.createElement("tr"));', true),
    'populated tbody by id' => array('<tbody id="incTable"><tr><td>1</td><td>High</td></tr></tbody>', 'document.getElementById("incTable").appendChild(document.createElement("tr"));', true),
    'no script target' => array('<tbody id="incTable"></tbody>', '', false),
    'tbody by class' => array('<tbody id="incTable" class="incidents"></tbody>', 'document.querySelector(".incidents").appendChild(document.createElement("tr"));', true),
    'tbody by data attribute' => array('<tbody id="incTable" data-incidents></tbody>', 'document.querySelector("[data-incidents]").appendChild(document.createElement("tr"));', true),
    'thead by id' => array('<thead id="incTable"><tr><th>ID</th></tr></thead>', 'document.getElementById("incTable").remove();', true),
    'tfoot by data attribute' => array('<tfoot id="incTable" data-total><tr><td>Total</td></tr></tfoot>', 'document.querySelector("[data-total]").remove();', true),
    'row by class' => array('<tbody><tr id="incTable" class="incident"><td>1</td></tr></tbody>', 'document.querySelector(".incident").remove();', true),
);
foreach ($cases as $label => [$section, $script, $preserved]) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<main><h1>Alerts</h1><table id="alerts" class="t"><thead><tr><th>ID</th><th>Sev</th></tr></thead>' . $section . '</table></main><script src="alerts.js"></script>',
            'alerts.js' => $script,
        ),
    ))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $islands = array_values(array_filter($result['source_reports']['runtime_islands'] ?? array(), static fn (array $island): bool => 'table' === ($island['tag'] ?? '')));
    if ($preserved) {
        if (! str_contains($markup, '<!-- wp:html') || str_contains($markup, '<!-- wp:table') || ! str_contains($markup, 'id="incTable"') || 1 !== count($islands)) {
            $failures[] = $label . ': expected one bounded table island retaining the target';
        }
    } elseif (! str_contains($markup, '<!-- wp:table') || array() !== $islands) {
        $failures[] = $label . ': expected a native table without an island';
    }
    if (! str_contains($markup, '<!-- wp:heading')) {
        $failures[] = $label . ': surrounding heading must remain editable';
    }
}
if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "runtime table section target tests passed\n";
