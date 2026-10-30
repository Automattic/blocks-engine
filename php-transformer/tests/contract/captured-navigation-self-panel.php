<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// The controlled panel IS the converted nav, not a dialog containing it.
// Keep the capture runtime verbatim: without it the duplicate is already dropped.
$html = (string) file_get_contents(__DIR__ . '/fixtures/captured-navigation-self-panel.html');
$failures = array();
foreach (array('with runtime' => $html, 'without runtime' => preg_replace('/<script\b[^>]*>.*?<\/script>/s', '', $html)) as $case => $source) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $source))))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    preg_match_all('/<!-- wp:navigation (\{.*?\}) -->/', $markup, $matches);
    $navigation = json_decode($matches[1][0] ?? '{}', true);
    if (1 !== count($matches[1]) || 'primaryNav' !== ($navigation['anchor'] ?? null) || 'mobile' !== ($navigation['overlayMenu'] ?? null)) {
        $failures[] = $case . ': the controlled nav must become one core/navigation with its native mobile opener.';
    }
    // At 390px Core provides the one menu toggle; no source button or wiring
    // may survive beside it. The CTA and all menu destinations must remain.
    if (str_contains($markup, '<button') || str_contains($markup, 'menuToggle') || str_contains($markup, 'aria-controls')) {
        $failures[] = $case . ': the superseded burger must not survive as a second menu toggle.';
    }
    foreach (array('/a.html', '/b.html', '/c.html', 'Free Estimate') as $content) {
        if (!str_contains($markup, $content)) {
            $failures[] = $case . ': missing retained content ' . $content;
        }
    }
    foreach ($result['diagnostics'] ?? array() as $diagnostic) {
        if ('runtime_dependency_target_missing' === ($diagnostic['code'] ?? null)) {
            $failures[] = $case . ': superseded wiring must not be reported as a missing runtime target.';
        }
    }
}
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
exit(array() === $failures ? 0 : 1);
