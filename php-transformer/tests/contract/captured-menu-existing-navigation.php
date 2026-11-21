<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// PR #7341 real-import document: source CSS and capture runtime CSS are
// deliberately in the same style element, with the already-projected header.
$html = file_get_contents(dirname(__DIR__) . '/fixtures/captured-menu-existing-navigation.html');
$document = new DOMDocument();
@$document->loadHTML($html);
$xpath = new DOMXPath($document);
foreach ($xpath->query('//@*[starts-with(name(), "data-blocks-engine-capture-")]') as $attribute) {
    $attribute->ownerElement->removeAttribute($attribute->name);
}
foreach ($xpath->query('//*[@id="data-blocks-engine-capture-dialog-0"]') as $panel) {
    $panel->removeAttribute('id');
    $panel->setAttribute('class', 'links');
}
foreach ($document->getElementsByTagName('button') as $button) {
    foreach (array('aria-controls', 'aria-expanded', 'aria-haspopup') as $name) $button->removeAttribute($name);
}
$failures = array();
foreach (array('without markers' => $document->saveHTML(), 'exported capture' => $html) as $case => $content) {
    $artifact = array('entrypoint' => 'index.html', 'files' => array(
        array('path' => 'index.html', 'content' => $content),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'index.html')))))
    ));
    $result = (new ArtifactCompiler())->compile($artifact)->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $runtime = new Runtime();
    $blocks = $runtime->parseBlocks($markup);
    $navigation = array();
    $walk = static function (array $blocks) use (&$walk, &$navigation, &$failures, $case): void {
        foreach ($blocks as $block) {
            if ('core/navigation' === ($block['blockName'] ?? '')) $navigation[] = $block;
            if ('core/button' === ($block['blockName'] ?? '')) $failures[] = $case . ': source toggle became core/button';
            if (preg_match('/(?:display|visibility)[^!]*!important/i', (string) json_encode($block['attrs'] ?? array()))) $failures[] = $case . ': forced pose in block attributes';
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($blocks);
    // Core dynamically renders overlayMenu=mobile: hidden until opened on
    // phones, inline at desktop; no authored display override is needed.
    if (1 !== count($navigation) || 'mobile' !== ($navigation[0]['attrs']['overlayMenu'] ?? null)) $failures[] = $case . ': expected one responsive core/navigation';
    $items = $navigation[0]['innerBlocks'] ?? array();
    if (5 !== count($items)) $failures[] = $case . ': expected five native navigation links';
    foreach (array('The Stories', 'Approach', 'Collections', 'About', 'Get in touch ↗') as $index => $label) {
        if ('core/navigation-link' !== ($items[$index]['blockName'] ?? '') || $label !== ($items[$index]['attrs']['label'] ?? '')) $failures[] = $case . ': missing native link ' . $label;
    }
    if (str_contains($markup, 'data-blocks-engine-capture-dialog')) $failures[] = $case . ': captured dialog survived';
    if (!$blocks || $runtime->serializeBlocks($blocks) !== $markup) $failures[] = $case . ': block markup does not round-trip';
    if (isset($baseline) && $baseline !== $markup) $failures[] = 'capture markers must not change native navigation output';
    $baseline = $markup;
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit($failures ? 1 : 0);
