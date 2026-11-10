<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

// Exercise stylesheet assets, not a hand-written projection or an image reset.
$html = '<div class="logo"><a class="img-link" href="/"><img src="x.png" width="48" height="48"></a></div>';
$author = 'body{font:16px/25.6px Arial}.img-link{display:block}img{display:block}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><link rel="stylesheet" href="style.css">' . $html,
    'style.css' => $author,
)))->toArray();
$site = $result['source_reports']['compiled_site'];
$markup = $site['pages'][0]['block_markup'];
$css = '';
foreach ($site['assets'] as $asset) {
    if ('css' === $asset['kind']) $css .= $asset['content'] . "\n";
}
if (!str_contains($markup, '<figure') || !preg_match('/:where\(\.blocks-engine-semantic-[^)]+\)(?::not\(\.[^)]+\)){5}\{display:block\}/', $css)) {
    throw new RuntimeException('Retained native inner subjects must replay author weight above Core defaults.');
}
$chrome = getenv('CHROME_BIN') ?: trim((string) shell_exec('command -v google-chrome || command -v chromium || command -v chromium-browser'));
if ('' !== $chrome) {
    // The real Core selector, deliberately loaded AFTER the converted assets.
    $core = '.wp-block-image > a,.wp-block-image > figure > a{display:inline-block}';
    $page = '<!doctype html><style>' . $css . $core . '</style>' . $markup . <<<'JS'
<script>
window.onload = () => {
    const link = document.querySelector('a.img-link');
    const result = document.createElement('pre');
    result.id = 'measurement';
    result.textContent = JSON.stringify({display:getComputedStyle(link).display,height:link.closest('figure').getBoundingClientRect().height});
    document.body.append(result);
};
</script>
JS;
    $file = tempnam(sys_get_temp_dir(), 'inner-precedence-');
    rename($file, $file . '.html');
    $file .= '.html';
    try {
        file_put_contents($file, $page);
        $output = shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --virtual-time-budget=2000 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        if (!preg_match('/<pre id="measurement">(.*?)<\/pre>/s', (string) $output, $match)) throw new RuntimeException('Browser did not report geometry.');
        $box = json_decode(html_entity_decode($match[1]), true, 512, JSON_THROW_ON_ERROR);
        if ('block' !== $box['display'] || 48 !== $box['height']) throw new RuntimeException('Native inner author precedence failed: ' . $match[1]);
        echo 'Browser: ' . $match[1] . "\n";
    } finally {
        unlink($file);
    }
}
echo "Native inner author precedence passed\n";
