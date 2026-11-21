<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

// Issue #7208: a two-line brand link whose children carry their own author
// typography. On a box-tree route each child keeps a box-tree carrier with
// its responsive values; the RichText marks nested inside must not restate
// the child's base declarations inline (they outrank the carrier's @media
// rules), and box declarations of a child laid out as a box (a flex item's
// margin-top and text-align) stay on that box.
$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if (!$condition) throw new RuntimeException($message . ('' === $detail ? '' : "\n" . $detail));
    echo "PASS: $message\n";
};

$author = 'body{margin:0;font-family:sans-serif;color:#111}'
    . '.brand{display:inline-flex;flex-direction:column;color:#111;text-decoration:none}'
    . '.brand strong{font:900 35px sans-serif}'
    . '.brand small{text-align:right;letter-spacing:8px;font-size:11px;margin-top:8px}'
    . '@media(max-width:900px){.brand strong{font-size:28px}}'
    . 'h1 em{font-style:italic;color:#a9a9a9}';
$markup = '<!doctype html><html><head><meta charset="utf-8"><title>Brand</title><style>' . $author . '</style></head><body>'
    . '<header><a class="brand" href="/"><strong>MX<span>PERF</span></strong><small>BY ME</small></a></header>'
    . '<main><h1>IK BEN<br><em>GREY WORDS.</em></h1><p>Body copy.</p></main></body></html>';
$viewports = array('desktop' => array('width' => 1440, 'height' => 900), 'tablet' => array('width' => 768, 'height' => 1024));

// Used values the browser reports; the link's children are blockified flex items.
$strong = static fn(string $size, string $width): array => array('display' => 'block', 'font-weight' => '900', 'font-size' => $size, 'width' => $width, 'height' => '40px');
$small = array('display' => 'block', 'text-align' => 'right', 'letter-spacing' => '8px', 'font-size' => '11px', 'margin-top' => '8px', 'width' => '160px', 'height' => '13px');
$observed = array(
    '//a[@class="brand"]' => array_fill_keys(array_keys($viewports), array('display' => 'inline-flex', 'flex-direction' => 'column', 'width' => '160px', 'height' => '61px')),
    '//a[@class="brand"]/strong' => array('desktop' => $strong('35px', '160px'), 'tablet' => $strong('28px', '128px')),
    '//a[@class="brand"]/strong/span' => array('desktop' => array_replace($strong('35px', '80px'), array('display' => 'inline')), 'tablet' => array_replace($strong('28px', '64px'), array('display' => 'inline'))),
    '//a[@class="brand"]/small' => array_fill_keys(array_keys($viewports), $small),
    // #7241: the em's carrier projects only its font-style; its colour is not a
    // carrier property and must still reach the text.
    '//h1/em' => array_fill_keys(array_keys($viewports), array('display' => 'inline', 'font-style' => 'italic', 'color' => 'rgb(169, 169, 169)', 'width' => '200px', 'height' => '40px')),
);

$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$defaults = $fixture['nodes']['n5']['viewports']['desktop']['used_styles'];
foreach ($defaults as $property => $value) {
    if ('none' === $value && preg_match('/^(?:margin|padding)-/', $property)) $defaults[$property] = '0px';
}
$defaults = array_replace($defaults, array(
    'overflow-x' => 'visible', 'overflow-y' => 'visible', 'row-gap' => 'normal', 'column-gap' => 'normal',
    'flex-direction' => 'row', 'flex-wrap' => 'nowrap', 'justify-content' => 'normal', 'align-items' => 'normal', 'align-content' => 'normal',
    'flex-grow' => '0', 'flex-shrink' => '1', 'flex-basis' => 'auto', 'align-self' => 'auto', 'order' => '0', 'border-radius' => '0px',
    'border-top' => '0px none rgb(0, 0, 0)', 'border-right' => '0px none rgb(0, 0, 0)', 'border-bottom' => '0px none rgb(0, 0, 0)', 'border-left' => '0px none rgb(0, 0, 0)',
    'min-width' => 'auto', 'min-height' => 'auto', 'font-family' => 'sans-serif', 'color' => 'rgb(17, 17, 17)',
));

$dom = new DOMDocument();
$dom->loadHTML($markup, LIBXML_NOERROR);
$xpath = new DOMXPath($dom);
$id = 0;
foreach ($xpath->query('//*') as $element) $element->setAttribute(BoxTree::NODE_ATTRIBUTE, 'n' . ++$id);
$targets = array();
foreach ($observed as $query => $values) $targets[$xpath->query($query)->item(0)->getAttribute(BoxTree::NODE_ATTRIBUTE)] = $values;
$source = '<!doctype html>' . $dom->saveHTML($dom->documentElement);
$nodes = array();
foreach ($xpath->query('//*') as $element) {
    $node = $element->getAttribute(BoxTree::NODE_ATTRIBUTE);
    $children = array();
    foreach ($element->childNodes as $child) if ($child instanceof DOMElement) $children[] = $child->getAttribute(BoxTree::NODE_ATTRIBUTE);
    $captures = array();
    foreach ($viewports as $name => $viewport) {
        $styles = array_replace($defaults, array('width' => $viewport['width'] . 'px', 'height' => '100px'));
        if (in_array($element->tagName, array('head', 'title', 'style', 'meta'), true)) $styles['display'] = 'none';
        $styles = array_replace($styles, $targets[$node][$name] ?? array());
        $captures[$name] = array('border_box' => array('x' => 0, 'y' => 0, 'width' => (float) $styles['width'], 'height' => (float) $styles['height']), 'used_styles' => $styles);
    }
    $nodes[$node] = array('children' => $children, 'viewports' => $captures);
}
$tree = array('schema' => BoxTree::SCHEMA, 'source_path' => 'index.html', 'source_hash' => hash('sha256', $source), 'viewports' => $viewports, 'nodes' => $nodes);

// The public staged path, whose route delivers engine-owned CSS only.
$files = array('index.html' => $source, 'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR));
$reader = new class implements PayloadReader {
    public array $payloads = array();
    public function read(array $reference): string { return $this->payloads[$reference['id']]; }
};
$artifact = array('entrypoint' => 'index.html', 'files' => array());
foreach ($files as $path => $bytes) {
    $reader->payloads[$path] = $bytes;
    $artifact['files'][] = array('path' => $path, 'payload_reference' => array(
        'schema' => 'blocks-engine/payload-reference/v1', 'id' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
    ));
}
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact, $reader);
$plans = $compiler->preparePages($artifact, $shared, $reader);
$composed = $compiler->compose($shared, array_values($compiler->compilePreparedPages($shared, array_values($plans), $reader)), $reader);
$blocks = $composed->toArray()['serialized_blocks'];
$plan = $composed->toWordPressSitePlanView()['wordpress_site_plan'];
$assets = array_column($plan['assets'], null, 'target_path');
$css = '';
foreach (ThemeBootstrap::documentStylesheets($plan, 'index.html') as $row) $css .= "\n" . ($assets[$row['target_path']]['content'] ?? '');
$detail = $blocks . "\n" . $css;

$assert(!empty($plan['pages'][0]['document_metadata']['engine_owned_styles']) && !str_contains($css, '.brand'), 'The route is engine-owned: no author selector is delivered.', $detail);
$assert(1 === preg_match('/<a class="brand" href="\/">(.*?)<\/a>/s', $blocks, $link), 'The brand link is kept as RichText.', $detail);
$assert(1 === preg_match('/<strong class="(be-inline-geometry-[0-9a-f]+)">/', $link[1], $strongCarrier), 'The strong keeps its own box-tree carrier.', $detail);
$assert(1 === preg_match('/<small class="(be-inline-geometry-[0-9a-f]+)">/', $link[1], $smallCarrier), 'The small keeps its own box-tree carrier.', $detail);
$assert(!preg_match('/style="[^"]*(?:font|letter-spacing|margin|text-align)/', $link[1]), 'No inline style in the link restates typography or box declarations over the carriers.', $link[1]);
$assert(!str_contains($link[1], '<mark'), 'Text runs need no mark carrier once the children own their styling.', $link[1]);

$rule = static function (string $class, string $media = '') use ($css): string {
    $selector = preg_quote('.' . $class . '.' . $class, '/');
    $pattern = '' === $media
        ? '/(?<![{\w-])' . $selector . '\{([^}]*)\}/'
        : '/@media \(max-width:' . preg_quote($media, '/') . 'px\)\{' . $selector . '\{([^}]*)\}\}/';
    return 1 === preg_match($pattern, $css, $match) ? $match[1] : '';
};
// A typography preset may stand for the base value, with it as fallback.
$declares = static fn(string $body, string $property, string $value): bool => 1 === preg_match('/(?:^|;)' . $property . ':(?:var\(--[\w-]+,)?' . preg_quote($value, '/') . '\)?(?:;|$)/', $body);
$assert($declares($rule($strongCarrier[1]), 'font-size', '35px') && $declares($rule($strongCarrier[1], '900'), 'font-size', '28px'), 'The strong carrier holds the 900px author breakpoint, which now reaches the text.', $css);
$small = $rule($smallCarrier[1]);
$assert(str_contains($small, 'text-align:right') && str_contains($small, 'margin-top:8px'), 'The small, a flex item, keeps text-align:right and margin-top:8px on its own box.', $css);
$assert($declares($small, 'font-size', '11px') && $declares($small, 'letter-spacing', '8px'), 'The small carrier holds its own typography.', $css);
$assert(!preg_match('/(?:margin|padding):0px/', $rule($strongCarrier[1])), 'Zero box sides are not restated on an inline carrier.', $css);

if (false !== ($target = getenv('BOX_TREE_LINK_CHILD_CARRIERS_ARTIFACT'))) {
    file_put_contents($target, json_encode(array('html' => '<!doctype html><html><head><style>' . $css . '</style></head><body>' . $blocks . '</body></html>'), JSON_THROW_ON_ERROR));
}

// #7241: the carrier-owned filter withholds only what a carrier projects; a
// colour the em's carrier never states still reaches its text.
$assert(1 === preg_match('/<em class="be-inline-geometry-[0-9a-f]+">(.*?)<\/em>/s', $blocks, $em), 'The em keeps its box-tree carrier.', $blocks);
$assert(1 === preg_match('/(?:has-[\w-]+-color|color:\s*(?:#a9a9a9|rgb\(169, 169, 169\)))[^>]*>GREY WORDS\./i', $em[1]), 'The em text keeps the colour its carrier does not state.', $em[1]);
