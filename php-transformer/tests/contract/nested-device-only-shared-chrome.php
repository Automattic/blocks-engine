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
// Different route paint forces source-identity reconciliation. The layout
// rules must keep the page compiler's classes even though that route-specific
// stylesheet is absent from the isolated artifact used to prove identity.
$files = array('index.html' => str_replace(array('TITLE', 'COLOR'), array('Home', 'red'), $fixture), 'about/index.html' => str_replace(array('TITLE', 'COLOR'), array('About', 'blue'), $fixture));
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
$fail = static function (string $message): never { fwrite(STDERR, $message . "\n"); exit(1); };
foreach ($plan['pages'] as $page) if (preg_match('/<(?:header|footer)\b/', $page['canonical_block_markup'])) $fail("{$page['source_path']} retains route-owned chrome.");
foreach (array('header' => 'masthead', 'footer' => 'colophon') as $area => $id) {
    $parts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => $area === ($part['area'] ?? null)));
    if (1 !== count($parts)) $fail("Expected one shared {$area} part.");
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $parts[0]['canonical_block_markup'] . '</body>');
    $xpath = new DOMXPath($dom);
    if ('header' === $area) {
        // Every compiled grid/placement predicate must still select the same
        // header carrier, under the device scope used by the author sheet.
        preg_match_all('/([^{}]+)\{([^{}]*(?:grid-area|grid-template-columns)[^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $checked = 0;
        foreach ($rules as $rule) {
            if (!str_contains($rule[1], ':where([data-be-d="desktop"])')) continue;
            preg_match_all('/\.(blocks-engine-attribute-[a-f0-9]+-[0-9]+)\b/', $rule[1], $classes);
            foreach (array_unique($classes[1]) as $class) {
                ++$checked;
                if (0 === $xpath->query('//header//*[@class and contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")][ancestor::*[@data-be-d="desktop"]]')->length) $fail("Header lost scoped author predicate {$class}.");
            }
        }
        if ($checked < 4) $fail('Fixture must exercise scoped attribute-class grid and child placement rules for both headers.');
        // Compare the inline compiled header carriers too: identity markup is
        // deliberately not a renderable substitute for these generated classes.
        $inline = new DOMDocument();
        @$inline->loadHTML('<?xml encoding="utf-8"?><body>' . $result['serialized_blocks'] . '</body>');
        $inlinePath = new DOMXPath($inline);
        foreach ($inlinePath->query('//header//*[@class]') as $element) {
            preg_match_all('/\bblocks-engine-attribute-[a-f0-9]+-[0-9]+\b/', $element->getAttribute('class'), $classes);
            foreach ($classes[0] as $class) {
                if (0 === $xpath->query('//header//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]')->length) $fail("Header lost inline attribute class {$class}.");
            }
        }
    }
    // Each device copy sits under the gate its own device's visibility CSS selects.
    foreach (array($id . '--data-blocks-engine-capture-mobile' => array(CaptureEvidence::DEVICE_ONLY, CaptureEvidence::DEVICE_HIDE), $id => array(CaptureEvidence::DEVICE_HIDE, CaptureEvidence::DEVICE_ONLY)) as $copy => [$gate, $other]) {
        if (1 !== $xpath->query("//{$area}[@id='{$copy}'][ancestor::*[@{$gate}='mobile']][not(ancestor::*[@{$other}])]")->length) $fail("{$area} copy #{$copy} is not gated by {$gate}.");
        // The gate stays inside its device document: the document's rules select through that marker.
        if (1 !== $xpath->query("//*[@" . CaptureEvidence::DEVICE_DOCUMENT . "='desktop'][@data-be-d='desktop']//*[@{$gate}='mobile']//{$area}[@id='{$copy}']")->length) $fail("{$area} copy #{$copy} lost its enclosing device document.");
    }
}
echo "nested device-only shared chrome contract passed\n";
