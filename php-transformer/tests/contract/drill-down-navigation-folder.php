<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A drill-down overlay emits its navigation from the root folder's wrapper;
// each other folder, with its back control, sits beside that wrapper. Core's
// native submenus replace the back control, so it is not lost behavior.
$html = file_get_contents(__DIR__ . '/fixtures/drill-down-navigation-folder.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $html),
)))->toArray();
$failures = array();
if (str_contains($result['serialized_blocks'], 'header-menu-controls-control')) {
    $failures[] = 'The folder back control must be absorbed by native navigation.';
}
$quality = $result['source_reports']['wordpress_site_plan']['quality'] ?? null;
if (!is_array($quality)) $failures[] = 'Compilation must expose site-plan quality findings.';
foreach ($quality['fallbacks'] ?? array() as $fallback) {
    if ('interactive_control_behavior_lost' === ($fallback['reason'] ?? '')) {
        $failures[] = 'The drill-down folder back control must not count as lost behavior: ' . ($fallback['selector'] ?? '');
    }
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
