<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$cases = array(
    'capture-proven inert' => array(' data-blocks-engine-capture-inert-control="true"', 0),
    'unmarked' => array('', 1),
    'false marker' => array(' data-blocks-engine-capture-inert-control="false"', 1),
);
foreach ( $cases as $label => [$marker, $expectedFindings] ) {
    $html = '<!doctype html><html><head><title>Panel</title></head><body><main><div data-action="minimize" style="cursor:pointer"' . $marker . '>Minimize</div></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $findings = array_values(array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'interactive_control_behavior_lost' === ($finding['diagnostic_code'] ?? '')));
    if ( $expectedFindings !== count($findings) ) {
        $failures[] = $label . ': unexpected behavior-loss findings';
    }
    $serialized = $result['serialized_blocks'] ?? '';
    if ( '' === $serialized || ! str_contains($serialized, 'Minimize') ) {
        $failures[] = $label . ': static content must survive';
    }
    if ( str_contains($serialized, 'data-blocks-engine-capture-inert-control') ) {
        $failures[] = $label . ': capture marker leaked';
    }
    if ( 0 === $expectedFindings && str_contains($serialized, '<!-- wp:html') ) {
        $failures[] = $label . ': static control became core/html';
    }
}
if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Capture inert control: passed\n";
