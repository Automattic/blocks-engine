<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// An unobserved device fragment still needs its transparent resting state,
// but must not acquire synthetic author geometry when author sheets retire.
$html = '<!doctype html><html><head><title>Test</title><style>'
    . '.skip{opacity:0;width:0;height:0;position:absolute;top:60px;background:red}'
    . '.visible{opacity:1;width:17px;position:absolute}'
    . '.skip:focus{opacity:1}'
    . '@media(min-width:1024px){.data-blocks-engine-capture-mobile-only{display:none!important}}'
    . '</style></head><body><main><button id="SKIP" class="skip data-blocks-engine-capture-mobile-only">Skip to Main Content</button>'
    . '<button id="VISIBLE" class="visible data-blocks-engine-capture-mobile-only">Visible</button>'
    . '<h1>Test</h1></main></body></html>';
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 640));
$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$defaults = $fixture['nodes']['n5']['viewports']['desktop']['used_styles'];
$dom = new DOMDocument();
$dom->loadHTML($html, LIBXML_NOERROR);
$xpath = new DOMXPath($dom);
$id = 0;
foreach ($xpath->query('//*') as $element) $element->setAttribute(BoxTree::NODE_ATTRIBUTE, ('button' === $element->tagName ? 'mobile:n' : 'n') . ++$id);
$source = '<!doctype html>' . $dom->saveHTML($dom->documentElement);
$nodes = array();
foreach ($xpath->query('//*') as $element) {
    $node = $element->getAttribute(BoxTree::NODE_ATTRIBUTE);
    if (str_starts_with($node, 'mobile:')) continue;
    $children = array();
    foreach ($element->childNodes as $child) {
        if ($child instanceof DOMElement && !str_starts_with($child->getAttribute(BoxTree::NODE_ATTRIBUTE), 'mobile:')) $children[] = $child->getAttribute(BoxTree::NODE_ATTRIBUTE);
    }
    $captures = array();
    foreach ($viewports as $name => $viewport) {
        $styles = array_replace($defaults, array('width' => $viewport['width'] . 'px', 'height' => '40px'));
        if (in_array($element->tagName, array('head', 'title', 'style'), true)) $styles['display'] = 'none';
        $captures[$name] = array('border_box' => array('x' => 0, 'y' => 0, 'width' => (float) $viewport['width'], 'height' => 40.0), 'used_styles' => $styles);
    }
    $nodes[$node] = array('children' => $children, 'viewports' => $captures);
}
$tree = array('schema' => BoxTree::SCHEMA, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $source), 'viewports' => $viewports, 'nodes' => $nodes);
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source, 'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR))));
$plan = $compiled->toWordPressSitePlanView()['wordpress_site_plan'];
$markup = $compiled->toArray()['serialized_blocks'];
if (empty($plan['pages'][0]['document_metadata']['engine_owned_styles'])) throw new RuntimeException('Expected an engine-owned route');
$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= "\n" . ($assets[$row['target_path']]['content'] ?? '');
if (!preg_match('/<div id="SKIP" class="([^"]*)"/', $markup, $match)) throw new RuntimeException('Missing skip button');
$resting = '';
foreach (preg_split('/\s+/', $match[1]) as $class) {
    if (!str_starts_with($class, 'be-inline-geometry-')) continue;
    if (preg_match_all('/(?<=^|})\s*([^{}@]*' . preg_quote($class, '/') . '[^{}]*)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER)) {
        foreach ($rules as $rule) if (!str_contains($rule[1], ':focus')) $resting .= $rule[2] . ';';
    }
}
if (!str_contains($resting, 'opacity:0')) throw new RuntimeException('Unobserved island loses resting opacity: ' . $css);
// This is author opacity, not a synthetic captured box. In particular it
// must not change the core wrappers to display:contents or move their styles.
foreach (array('display:', 'width:', 'height:', 'position:', 'top:') as $unrelated) {
    if (preg_match('/(?:^|;)' . preg_quote($unrelated, '/') . '/', $resting)) throw new RuntimeException('Opacity fallback invented presentation: ' . $resting);
}
if (str_contains($css, '.skip{')) throw new RuntimeException('Author stylesheet was not retired');
if (!preg_match('/<div id="VISIBLE" class="([^"]*)"/', $markup, $visible)) throw new RuntimeException('Missing visible control');
if (str_contains($visible[1], 'be-inline-geometry-')) throw new RuntimeException('Visible unobserved control acquired a carrier');
echo "Unobserved island opacity passed\n";
