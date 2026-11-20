<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #7425: the wrapper keeps its identity, but its synthesized inner
// anchors must not become fresh blue links inside the white card text.
$html = <<<'HTML'
<a class="product-link" href="/products/vh-tier-i-train-rehab-program">
  <div class="product-block__title">VH Tier I Fitness | Program</div>
  <div class="product-price"><span class="product-price__amount">$250.00</span></div>
</a>
HTML;
$css = 'a{color:#1e4397}.product-link:not(.button){color:inherit}';
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ('' === $chrome) throw new RuntimeException('Chromium is required for the split link presentation contract.');

// Also exercise the existing undecorated-link projection alongside inheritance.
foreach (array('', '.product-link:not(.button){text-decoration:none}') as $decoration) {
    $sourceCss = $css . $decoration;
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="/style.css"></head><body>' . $html . '</body></html>',
            'style.css' => $sourceCss,
        ),
    ))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    $emittedCss = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'editor' !== ($asset['stylesheet_target'] ?? '')), 'content'));
    $rootClass = $plan['pages'][0]['document_metadata']['root_attributes']['class'] ?? '';
    $markup = $result['serialized_blocks'];
    if (2 !== substr_count($markup, '<a href="/products/vh-tier-i-train-rehab-program">')) {
        throw new RuntimeException('The fixture must split the source link onto the title and price: ' . $markup);
    }
    $values = array();
    foreach (array(array($html, $sourceCss, ''), array($markup, $emittedCss, $rootClass)) as [$body, $styles, $classes]) {
        $base = tempnam(sys_get_temp_dir(), 'split-link-');
        $temp = $base . '.html';
        unlink($base);
        try {
            file_put_contents($temp, '<!doctype html><html class="' . htmlspecialchars($classes, ENT_QUOTES) . '"><head><style>body{color:white}' . $styles . '</style></head><body>' . $body . '<script>document.body.dataset.paint=JSON.stringify([...document.querySelectorAll("a[href]")].map(a=>{const s=getComputedStyle(a);return [s.color,s.textDecorationLine]}));</script></body></html>');
            $output = (string) shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom ' . escapeshellarg('file://' . $temp) . ' 2>/dev/null');
            preg_match('/data-paint="([^"]*)"/', $output, $match);
            $values[] = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
        } finally {
            unlink($temp);
        }
    }
    $expected = array('rgb(255, 255, 255)', '' === $decoration ? 'underline' : 'none');
    if (array(array($expected), array($expected, $expected)) !== $values) {
        throw new RuntimeException('Split anchors must preserve source color and decoration: ' . json_encode($values) . "\n" . $markup . "\n" . $emittedCss);
    }
}
echo "Split link source presentation contract passed\n";
