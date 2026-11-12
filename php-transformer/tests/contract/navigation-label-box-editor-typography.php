<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

$source = '<!doctype html><html><head><title>Navigation</title><style>.label{font:700 17px/1.4 Sora,sans-serif;letter-spacing:.08em;color:purple;text-transform:uppercase}nav ul{display:flex;gap:24px;list-style:none}</style></head><body><nav><ul><li><div class="wrap"><div class="label"><a href="#about">About</a></div></div></li><li><div class="wrap"><div class="label"><a href="#services">Services</a></div></div></li></ul></nav></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $source))))->toArray();
$frontend = array('blocks' => $result['serialized_blocks'], 'assets' => array_values(array_filter($result['assets'], static fn(array $asset): bool => 'editor' !== ($asset['stylesheet_target'] ?? 'both'))));
// Baseline after navigation target sharing: equivalent links and retained
// label boxes share markers and structural selectors instead of enumerating positions.
// Duplicate subject removal also removes a redundant single-arm :is().
// Keep serialized runtime metadata and every non-editor asset byte-locked.
if ('10342430f746de18376920587011b3979045dd0367edfdde00ac998f7e45f903' !== hash('sha256', json_encode($frontend))) {
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
// Reopened #4512: a whole-value var() shorthand must own the font
// family, even in the presence of lower-specificity conditional presets.
foreach (array('', '@container style(--preset: a){.w-p .item{font:14px sans-serif}}', '@media (min-width:1px){.w-p .item{font:14px sans-serif}}') as $conditional) {
    foreach (array('normal normal 700 16.875px/1.4 sora, sans-serif', 'var(--unresolved-font)') as $font) {
        $source = '<!doctype html><html><head><title>Navigation</title><style>:root{--para-font:' . $font . '}body{font-family:Arial, Helvetica, sans-serif;font-size:10px}#main :where(.menu-1).menu .item{font:var(--para-font)}' . $conditional . '</style></head><body><div id="main"><nav class="menu-1 menu w-p" style="--preset:a"><ul><li><div class="item"><a href="/about">About</a></div></li></ul></nav></div></body></html>';
        $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $source))))->toArray();
        $editorCss = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'editor' === ($asset['stylesheet_target'] ?? 'both')), 'content'));
        if (preg_match('/Arial|10px|14px/i', $editorCss)) throw new RuntimeException('Editor inherited a reset or lower-specificity preset: ' . $editorCss);
        if (!str_contains($font, 'var(')) {
            foreach (array('font-family:sora, sans-serif', 'font-size:16.875px', 'font-weight:700') as $declaration) {
                if (!str_contains($editorCss, $declaration)) throw new RuntimeException('Missing ' . $declaration . ': ' . $editorCss);
            }
        }
    }
}
// Real captures: ancestor-scoped fluid shorthand and a mobile-only winner.
$fluid = 'max(0.5px, 0.0125 * (var(--scaling-factor) - var(--scrollbar-width)))';
foreach (array(false, true) as $mobile) {
    $rule = '#main :where(.menu-1).menu .item{font:var(--para-font)}';
    if ($mobile) $rule = '@media screen and (max-width:1023px){' . $rule . '}';
    $source = '<!doctype html><html><head><title>Navigation</title><style>body{font-family:Arial, Helvetica, sans-serif;font-size:10px}#main :where(.menu-1){--para-font:normal normal 400 ' . $fluid . ' /1.4em sora, sans-serif}' . $rule . '</style></head><body><div id="main"><nav class="menu-1 menu"><ul><li><div class="item"><a href="/about">About</a></div></li></ul></nav></div></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $source))))->toArray();
    $editorCss = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'editor' === ($asset['stylesheet_target'] ?? 'both')), 'content'));
    if (preg_match('/Arial|10px/i', $editorCss)) throw new RuntimeException('Real capture inherited body reset: ' . $editorCss);
    foreach (array('font-family:sora, sans-serif', 'font-size:' . $fluid, 'font-weight:400', 'line-height:1.4em') as $declaration) {
        if (!str_contains($editorCss, $declaration)) throw new RuntimeException('Missing real-capture ' . $declaration . ': ' . $editorCss);
    }
    if ($mobile && !preg_match('/@media screen and \\(max-width:1023px\\)\\{[^{}]*\\{font-family:sora/', $editorCss)) throw new RuntimeException('Mobile typography lost its media condition: ' . $editorCss);
}
// A label box that centres the link (GoDaddy logo) centres it in the editor too.
$source = '<!doctype html><html><head><title>Navigation</title><style>.bar{display:flex;justify-content:center}.logo{text-align:center}</style></head><body><nav><ul><li><div class="bar"><div class="logo"><a href="/">Studio Name</a></div></div></li><li><a href="/x">X</a></li></ul></nav></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $source))))->toArray();
$dom = new DOMDocument();
@$dom->loadHTML(StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($result['serialized_blocks']));
foreach (iterator_to_array((new DOMXPath($dom))->query('//*[contains(@class,"blocks-engine-navigation-box-")]')) as $box) {
    while ($box->firstChild) $box->parentNode->insertBefore($box->firstChild, $box);
    $box->parentNode->removeChild($box);
}
$cascade = new StaticCssCascade($dom, implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'editor' === ($asset['stylesheet_target'] ?? 'both')), 'content')));
$aligned = array();
foreach ($dom->getElementsByTagName('a') as $link) $aligned[trim($link->textContent)] = $cascade->resolve($link, array('text-align'), array())['text-align'] ?? '';
if (array('Studio Name' => 'center', 'X' => '') !== $aligned) throw new RuntimeException('Editor link alignment: ' . json_encode($aligned));
echo "Navigation label-box typography reaches editor links without changing frontend output\n";
