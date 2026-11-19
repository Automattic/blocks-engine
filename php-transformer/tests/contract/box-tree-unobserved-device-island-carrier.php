<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// Issue #5860: a responsive capture grafts a mobile-only fragment (a Wix skip
// button, hidden at rest by `opacity:0;width:0;height:0;position:absolute`
// and revealed on focus) into the desktop document. The route's box tree has
// no node for it, yet the route is engine-owned, so its author sheets are
// retired (ADR 0005). The block must still carry the author's resting pose,
// or the hidden label paints at every mobile width.
$assert = static function (bool $ok, string $message, string $detail = ''): void {
    if (!$ok) throw new RuntimeException($message . ('' === $detail ? '' : "\n" . $detail));
    echo "PASS: $message\n";
};

$author = '.skip{pointer-events:none;z-index:9999;opacity:0;width:0;height:0;margin-left:-94px;padding:0 24px;position:absolute;top:60px;left:50%;background:#fff;color:#116dff}'
    . '.skip:focus{opacity:1;pointer-events:auto;width:auto;height:40px}'
    . '@media (min-width:1024px){.data-blocks-engine-capture-mobile-only{display:none!important}}';
$html = '<!doctype html><html><head><title>Terms</title><style>' . $author . '</style></head><body><main>'
    . '<button id="SKIP" class="skip data-blocks-engine-capture-mobile-only" tabindex="0">Skip to Main Content</button>'
    . '<h1>Terms</h1><p>Hello world</p></main></body></html>';
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 640));

$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$defaults = $fixture['nodes']['n5']['viewports']['desktop']['used_styles'];
$dom = new DOMDocument();
$dom->loadHTML($html, LIBXML_NOERROR);
$xpath = new DOMXPath($dom);
$id = 0;
// The grafted fragment keeps its mobile capture's namespaced id, which the
// route's box tree never observed.
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
$assert(!empty($plan['pages'][0]['document_metadata']['engine_owned_styles']), 'The route is engine-owned, so its author stylesheets are retired.');

$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= "\n" . ($assets[$row['target_path']]['content'] ?? '');
$assert(1 === preg_match('/<div id="SKIP" class="([^"]*)"/', $markup, $match), 'The skip button converts to a core/button.', $markup);
$classes = array_values(array_filter(preg_split('/\s+/', $match[1]) ?: array(), static fn(string $class): bool => str_starts_with($class, 'be-inline-geometry-')));
$assert(array() !== $classes, 'The unobserved island button still gets an engine carrier.', $markup);

// Rules addressing the button's own carrier, outside any @media, at rest.
$resting = '';
foreach ($classes as $class) {
    if (preg_match_all('/(?<=^|})\s*([^{}@]*' . preg_quote($class, '/') . '[^{}]*)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER)) {
        foreach ($rules as $rule) if (!str_contains($rule[1], ':focus')) $resting .= $rule[2] . ';';
    }
}
$assert(str_contains($resting, 'opacity:0'), 'The carrier keeps the author\'s hidden-at-rest opacity.', $css);
$assert(str_contains($resting, 'width:0') && str_contains($resting, 'height:0'), 'The carrier keeps the author\'s zero resting size.', $css);
$assert(str_contains($resting, 'position:absolute'), 'The carrier keeps the button out of flow.', $css);
$assert(!str_contains($resting, 'display:none'), 'The desktop-only hiding rule does not leak into the mobile copy\'s carrier.', $css);
$assert(!str_contains($css, '.skip{'), 'No author selector is delivered on the evidence route.', $css);

echo "Box-tree unobserved device island carrier passed\n";
