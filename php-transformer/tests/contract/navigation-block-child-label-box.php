<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

// Reduced fixture from #4329: the relative box must contain the absolute fill.
$source = <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"><title>Menu of sized link boxes</title>
<style>
.slot{display:inline-block;position:relative;width:125px;height:53px;margin-left:10px}
.item{position:absolute;left:0;right:0;top:0;bottom:0}
.box{position:relative;height:100%}
.fill{position:absolute;left:0;top:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center}
</style></head>
<body>
<header><nav>
<span class="slot"><div class="item"><a href="/"><div class="box"><div class="fill"><span>Home</span></div></div></a></div></span><span class="slot"><div class="item"><a href="/services/"><div class="box"><div class="fill"><span>Services</span></div></div></a></div></span><span class="slot"><div class="item"><a href="/contact/"><div class="box"><div class="fill"><span>Contact</span></div></div></a></div></span>
</nav></header>
<main><p>Body text.</p></main>
</body></html>
HTML;
// Keep one real source span addressable so its inline role is observable.
$source = str_replace(array('<span>Services</span>', '</style>'), array('<span class="caption">Services</span>', '.caption{font-size:16px}</style>'), $source);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $source))))->toArray();
$native = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($result['serialized_blocks']);
$css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
if (isset($argv[1])) {
    file_put_contents($argv[1], json_encode(array('source' => $source, 'native' => $native, 'css' => $css), JSON_THROW_ON_ERROR));
}
$dom = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="utf-8" ?>' . $native);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$cascade = new StaticCssCascade($dom, $css);
$boxes = $fills = $labels = 0;
foreach ($dom->getElementsByTagName('span') as $span) {
    $classes = explode(' ', $span->getAttribute('class'));
    $display = $cascade->resolve($span, array('display'), array())['display'] ?? '';
    if (in_array('box', $classes, true)) {
        ++$boxes;
        if ('block' !== $display) throw new RuntimeException('box carrier must retain display:block; got ' . json_encode($display));
    } elseif (in_array('fill', $classes, true)) {
        ++$fills;
        if ('flex' !== $display) throw new RuntimeException('fill carrier must retain author display:flex');
    } elseif ('Services' === trim($span->textContent) && $span->parentNode instanceof DOMElement && in_array('fill', explode(' ', $span->parentNode->getAttribute('class')), true)) {
        ++$labels;
        if (!in_array($display, array('', 'inline'), true)) throw new RuntimeException('real source span must stay inline');
    }
}
if (3 !== $boxes || 3 !== $fills || 1 !== $labels) throw new RuntimeException('navigation label carrier topology was lost');
echo "Navigation block label boxes retain block display, author flex, and inline source spans\n";
