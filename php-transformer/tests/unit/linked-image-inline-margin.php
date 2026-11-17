<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

// Reduced public-compile fixture from #6311. Adjacent links have no whitespace.
$source = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Icon margin</title><style>
body{margin:0;font-family:sans-serif}.row{text-align:center;background:#eee}
.icon{overflow:hidden;display:inline-block;vertical-align:middle;line-height:0;font-size:10pt}
</style></head><body><main><h1>Hello</h1>
<div class="row"><a href="https://www.instagram.com/example/" target="_blank" class="icon" style="margin: 8px;width: 26px;height: 26px"><img src="/media/ig.jpg" alt="Instagram" style="width: 26px;height: 26px" width="26" height="26"></a><a href="https://www.linkedin.com/in/example/" target="_blank" class="icon" style="margin: 8px;width: 26px;height: 26px"><img src="/media/li.jpg" alt="LinkedIn" style="width: 26px;height: 26px" width="26" height="26"></a></div>
<p>After</p></main></body></html>
HTML;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$site = $result['source_reports']['compiled_site'];
$markup = $site['pages'][0]['block_markup'];
$css = '';
foreach ($site['assets'] as $asset) {
    if ('css' === $asset['kind']) $css .= $asset['content'] . "\n";
}
if (2 !== substr_count($markup, '<!-- wp:image ') || 2 !== substr_count($markup, '"linkDestination":"custom"')) {
    throw new RuntimeException('Both linked images must remain native custom-link blocks.');
}
$dom = new DOMDocument();
@$dom->loadHTML($markup);
$links = (new DOMXPath($dom))->query('//figure/a');
if (2 !== $links->length) throw new RuntimeException('Expected two rendered image links.');
foreach ($links as $link) {
    if (!preg_match('/\bbe-inline-geometry-[a-f0-9-]+\b/', $link->getAttribute('class'), $class)
        || !preg_match('/\.' . preg_quote($class[0], '/') . '\{([^}]+)\}/', $css, $rule)) {
        throw new RuntimeException('Inline box carrier must belong to the anchor.');
    }
    foreach (array('margin' => '8px', 'width' => '26px', 'height' => '26px') as $property => $value) {
        if (!preg_match('/(?:^|;)\s*' . $property . ':' . $value . '(?:\s*!important)?(?:;|$)/', $rule[1])) {
            throw new RuntimeException('Anchor must retain ' . $property . ':' . $value . '; got ' . $rule[1]);
        }
    }
}

$chrome = getenv('CHROME_BIN') ?: trim((string) shell_exec('command -v google-chrome || command -v chromium || command -v chromium-browser'));
if ('' !== $chrome) {
    $native = '<!doctype html><style>.wp-block-image a{display:inline-block}' . $css . '</style>' . $markup;
    $pixel = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    $replace = static fn (string $html): string => preg_replace('#src="[^"]+"#', 'src="' . $pixel . '"', $html);
    $document = '<!doctype html><iframe id="source" srcdoc="' . htmlspecialchars($replace($source), ENT_QUOTES) . '"></iframe>'
        . '<iframe id="native" srcdoc="' . htmlspecialchars($replace($native), ENT_QUOTES) . '"></iframe>' . <<<'JS'
<script>
window.onload = () => {
    const measure = id => {
        const doc = document.getElementById(id).contentDocument;
        return { row: doc.querySelector('.row').getBoundingClientRect().height,
            links: [...doc.querySelectorAll('a.icon')].map(a => {
                const box = a.getBoundingClientRect(), style = getComputedStyle(a);
                return { width: box.width, height: box.height,
                    margins: [style.marginTop, style.marginRight, style.marginBottom, style.marginLeft] };
            }) };
    };
    const pre = document.createElement('pre');
    pre.id = 'measurements';
    pre.textContent = JSON.stringify({source: measure('source'), native: measure('native')});
    document.body.append(pre);
};
</script>
JS;
    $file = tempnam(sys_get_temp_dir(), 'linked-image-margin-');
    rename($file, $file . '.html');
    $file .= '.html';
    try {
        file_put_contents($file, $document);
        $output = shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --allow-file-access-from-files --virtual-time-budget=2000 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        if (!preg_match('/<pre id="measurements">(.*?)<\/pre>/s', (string) $output, $match)) throw new RuntimeException('Browser did not report measurements.');
        $boxes = json_decode(html_entity_decode($match[1]), true, 512, JSON_THROW_ON_ERROR);
        foreach (array('source', 'native') as $role) {
            if (42 !== $boxes[$role]['row'] || 2 !== count($boxes[$role]['links'])) throw new RuntimeException('Row must remain 42px: ' . $match[1]);
            foreach ($boxes[$role]['links'] as $box) {
                if (26 !== $box['width'] || 26 !== $box['height'] || array('8px', '8px', '8px', '8px') !== $box['margins']) {
                    throw new RuntimeException('Link box changed: ' . $match[1]);
                }
            }
        }
        echo 'Browser source/native: ' . $match[1] . "\n";
    } finally {
        unlink($file);
    }
}
echo "Linked image inline margin passed\n";
