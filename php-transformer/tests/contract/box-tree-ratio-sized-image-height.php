<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// #5859: a full-width image the author sizes by `aspect-ratio` (Tailwind's
// `aspect-[4/3] w-full`, `img{height:auto}`) is captured at 1440px and 390px.
// Its height is its width over that ratio, so the carrier restates `auto` and
// the copy follows its width at every width, rather than freezing the widest
// capture's 430.5px at the uncaptured 768px (702x431 for a 702x527 source).
$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (!$condition) throw new RuntimeException($message . ('' === $detail ? '' : "\n" . $detail));
    echo "PASS: $message\n";
};

/** The engine CSS the route delivers for one compiled image. */
$compile = static function (string $class, string $inline, array $heights): string {
    $author = 'img{display:block;max-width:100%;height:auto}.w-full{width:100%}.aspect-\[4\/3\]{aspect-ratio:4/3}.object-cover{object-fit:cover}';
    $markup = '<!doctype html><html><head><title>Ratio</title><style>' . $author . '</style></head><body><main><section><div class="col">'
        . '<img src="https://example.com/a.jpg" alt="A" class="' . $class . '"' . ('' === $inline ? '' : ' style="' . $inline . '"') . '></div><p>Text</p></section></main></body></html>';
    $viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'mobile' => array('width' => 390, 'height' => 844));
    $widths = array('desktop' => 574, 'mobile' => 348);
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
    $defaults = $fixture['nodes']['n5']['viewports']['desktop']['used_styles'];
    foreach ($defaults as $property => $value) if ('none' === $value && preg_match('/^(?:margin|padding)-/', $property)) $defaults[$property] = '0px';
    $defaults = array_replace($defaults, array('min-width' => 'auto', 'min-height' => 'auto', 'aspect-ratio' => 'auto', 'object-fit' => 'fill', 'max-width' => 'none'));
    $dom = new DOMDocument();
    $dom->loadHTML($markup, LIBXML_NOERROR);
    $xpath = new DOMXPath($dom);
    $id = 0;
    foreach ($xpath->query('//*') as $element) $element->setAttribute(BoxTree::NODE_ATTRIBUTE, 'n' . ++$id);
    $source = '<!doctype html>' . $dom->saveHTML($dom->documentElement);
    $nodes = array();
    foreach ($xpath->query('//*') as $element) {
        $children = array();
        foreach ($element->childNodes as $child) if ($child instanceof DOMElement) $children[] = $child->getAttribute(BoxTree::NODE_ATTRIBUTE);
        $captures = array();
        foreach ($viewports as $name => $viewport) {
            $styles = array_replace($defaults, array('width' => $viewport['width'] . 'px', 'height' => '100px'));
            if (in_array($element->tagName, array('head', 'title', 'style'), true)) $styles['display'] = 'none';
            if ('div' === $element->tagName) $styles['width'] = $widths[$name] . 'px';
            if ('img' === $element->tagName) $styles = array_replace($styles, array('display' => 'block', 'width' => $widths[$name] . 'px', 'height' => $heights[$name] . 'px',
                'max-width' => '100%', 'object-fit' => 'cover', 'aspect-ratio' => str_contains($class, 'aspect-') ? '4 / 3' : 'auto'));
            $captures[$name] = array('border_box' => array('x' => 0, 'y' => 0, 'width' => (float) $styles['width'], 'height' => (float) $styles['height']), 'used_styles' => $styles);
        }
        $nodes[$element->getAttribute(BoxTree::NODE_ATTRIBUTE)] = array('children' => $children, 'viewports' => $captures);
    }
    $tree = array('schema' => BoxTree::SCHEMA, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $source), 'viewports' => $viewports, 'nodes' => $nodes);
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source, 'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR))))
        ->toWordPressSitePlanView()['wordpress_site_plan'];
    $assets = array_column($plan['assets'], null, 'target_path');
    $css = '';
    foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= "\n" . ($assets[$row['target_path']]['content'] ?? '');
    return $css;
};
/** Every height an engine image carrier declares, base rule and bands. */
$heights = static function (string $css): array {
    preg_match_all('/\.be-inline-geometry-[0-9a-f]+(?:\.be-inline-geometry-[0-9a-f]+)* img\{[^}]*?(?<![\w-])height:([^;}]+)/', $css, $found);
    return $found[1];
};

foreach (array('author rule and inline style' => 'aspect-ratio: 4 / 3', 'author rule only' => '') as $label => $inline) {
    $css = $compile('aspect-[4/3] w-full object-cover', $inline, array('desktop' => 430.5, 'mobile' => 261));
    $assert(array('auto') === $heights($css), "A ratio-sized image restates its auto height ($label).", $css);
    $assert(1 === preg_match('/ img\{[^}]*width:100%[^}]*aspect-ratio:4 \/ 3/', $css), "The width and ratio the height derives from are kept ($label).", $css);
}

// Without a ratio, the replaced image's auto height is its intrinsic size: pixels are kept.
$css = $compile('w-full object-cover', '', array('desktop' => 430.5, 'mobile' => 261));
$assert(array('430.5px', '261px') === $heights($css), 'An image with no author ratio keeps its captured heights.', $css);
// A height its ratio does not explain is not restated as auto.
$css = $compile('aspect-[4/3] w-full object-cover', 'aspect-ratio: 4 / 3', array('desktop' => 300, 'mobile' => 200));
$assert(!in_array('auto', $heights($css), true), 'A height its ratio does not produce keeps its captured pixels.', $css);
