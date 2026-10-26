<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

// Reduced page from #3462, including the sibling span's independent styling.
$source = <<<'HTML'
<style>
.menu .nav>li{position:relative;float:left}
.menu .nav>li>a span{display:block;font-size:10px}
.menu .nav>li>a p{position:absolute;left:47px;top:40px;transform:rotate(90deg);margin:0}
</style>
<header><div class="menu"><ul class="nav">
  <li><a href="/">Home<span>Welcome</span></a></li>
  <li><a href="/g/">Gallery<span>My work</span> <p>&gt;</p></a><ul class="sub"><li><a href="/g/a/">Sculpture</a></li><li><a href="/g/b/">Painting</a></li></ul></li>
  <li><a href="/c/">Contact<span>Find me</span></a></li>
</ul></div></header><main><p>Hi</p></main>
HTML;

foreach (array('p', 'div', 'section', 'blockquote') as $tag) {
    $html = str_replace(array('a p{', '<p>&gt;</p>'), array('a ' . $tag . '{', '<' . $tag . '>&gt;</' . $tag . '>'), $source);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html))))->toArray();
    $markup = $result['serialized_blocks'];
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    $native = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup);
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $native);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $cascade = new StaticCssCascade($dom, $css);
    $found = false;
    foreach ($dom->getElementsByTagName('span') as $span) {
        if ('>' !== trim($span->textContent)) continue;
        if (!preg_match('/blocks-engine-semantic-[a-z0-9-]+/', $span->getAttribute('class'), $marker)) continue;
        $values = $cascade->resolve($span, array('position', 'left', 'top', 'transform'), array());
        if (array('position' => 'absolute', 'left' => '47px', 'top' => '40px', 'transform' => 'rotate(90deg)') != $values || !str_contains($css, $marker[0])) {
            throw new RuntimeException($tag . ': carrier lost projected author CSS: ' . json_encode($values));
        }
        $found = true;
    }
    if (!$found) throw new RuntimeException($tag . ': navigation label lost the marked chevron carrier');
    if ('p' === $tag && isset($argv[1])) {
        file_put_contents($argv[1], json_encode(array('source' => $html, 'native' => $native, 'css' => $css), JSON_THROW_ON_ERROR));
    }
}
echo "Block children in navigation labels retain semantic carriers and positioned presentation\n";
