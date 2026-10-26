<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Reduced page from #3449: five 51px icons, 10px author margins, and
// whitespace in the parent's 13px Arial (not the link's font).
$source = <<<'HTML'
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Icons</title><style>
body{margin:0;font:13px Arial,sans-serif}
.wrap{width:400px}
.menu{float:right;width:120px;height:20px;margin:0;background:#ccc}
.social{float:right}
.social a{margin-left:10px}
.social img{width:51px;height:51px}
</style></head><body><main><div class="wrap">
<ul class="menu"><li>Menu</li></ul>
<div class="social">
  <a href="/a"><img src="/a.png" alt="A"></a>
  <a href="/b"><img src="/b.png" alt="B"></a>
  <a href="/c"><img src="/c.png" alt="C"></a>
  <a href="/d"><img src="/d.png" alt="D"></a>
  <a href="/e"><img src="/e.png" alt="E"></a>
</div></div></main></body></html>
HTML;
$compile = static fn (string $html): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$result = $compile($source);
if (in_array('--inspect', $argv, true)) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
$site = $result['source_reports']['compiled_site'];
$markup = $site['pages'][0]['block_markup'];
$css = '';
foreach ($site['assets'] as $asset) {
    if ('css' === $asset['kind']) $css .= $asset['content'] . "\n";
}
if (5 !== substr_count($markup, '<!-- wp:image ') || 4 !== substr_count($markup, '<figure class="wp-block-image is-resized blocks-engine-synthetic-image-figure blocks-engine-synthetic-image-figure-inline blocks-engine-synthetic-image-gap"')
    || !str_contains($css, '::before{content:" ";white-space:normal}')) {
    throw new RuntimeException('Site compilation must retain exactly four real spaces and five native images.');
}
// No source separator must mean no generated separator (including flex whitespace).
foreach (array(str_replace("</a>\n  <a", '</a><a', $source), str_replace('.social{float:right}', '.social{display:flex}', $source)) as $control) {
    $controlSite = $compile($control)['source_reports']['compiled_site'];
    if (str_contains($controlSite['pages'][0]['block_markup'], 'blocks-engine-synthetic-image-gap')) {
        throw new RuntimeException('Do not invent spaces for adjacent markup or flex items.');
    }
}

// Browser evidence without a JS package dependency. The structural assertions
// above also run on PHP-only installations; Chrome runs the layout comparison
// wherever it is installed (CHROME_BIN may select another Chromium binary).
$chrome = getenv('CHROME_BIN') ?: trim((string) shell_exec('command -v google-chrome || command -v chromium || command -v chromium-browser'));
if ('' !== $chrome) {
    // Core's image link is inline-block; its stylesheet is supplied by WP.
    $native = '<!doctype html><style>.wp-block-image a{display:inline-block}' . $css . '</style>' . $markup;
    $pixel = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    $source = preg_replace('#src="/[a-e]\\.png"#', 'src="' . $pixel . '"', $source);
    $native = preg_replace('#src="/[a-e]\\.png"#', 'src="' . $pixel . '"', $native);
    $document = '<!doctype html><iframe style="width:800px" id="source" srcdoc="' . htmlspecialchars($source, ENT_QUOTES) . '"></iframe>'
        . '<iframe style="width:800px" id="native" srcdoc="' . htmlspecialchars($native, ENT_QUOTES) . '"></iframe>' . <<<'JS'
<script>
window.onload = () => {
    const measure = id => {
        const doc = document.getElementById(id).contentDocument;
        const images = [...doc.querySelectorAll('img')];
        return { x: images.map(img => img.getBoundingClientRect().x), width: images[0].closest('.social').getBoundingClientRect().width };
    };
    const result = document.createElement('pre');
    result.id = 'measurements';
    result.textContent = JSON.stringify({source: measure('source'), native: measure('native')});
    document.body.append(result);
};
</script>
JS;
    $file = tempnam(sys_get_temp_dir(), 'linked-image-gap-');
    rename($file, $file . '.html');
    $file .= '.html';
    try {
        file_put_contents($file, $document);
        $output = shell_exec('TMPDIR=/tmp ' . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --allow-file-access-from-files --virtual-time-budget=2000 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        if (!preg_match('/<pre id="measurements">(.*?)<\/pre>/s', (string) $output, $match)) throw new RuntimeException('Browser did not report measurements.');
        $boxes = json_decode(html_entity_decode($match[1]), true, 512, JSON_THROW_ON_ERROR);
        foreach (array('source', 'native') as $role) {
            if (5 !== count($boxes[$role]['x'])) throw new RuntimeException('Browser must render all five icons.');
        }
        if (abs($boxes['source']['width'] - $boxes['native']['width']) > 0.02) throw new RuntimeException('Row width changed: ' . $match[1]);
        for ($i = 1; $i < 5; ++$i) {
            $pitch = $boxes['source']['x'][$i] - $boxes['source']['x'][$i - 1];
            $nativePitch = $boxes['native']['x'][$i] - $boxes['native']['x'][$i - 1];
            if ($pitch <= 61 || abs($pitch - $nativePitch) > 0.02) throw new RuntimeException('Space plus author margin changed: ' . $match[1]);
        }
        echo 'Browser source/native: ' . $match[1] . "\n";
    } finally {
        unlink($file);
    }
}
echo "Linked image whitespace gap passed\n";
