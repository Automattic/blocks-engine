<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/linked-card-image.html');
// Supply the fixture's 320x180 raster without an external server or GD.
$chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
$png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 320, 180, 8, 2, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\x80", 960), 180))) . $chunk('IEND', '');
$source = str_replace('photo.png', 'data:image/png;base64,' . base64_encode($png), $source);
$out = (new HtmlTransformer())->transform($source)->toArray();
$css = implode("\n", array_column($out['assets'], 'content'));
$core = '.wp-block-image a{display:inline-block}.wp-block-image img{vertical-align:bottom;max-width:100%;height:auto}';
$native = '<!doctype html><style>' . $css . $core . '</style>' . $out['serialized_blocks'];
$chrome = getenv('CHROME_BIN') ?: trim((string) shell_exec('command -v google-chrome || command -v chromium || command -v chromium-browser'));
if ('' !== $chrome) {
    $document = '<!doctype html><iframe style="width:1440px;height:900px" id="source" srcdoc="' . htmlspecialchars($source, ENT_QUOTES) . '"></iframe>'
        . '<iframe style="width:1440px;height:900px" id="native" srcdoc="' . htmlspecialchars($native, ENT_QUOTES) . '"></iframe>' . <<<'JS'
<script>
window.onload = () => {
    const measure = id => {
        const doc = document.getElementById(id).contentDocument;
        return [...doc.querySelectorAll('.media')].map(media => ({
            image: media.querySelector('img').getBoundingClientRect().height,
            media: media.getBoundingClientRect().height,
            figure: media.querySelector('figure')?.getBoundingClientRect().height,
            linkDisplay: getComputedStyle(media.querySelector('a') || media.closest('a')).display,
            text: media.nextElementSibling.getBoundingClientRect().top,
            href: media.querySelector('a')?.getAttribute('href') || media.closest('a')?.getAttribute('href')
        }));
    };
    const result = document.createElement('pre');
    result.id = 'measurements';
    result.textContent = JSON.stringify({source: measure('source'), native: measure('native')});
    document.body.append(result);
};
</script>
JS;
    $file = tempnam(sys_get_temp_dir(), 'linked-card-image-');
    rename($file, $file . '.html');
    $file .= '.html';
    try {
        file_put_contents($file, $document);
        $output = shell_exec(HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --allow-file-access-from-files --virtual-time-budget=2000 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
        if (!preg_match('/<pre id="measurements">(.*?)<\/pre>/s', (string) $output, $match)) throw new RuntimeException('Browser did not report measurements.');
        $boxes = json_decode(html_entity_decode($match[1]), true, 512, JSON_THROW_ON_ERROR);
        if (2 !== count($boxes['source']) || 2 !== count($boxes['native'])) throw new RuntimeException('Both cards must remain native.');
        foreach ($boxes['source'] as $index => $box) {
            $actual = $boxes['native'][$index];
            if (144 !== $box['image'] || abs($actual['figure'] - $box['image']) > 0.02
                || abs($actual['media'] - $box['media']) > 0.02 || abs($actual['text'] - $box['text']) > 0.02
                || $actual['href'] !== $box['href'] || 'contents' !== $actual['linkDisplay']) {
                throw new RuntimeException('Card image geometry or destination changed: ' . $match[1]);
            }
        }
        echo 'Browser source/native: ' . $match[1] . "\n";
    } finally {
        unlink($file);
    }
}
// The same contract remains covered on PHP-only installations.
$dom = new DOMDocument();
@$dom->loadHTML($out['serialized_blocks']);
$xpath = new DOMXPath($dom);
$links = $xpath->query('//figure[contains(concat(" ", @class, " "), " blocks-engine-propagated-link ")]/a');
if (2 !== $links->length || !str_contains($css, ':root :where(.blocks-engine-propagated-link)>a:only-child{display:contents}')) {
    throw new RuntimeException('Both native image links must use the transparent propagated-link carrier.');
}
echo "Linked card image contract passed.\n";
