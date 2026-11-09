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
// Home keeps its footer inline (its copy differs) with the same footer
// classes, while the other routes share the hoisted footer. The rule
// `#container .colophon` reaches that footer through an ancestor the shared
// part is rendered outside of: it stays on the inline route, and a copy
// re-anchored on the part's wrapper must reach the hoisted footer too.
$withInlineFooter = static fn(string $html): string => preg_replace('#(<footer id="colophon(?:-m)?" class="colophon"><p>© Example Co\.</p>)#u', '$1<p>Say hello.</p>', $html) ?? $html;
$files = array('index.html' => $withInlineFooter(str_replace('TITLE', 'Home', $island)));
foreach (array('about/index.html' => 'About', 'contact/index.html' => 'Contact', 'work/index.html' => 'Work') as $path => $title) $files[$path] = str_replace('TITLE', $title, $island);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$footers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null) && 'shared_shell' === ($part['placement']['kind'] ?? null)));
$pages = array_column($plan['pages'], 'canonical_block_markup', 'source_path');
if (1 !== count($footers) || !in_array('container', (array) ($footers[0]['ancestor_context']['ids'] ?? array()), true)) $failures[] = 'mixed footers: expected one hoisted footer detached from #container.';
if (!str_contains($pages['index.html'] ?? '', 'class="wp-block-group colophon')) $failures[] = 'mixed footers: index.html no longer keeps its footer inline.';
foreach (array('about/index.html', 'contact/index.html', 'work/index.html') as $path) if (preg_match('/<footer\b/', $pages[$path] ?? '')) $failures[] = "mixed footers: {$path} still inlines its footer.";
$css = array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null));
$shared = implode("\n", array_column(array_filter($css, static fn(array $asset): bool => str_starts_with((string) ($asset['path'] ?? ''), 'assets/css/shared-chrome-')), 'content'));
$route = implode("\n", array_column(array_filter($css, static fn(array $asset): bool => in_array('index.html', array_column($asset['scopes'] ?? array(), 'source_path'), true)), 'content'));
// The hoisted footer sits in a wrapper whose child is #colophon, not in #container.
if (1 !== preg_match('/:where\([^{}]*:has\(> #colophon\)[^{}]*\)\)\s*\.colophon\s*\{\s*padding-top\s*:\s*200px/', $shared)) $failures[] = 'mixed footers: shared chrome does not apply `#container .colophon{padding-top:200px}` to the hoisted footer.';
if (1 !== preg_match('/(?:^|[}\s])#container \.colophon\s*\{\s*padding-top\s*:\s*200px/', $route)) $failures[] = 'mixed footers: the inline route lost its `#container .colophon` rule.';

if (array() !== $failures) {
    fwrite(STDERR, "device island shared chrome contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "device island shared chrome contract passed\n";
