<?php
/**
 * A capture can assemble each route as a root island pair: one element with
 * the desktop-only class and one with the mobile-only class, each holding a
 * whole nav/header/main/footer frame (#4863). Shell extraction shares the
 * header and footer with one variant per island, each under the gate its
 * island class selects, as it already does for device documents and nested
 * device-only subtrees (#4611), which stay here as control rows.
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

$island = (string) file_get_contents(__DIR__ . '/fixtures/device-island-shared-chrome.html');
$islandClass = static fn(string $class): string => "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
$desktopIsland = $islandClass(CaptureEvidence::DESKTOP_ONLY_CLASS);
$mobileIsland = $islandClass(CaptureEvidence::MOBILE_ONLY_CLASS);
$document = static fn(string $device): string => '@' . CaptureEvidence::DEVICE_DOCUMENT . "='{$device}'";
$deviceDocuments = str_replace(
    array('<body><div id="root">', '</div></body>', 'class="frame-d ' . CaptureEvidence::DESKTOP_ONLY_CLASS . '"', 'class="frame-m ' . CaptureEvidence::MOBILE_ONLY_CLASS . '"'),
    array('<body>', '</body>', 'class="' . CaptureEvidence::DESKTOP_DOCUMENT_CLASS . '" ' . CaptureEvidence::DEVICE_DOCUMENT . '="desktop" ' . CaptureEvidence::DOCUMENT_SCOPE . '=""', 'class="' . CaptureEvidence::MOBILE_DOCUMENT_CLASS . '" ' . CaptureEvidence::DEVICE_DOCUMENT . '="mobile" ' . CaptureEvidence::DOCUMENT_SCOPE . '=""'),
    $island
);
$deviceOnly = '@' . CaptureEvidence::DEVICE_ONLY . "='mobile'";
$deviceHide = '@' . CaptureEvidence::DEVICE_HIDE . "='mobile'";

// Shape => [fixture, area => [copy id => [its gate, the other variant's gate]], gate classes the shared CSS must carry].
$rows = array(
    'root island pair' => array($island, array(
        'header' => array('masthead' => array($desktopIsland, $mobileIsland), 'masthead-m' => array($mobileIsland, $desktopIsland)),
        'footer' => array('colophon' => array($desktopIsland, $mobileIsland), 'colophon-m' => array($mobileIsland, $desktopIsland)),
    ), array(CaptureEvidence::DESKTOP_ONLY_CLASS, CaptureEvidence::MOBILE_ONLY_CLASS)),
    'body-level island pair' => array(str_replace(array('<body><div id="root">', '</div></body>'), array('<body>', '</body>'), $island), array(
        'header' => array('masthead' => array($desktopIsland, $mobileIsland), 'masthead-m' => array($mobileIsland, $desktopIsland)),
        'footer' => array('colophon' => array($desktopIsland, $mobileIsland), 'colophon-m' => array($mobileIsland, $desktopIsland)),
    ), array(CaptureEvidence::DESKTOP_ONLY_CLASS, CaptureEvidence::MOBILE_ONLY_CLASS)),
    'control: device documents' => array($deviceDocuments, array(
        'header' => array('masthead' => array($document('desktop'), $document('mobile')), 'masthead-m' => array($document('mobile'), $document('desktop'))),
        'footer' => array('colophon' => array($document('desktop'), $document('mobile')), 'colophon-m' => array($document('mobile'), $document('desktop'))),
    ), array()),
    'control: nested device-only' => array((string) file_get_contents(__DIR__ . '/fixtures/nested-device-only-shared-chrome.html'), array(
        'header' => array('masthead--data-blocks-engine-capture-mobile' => array($deviceOnly, $deviceHide), 'masthead' => array($deviceHide, $deviceOnly)),
        'footer' => array('colophon--data-blocks-engine-capture-mobile' => array($deviceOnly, $deviceHide), 'colophon' => array($deviceHide, $deviceOnly)),
    ), array()),
);

$failures = array();
foreach ($rows as $shape => [$fixture, $areas, $gateClasses]) {
    $files = array();
    foreach (array('index.html' => 'Home', 'about/index.html' => 'About', 'contact/index.html' => 'Contact') as $path => $title) $files[$path] = str_replace('TITLE', $title, $fixture);
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
    foreach ($plan['pages'] as $page) {
        if (preg_match('/<(?:header|footer)\b/', $page['canonical_block_markup'])) $failures[] = "{$shape}: {$page['source_path']} still inlines route chrome.";
        if (str_contains($page['canonical_block_markup'], 'wp:template-part')) $failures[] = "{$shape}: {$page['source_path']} binds chrome parts inline instead of sharing them.";
    }
    foreach ($areas as $area => $copies) {
        $parts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => $area === ($part['area'] ?? null)));
        if (1 !== count($parts)) { $failures[] = "{$shape}: expected one shared {$area} part, got " . count($parts) . '.'; continue; }
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $parts[0]['canonical_block_markup'] . '</body>');
        $xpath = new DOMXPath($dom);
        // Each device variant sits under the gate its own device's visibility CSS selects, and only that one.
        foreach ($copies as $id => [$gate, $other]) {
            if (1 !== $xpath->query("//{$area}[@id='{$id}'][ancestor::*[{$gate}]][not(ancestor::*[{$other}])]")->length) $failures[] = "{$shape}: {$area} variant #{$id} is not gated by [{$gate}] alone.";
        }
    }
    if (array() !== $gateClasses) {
        // The gate rules follow the part to every template that renders it.
        $global = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && array('global') === array_values(array_unique(array_column($asset['scopes'] ?? array(), 'kind')))), 'content'));
        foreach ($gateClasses as $class) {
            if (1 !== preg_match('/\.' . preg_quote($class, '/') . '\s*\{\s*display\s*:\s*none\s*!important/', $global)) $failures[] = "{$shape}: the .{$class} visibility rule does not reach the shared part.";
        }
    }
}
if (array() !== $failures) {
    fwrite(STDERR, "device island shared chrome contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "device island shared chrome contract passed\n";
