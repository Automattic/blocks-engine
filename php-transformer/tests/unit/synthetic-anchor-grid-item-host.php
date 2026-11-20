<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

// A classless link that is an item of an author grid is lowered into a
// synthetic paragraph host, which becomes the grid item. The authored
// `parent > a` rules must not double the link's padding (once on the host,
// once on the link), and its child-indexed item placement
// (`:last-child{justify-self:end}`) must reach the host that is now the item.
$html = '<div class="foot"><span>Studio</span><a href="#next">Scroll to explore</a></div>';
$author = 'body{margin:0;font:13px/1.5 Arial}.foot{display:grid;grid-template-columns:1fr 1fr;align-items:center;width:600px}'
    . '.foot>a:first-child{justify-self:start}.foot>a:last-child{justify-self:end}.foot>a{padding:12px 0}';
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
if (!preg_match('/<p class="[^"]*blocks-engine-synthetic-paragraph[^"]*"([^>]*)><a href="#next"/', $markup, $host)) {
    throw new RuntimeException('The grid-item link must keep its synthetic paragraph host.');
}
if (str_contains($host[1], 'padding')) {
    throw new RuntimeException('The host must not restate padding the projected `.foot > a` rule already gives the link: ' . $host[0]);
}
if (!preg_match('/\.foot>:where\(\.blocks-engine-synthetic-paragraph\):last-child:has\(>a\)\{justify-self:end\}/', $css)) {
    throw new RuntimeException('`.foot > a:last-child` item placement must reach the synthetic paragraph grid item.');
}
if (preg_match('/:where\(\.blocks-engine-synthetic-paragraph\)>a:last-child/', $css)) {
    throw new RuntimeException('Child-indexed pseudo-classes must move to the carrier, where the link is an only child.');
}

$chrome = getenv('CHROME_BIN') ?: trim((string) shell_exec('command -v google-chrome || command -v chromium || command -v chromium-browser'));
if ('' !== $chrome) {
    $measure = <<<'JS'
<script>
window.onload = () => {
    const link = document.querySelector('a[href="#next"]');
    const foot = link.closest('.foot');
    // The text's own box: a block link fills its column whether or not
    // the item is placed at the column end.
    const text = document.createRange();
    text.selectNodeContents(link);
    const t = text.getBoundingClientRect(), r = link.getBoundingClientRect(), f = foot.getBoundingClientRect();
    const result = document.createElement('pre');
    result.id = 'measurement';
    result.textContent = JSON.stringify({textLeft: Math.round(t.left - f.left), textRight: Math.round(f.right - t.right), linkHeight: Math.round(r.height), footHeight: Math.round(f.height)});
    document.body.append(result);
};
</script>
JS;
    $measureIn = static function (string $page) use ($chrome): array {
        $file = tempnam(sys_get_temp_dir(), 'grid-item-host-');
        rename($file, $file . '.html');
        $file .= '.html';
        try {
            file_put_contents($file, $page);
            $output = shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --virtual-time-budget=2000 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
            if (!preg_match('/<pre id="measurement">(.*?)<\/pre>/s', (string) $output, $match)) throw new RuntimeException('Browser did not report geometry.');
            return json_decode(html_entity_decode($match[1]), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            unlink($file);
        }
    };
    $source = $measureIn('<!doctype html><style>' . $author . '</style>' . $html . $measure);
    $converted = $measureIn('<!doctype html><style>' . $css . '</style>' . $markup . $measure);
    if ($source !== $converted) {
        throw new RuntimeException('Converted grid-item link geometry differs from source: ' . json_encode(array('source' => $source, 'converted' => $converted)));
    }
    echo 'Browser: ' . json_encode($converted) . "\n";
}
echo "Synthetic anchor grid item host passed\n";
