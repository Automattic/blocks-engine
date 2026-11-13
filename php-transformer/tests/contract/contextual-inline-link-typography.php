<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fixture = <<<'HTML'
<div class="page"><div class="card"><div class="detail"><span class="city">Makkah</span><strong class="name"><a href="/h/a/">Hotel A</a></strong></div><div class="detail"><span class="city">Madinah</span><strong class="name">Hotel B</strong></div></div></div>
HTML;
$style = '.name{font:600 12px/1.45 sans-serif;color:#35463f}.page .card .name{font-size:14px;font-weight:750;color:#315a0b}';
$chrome = getenv('CHROME_PATH') ?: trim((string) shell_exec('command -v chromium || command -v google-chrome || true'));
if ('' === $chrome) throw new RuntimeException('Chromium is required for the typography cascade contract.');

foreach (array('issue fixture', 'responsive', 'emphasis', 'authored inline override') as $case) {
    $html = $fixture;
    $css = $style . 'a{color:inherit}';
    if ('responsive' === $case) $css .= '@media(max-width:600px){.page .card .name{font-size:16px}}';
    if ('emphasis' === $case) $html = str_replace(array('<strong', '</strong>'), array('<em', '</em>'), $html);
    if ('authored inline override' === $case) $html = str_replace('class="name"', 'class="name" style="font-size:18px;font-weight:800;color:#123456"', $html);
    $files = array('style.css' => $css);
    foreach (array('index.html', 'second.html', 'third.html', 'fourth.html') as $path) {
        $files[$path] = '<!doctype html><html><head><link rel="stylesheet" href="/style.css"></head><body>' . (in_array($path, array('index.html', 'second.html'), true) ? $html : str_replace(array('<strong', '</strong>', '<em', '</em>'), array('<p', '</p>', '<p', '</p>'), $html)) . '</body></html>';
    }
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    $page = array_values(array_filter($plan['pages'], static fn(array $page): bool => 'index.html' === $page['source_path']))[0];
    $markup = $result['serialized_blocks'];
    $emittedCss = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '') && 'editor' !== ($asset['stylesheet_target'] ?? '') && array_filter($asset['scopes'] ?? array(), static fn(array $scope): bool => 'global' === $scope['kind'] || 'index.html' === ($scope['source_path'] ?? ''))), 'content'));
    if (!str_contains($emittedCss, ':where(:root:not(.be-rs-')) throw new RuntimeException('Fixture must exercise route-guarded author CSS: ' . $emittedCss);
    foreach (array(390, 768, 1440) as $width) {
        $expected = 'authored inline override' === $case ? array('18px', '800', 'rgb(18, 52, 86)') : array('responsive' === $case && 390 === $width ? '16px' : '14px', '750', 'rgb(49, 90, 11)');
        $rootClass = htmlspecialchars($page['document_metadata']['root_attributes']['class'], ENT_QUOTES);
        $document = '<!doctype html><html class="' . $rootClass . '"><head><style>' . $emittedCss . '</style></head><body>' . $markup . '<script>const marks=[...document.querySelectorAll("mark.name")];const values=marks.map(m=>{const s=getComputedStyle(m);return [s.fontSize,s.fontWeight,s.color]});const link=document.querySelector("mark.name a");if(link){const s=getComputedStyle(link);values.push([s.fontSize,s.fontWeight,s.color])}parent.document.body.dataset.typography=JSON.stringify(values);parent.document.body.dataset.width=innerWidth;</script></body></html>';
        $base = tempnam(sys_get_temp_dir(), 'inline-typography-');
        $temp = $base . '.html';
        unlink($base);
        // Headless Chromium clamps top-level windows to 500px. An iframe
        // provides the exact captured CSS viewport, including 390px.
        file_put_contents($temp, '<!doctype html><body><iframe style="border:0;width:' . $width . 'px;height:900px" srcdoc="' . htmlspecialchars($document, ENT_QUOTES) . '"></iframe></body>');
        try {
            $output = (string) shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --force-device-scale-factor=1 --window-size=' . $width . ',900 --dump-dom ' . escapeshellarg('file://' . $temp) . ' 2>/dev/null');
            preg_match('/data-typography="([^"]*)"/', $output, $match);
            $values = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES), true);
            if (!str_contains($output, 'data-width="' . $width . '"') || array($expected, $expected, $expected) !== $values) throw new RuntimeException($case . ' at ' . $width . ': ' . json_encode($values) . "\n" . $markup . "\n" . $emittedCss);
        } finally {
            unlink($temp);
        }
    }
}
fwrite(STDOUT, "Contextual inline link typography contract passed\n");
