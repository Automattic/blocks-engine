<?php
/**
 * A capture can nest the phone layout as a device-only subtree beside its
 * device-hidden desktop sibling. The shared header and footer still hoist,
 * and each device copy keeps the gate its source visibility CSS selects,
 * inside the device document that encloses it.
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
$fixture = (string) file_get_contents(__DIR__ . '/fixtures/nested-device-only-shared-chrome.html');
$files = array('index.html' => str_replace('TITLE', 'Home', $fixture), 'about/index.html' => str_replace('TITLE', 'About', $fixture));
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$fail = static function (string $message): never { fwrite(STDERR, $message . "\n"); exit(1); };
foreach ($plan['pages'] as $page) if (preg_match('/<(?:header|footer)\b/', $page['canonical_block_markup'])) $fail("{$page['source_path']} retains route-owned chrome.");
foreach (array('header' => 'masthead', 'footer' => 'colophon') as $area => $id) {
    $parts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => $area === ($part['area'] ?? null)));
    if (1 !== count($parts)) $fail("Expected one shared {$area} part.");
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $parts[0]['canonical_block_markup'] . '</body>');
    $xpath = new DOMXPath($dom);
    // Each device copy sits under the gate its own device's visibility CSS selects.
    foreach (array($id . '--data-blocks-engine-capture-mobile' => array(CaptureEvidence::DEVICE_ONLY, CaptureEvidence::DEVICE_HIDE), $id => array(CaptureEvidence::DEVICE_HIDE, CaptureEvidence::DEVICE_ONLY)) as $copy => [$gate, $other]) {
        if (1 !== $xpath->query("//{$area}[@id='{$copy}'][ancestor::*[@{$gate}='mobile']][not(ancestor::*[@{$other}])]")->length) $fail("{$area} copy #{$copy} is not gated by {$gate}.");
        // The gate stays inside its device document: the document's rules select through that marker.
        if (1 !== $xpath->query("//*[@" . CaptureEvidence::DEVICE_DOCUMENT . "='desktop']//*[@{$gate}='mobile']//{$area}[@id='{$copy}']")->length) $fail("{$area} copy #{$copy} lost its enclosing device document.");
    }
}
echo "nested device-only shared chrome contract passed\n";
