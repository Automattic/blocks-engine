<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

$source = '<!doctype html><html><head><title>Navigation</title><style>.label{font:700 17px/1.4 Sora,sans-serif;letter-spacing:.08em;color:purple;text-transform:uppercase}nav ul{display:flex;gap:24px;list-style:none}</style></head><body><nav><ul><li><div class="wrap"><div class="label"><a href="#about">About</a></div></div></li><li><div class="wrap"><div class="label"><a href="#services">Services</a></div></div></li></ul></nav></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $source))))->toArray();
$frontend = array('blocks' => $result['serialized_blocks'], 'assets' => array_values(array_filter($result['assets'], static fn(array $asset): bool => 'editor' !== ($asset['stylesheet_target'] ?? 'both'))));
// Baseline captured on trunk: serialized blocks (including runtime box
// metadata) and every non-editor asset must remain byte-for-byte identical.
if ('714e8e4289515473892231fac21c975433b2b3263cdf8350396237ee4d4acbe7' !== hash('sha256', json_encode($frontend))) {
    throw new RuntimeException('Frontend markup or stylesheet output changed.');
}
$native = StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($result['serialized_blocks']);
$dom = new DOMDocument();
@$dom->loadHTML($native);
// Core's editor omits the runtime-injected boxes, retaining the native link.
$xpath = new DOMXPath($dom);
foreach (iterator_to_array($xpath->query('//*[contains(@class,"blocks-engine-navigation-box-")]')) as $box) {
    while ($box->firstChild) $box->parentNode->insertBefore($box->firstChild, $box);
    $box->parentNode->removeChild($box);
}
$editorCss = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'editor' === ($asset['stylesheet_target'] ?? 'both')), 'content'));
$cascade = new StaticCssCascade($dom, $editorCss);
$expected = array('font-family' => 'Sora,sans-serif', 'font-size' => '17px', 'font-weight' => '700', 'letter-spacing' => '.08em', 'line-height' => '1.4', 'color' => 'purple', 'text-transform' => 'uppercase');
$count = 0;
foreach ($dom->getElementsByTagName('a') as $link) {
    ++$count;
    $actual = $cascade->resolve($link, array_keys($expected), array());
    foreach ($expected as $property => $value) {
        if (($actual[$property] ?? '') !== $value) throw new RuntimeException($property . ': expected ' . $value . ', got ' . ($actual[$property] ?? '(missing)'));
    }
}
if (2 !== $count) throw new RuntimeException('Expected both native navigation links.');
echo "Navigation label-box typography reaches editor links without changing frontend output\n";
