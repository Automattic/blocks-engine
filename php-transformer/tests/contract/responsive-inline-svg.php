<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/responsive-inline-svg.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) $result['serialized_blocks'];
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if (!preg_match('/<img\b[^>]*class="[^"]*\b(be-inline-geometry-[a-f0-9]+)\b[^\"]*"[^>]*>/', $markup, $image)) {
    throw new RuntimeException('Materialized SVG must retain its geometry carrier.');
}
if (preg_match('/style="[^"]*(?:width|height):/', $image[0])) {
    throw new RuntimeException('Stylesheet sizing must not be frozen inline: ' . $image[0]);
}
foreach (array('width', 'height') as $axis) {
    if (!preg_match('/\.' . $image[1] . '\{[^}]*' . $axis . ':82px/', $css)
        || !preg_match('/@media\s*\(max-width:\s*560px\)\s*\{\.' . $image[1] . '\{[^}]*' . $axis . ':50px/', $css)) {
        throw new RuntimeException('Carrier must preserve base and breakpoint ' . $axis . '.');
    }
}
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ('' !== $chrome) {
    $file = tempnam(sys_get_temp_dir(), 'responsive-svg-');
    rename($file, $file . '.html');
    $file .= '.html';
    try {
        // Load the materialized artwork too: a broken image's alt-text box is
        // not the replaced-element box whose geometry this contract measures.
        $renderedMarkup = $markup;
        foreach ($result['assets'] as $asset) {
            if ('svg' === ($asset['kind'] ?? '')) {
                $renderedMarkup = str_replace($asset['path'], 'data:image/svg+xml;base64,' . base64_encode($asset['content']), $renderedMarkup);
            }
        }
        foreach (array(390 => 50, 1440 => 82) as $width => $size) {
            foreach (array($html, '<style>' . $css . '</style>' . $renderedMarkup) as $page) {
                $probe = '<script>addEventListener("load",()=>{const r=document.querySelector("svg,img").getBoundingClientRect();parent.document.body.dataset.box=JSON.stringify([r.width,r.height])});</script>';
                $frame = $page . $probe;
                file_put_contents($file, '<!doctype html><body><iframe style="border:0;width:' . $width . 'px;height:900px" srcdoc="' . htmlspecialchars($frame, ENT_QUOTES) . '"></iframe></body>');
                $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom --virtual-time-budget=1000 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
                preg_match('/data-box="([^"]+)"/', (string) $output, $match);
                $box = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
                if (array($size, $size) !== $box) {
                    throw new RuntimeException('Expected ' . $size . 'x' . $size . ' at ' . $width . 'px; got ' . json_encode($box));
                }
            }
        }
    } finally {
        unlink($file);
    }
}
echo "Responsive inline SVG contract passed.\n";
