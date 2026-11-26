<?php
declare(strict_types=1);

// A track that stays in flow but whose slides are all absolutely positioned
// has no content height: only its height:100% against the sized flex root
// gives it a box. The inserted stage and viewport must keep that chain, or the
// track collapses to 0px and the slides paint below the carousel.

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$slide = static fn (int $offset, string $alt): string => '<figure class="gallery-item" style="width:300px;height:400px;transform:translateX(' . $offset . 'px)"><div class="gallery-item-frame"><img src="slide.webp" alt="' . $alt . '" width="300" height="300"></div></figure>';
$source = '<!doctype html><html><head><meta charset="utf-8"><title>Gallery</title><style>
body{margin:0}
.gallery{height:480px}
.gallery-wrapper{display:flex;flex-direction:column;height:100%}
.gallery-list{position:relative;height:100%;margin:0 0 15px}
.gallery-item{position:absolute;top:0;left:0;margin:0}
.gallery-item-frame,.gallery-item img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover}
.gallery-controls{display:flex}
</style></head><body><main><div class="gallery"><div class="gallery-wrapper"><div class="gallery-list">'
    . $slide(0, 'Slide one') . $slide(310, 'Slide two') . $slide(620, 'Slide three')
    . '</div><div class="gallery-controls"><button aria-label="Previous Slide">&larr;</button><button aria-label="Next Slide">&rarr;</button></div></div></div><p>After</p></main></body></html>';
// The same square WebP as positioned-carousel-stage.php keeps the page self-contained.
$image = base64_decode('UklGRtoAAABXRUJQVlA4IM4AAACQFgCdASosASwBPm02mUmkIyKhICgAgA2JaW7hd2EbQAnsA99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtWAA/v/rAAAAAAAAAAAAAAAAAA==');
$artwork = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $source,
    'slide.webp' => $image,
)))->toArray();
if (!str_contains($artwork['serialized_blocks'], 'blocks-engine-authored-carousel--positioned-stage')) {
    throw new RuntimeException('An in-flow track of out-of-flow slides must carry the sized stage through the inserted wrappers.');
}
$definition = (new AuthoredCarouselBlockGenerator())->definition('custom');
$css = $definition['assets']['style.css'] . "\n" . implode("\n", array_column($artwork['assets'], 'content'));
$directory = '/tmp/out-of-flow-slides-carousel-' . bin2hex(random_bytes(6));
mkdir($directory);
try {
    foreach (array(1440, 768, 390) as $width) {
        $markup = preg_replace('/src="[^"]*"/', 'src="data:image/webp;base64,' . base64_encode($image) . '"', '<style>' . $css . '</style>' . $artwork['serialized_blocks']);
        $document = '<!doctype html><style>body{margin:0;width:' . $width . 'px}</style>' . $markup . '<script>
const root = document.querySelector(".blocks-engine-authored-carousel");
const track = root.querySelector(".blocks-engine-authored-carousel__track");
const image = track.querySelector("img");
const box = el => { const r = el.getBoundingClientRect(); return {top: r.top, bottom: r.bottom, width: r.width, height: r.height}; };
document.body.innerHTML = "<pre id=measurement>" + JSON.stringify({root:box(root),track:box(track),image:box(image)}) + "</pre>";
</script>';
        file_put_contents($directory . '/fixture.html', $document);
        $chrome = getenv('CHROME_PATH') ?: '/usr/bin/google-chrome';
        $command = HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --no-proxy-server --user-data-dir=' . escapeshellarg($directory . '/profile')
            . ' --window-size=' . $width . ',800 --dump-dom ' . escapeshellarg('file://' . $directory . '/fixture.html') . ' 2>/dev/null';
        $lines = array();
        exec($command, $lines, $exitCode);
        if (0 !== $exitCode || 1 !== preg_match('/<pre id="measurement">(.*?)<\/pre>/s', implode("\n", $lines), $matches)) {
            throw new RuntimeException('Browser must emit carousel geometry.');
        }
        $measure = json_decode(html_entity_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);
        echo 'projected ' . $width . ': ' . json_encode($measure) . "\n";
        if (480 !== (int) $measure['root']['height'] || 400 > $measure['track']['height']) {
            throw new RuntimeException('The track must keep the 480px root height instead of collapsing to 0px.');
        }
        if (400 !== (int) $measure['image']['height'] || 300 !== (int) $measure['image']['width'] || $measure['image']['bottom'] > $measure['root']['bottom']) {
            throw new RuntimeException('The 300x400 slides must paint inside the carousel root, not below it.');
        }
    }
} finally {
    exec('rm -rf ' . escapeshellarg($directory));
}
echo "PASS out-of-flow slides carousel stage\n";
