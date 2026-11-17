<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

// Reduced fixture from #6307: the link box and spacer are sibling divs.
$source = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Nav sibling</title><style>
body{margin:0;font-family:sans-serif}header{background:#222;color:#fff;display:flex;justify-content:space-between;padding:0 16px}
nav ul{list-style:none;margin:0;padding:0}nav li{display:inline-block}
.box{display:inline-block;padding:10px 12px}.box a{color:#fff;text-decoration:none}
.sep{display:inline-block;width:2px;height:2px;vertical-align:middle}
</style></head><body>
<header><a href="/" style="color:#fff;padding:10px 0">Site</a><nav><ul>
<li><div class="box"><a href="/">Home</a></div><div class="sep"></div></li>
<li><div class="box"><a href="/about/">About</a></div><div class="sep"></div></li>
<li><div class="box"><a href="/rates/">Rates</a></div><div class="sep"></div></li>
</ul></nav></header><main><h1>Hello</h1></main></body></html>
HTML;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $source))))->toArray();
$markup = $result['serialized_blocks'];
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
// The static renderer omits the runtime's retained boxes and siblings. Read
// their public metadata and materialize that same markup around each anchor.
$native = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup);
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $markup, $items);
foreach ($items[1] as $json) {
    $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $presentation = $attrs['metadata']['blocksEngineNavigationAnchor'] ?? array();
    $open = $close = '';
    foreach ($presentation['boxes'] ?? array() as $box) {
        $open .= '<' . $box['tag'] . ' class="' . htmlspecialchars($box['marker'] . ' ' . ($box['className'] ?? ''), ENT_QUOTES) . '">';
        $close = '</' . $box['tag'] . '>' . $close;
    }
    foreach ($presentation['siblings'] ?? array() as $sibling) {
        $html = '<' . $sibling['tag'] . ' class="' . htmlspecialchars($sibling['className'] ?? '', ENT_QUOTES) . '"></' . $sibling['tag'] . '>';
        if ($sibling['after'] ?? false) $close .= $html; else $open = $html . $open;
    }
    $url = preg_quote(htmlspecialchars($attrs['url'], ENT_QUOTES), '/');
    $native = preg_replace_callback('/(<a class="wp-block-navigation-item__content" href="' . $url . '">.*?<\/a>)/s', static fn(array $match): string => $open . $match[1] . $close, $native, 1);
}
if (isset($argv[1])) {
    file_put_contents($argv[1], json_encode(array('source' => $source, 'native' => $native, 'css' => $css), JSON_THROW_ON_ERROR));
}
if (!str_contains($markup, 'wp:navigation ')) throw new RuntimeException('Fixture must compile to native navigation');
$dom = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $native);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$cascade = new StaticCssCascade($dom, $css);
$counts = array('box' => 0, 'sep' => 0);
foreach ($dom->getElementsByTagName('div') as $element) {
    foreach ($counts as $class => $count) {
        if (!in_array($class, explode(' ', $element->getAttribute('class')), true)) continue;
        ++$counts[$class];
        $values = $cascade->resolve($element, array('padding', 'width'), array());
        $padding = $values['padding'] ?? '0';
        $expected = 'box' === $class ? '10px 12px' : '0';
        if ($expected !== $padding) throw new RuntimeException($class . ' padding: expected ' . $expected . ', got ' . $padding);
        if ('sep' === $class && '2px' !== ($values['width'] ?? '')) throw new RuntimeException('Spacer lost its 2px width');
    }
}
if (array('box' => 3, 'sep' => 3) !== $counts) throw new RuntimeException('Navigation must retain all three box/spacer pairs');
echo "Navigation box padding stays off sibling spacers\n";
