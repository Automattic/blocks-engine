<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$source = '<!doctype html><html><head><meta charset="utf-8"><title>Reel</title><style>
.gallery-reel{height:240px;padding:20px}
.gallery-reel-wrapper{position:relative;display:flex;flex-direction:column;width:100%;height:100%}
.gallery-reel-list{position:absolute;top:0;left:59px;right:59px;height:100%;overflow:hidden}
.gallery-reel-item{position:absolute;top:0;left:0;margin:0}
.gallery-reel-item-wrapper,.gallery-reel-item-src{position:absolute;top:0;left:0;width:100%;height:100%;overflow:hidden}
.gallery-reel-item img{position:absolute;display:block;left:0;top:0;width:100%;height:100%;object-fit:cover}
.gallery-reel-controls{position:absolute;display:flex;justify-content:space-between;top:0;left:0;width:100%;height:100%;pointer-events:none}
.gallery-reel-control{display:flex;align-items:center}
.gallery-reel-control-btn{pointer-events:all;background:none;border:0;padding:8px}
</style></head><body><main><div class="gallery-reel"><div class="gallery-reel-wrapper"><div class="gallery-reel-list">'
    . str_repeat('<figure class="gallery-reel-item" style="height:200px;width:200px"><div class="gallery-reel-item-wrapper"><div class="gallery-reel-item-src"><img alt="Logo" src="logo.webp" width="300" height="300"></div></div></figure>', 4)
    . '</div><div class="gallery-reel-controls"><div class="gallery-reel-control"><button class="gallery-reel-control-btn" aria-label="Previous Slide">&larr;</button></div><div class="gallery-reel-control"><button class="gallery-reel-control-btn" aria-label="Next Slide">&rarr;</button></div></div></div></div><p>After</p></main></body></html>';
// A square WebP keeps the reduced page self-contained, without image tooling.
$logo = base64_decode('UklGRtoAAABXRUJQVlA4IM4AAACQFgCdASosASwBPm02mUmkIyKhICgAgA2JaW7hd2EbQAnsA99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtWAA/v/rAAAAAAAAAAAAAAAAAA==');
$artwork = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $source,
    'logo.webp' => $logo,
)))->toArray();
if (!str_contains($artwork['serialized_blocks'], 'blocks-engine-authored-carousel--positioned-stage')) {
    throw new RuntimeException('The absolute percentage-height track must opt into scoped stage sizing.');
}
$definition = (new AuthoredCarouselBlockGenerator())->definition('custom');
$css = $definition['assets']['style.css'] . "\n" . implode("\n", array_column($artwork['assets'], 'content'));
$directory = '/tmp/positioned-carousel-' . bin2hex(random_bytes(6));
mkdir($directory);
try {
    foreach (array(1440, 768, 390) as $width) {
        foreach (array('source' => $source, 'projected' => '<style>' . $css . '</style>' . $artwork['serialized_blocks']) as $kind => $markup) {
            $markup = preg_replace('/src="[^"]*"/', 'src="data:image/webp;base64,' . base64_encode($logo) . '"', $markup);
            $document = '<!doctype html><style>body{width:' . $width . 'px}</style>' . $markup . '<script>
const root = document.querySelector(".blocks-engine-authored-carousel") || document.querySelector(".gallery-reel-wrapper");
const track = root.querySelector(".blocks-engine-authored-carousel__track") || root.querySelector(".gallery-reel-list");
const image = track.querySelector("img");
const box = el => ({width: el.getBoundingClientRect().width, height: el.getBoundingClientRect().height});
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
            echo $kind . ' ' . $width . ': ' . json_encode($measure) . "\n";
            if (240 !== $measure['root']['height'] || 240 !== $measure['track']['height'] || 200 !== $measure['image']['height'] || 200 !== $measure['image']['width']) {
                throw new RuntimeException('The positioned track must fill the 240px stage and retain 200px square slides.');
            }
        }
    }
} finally {
    exec('rm -rf ' . escapeshellarg($directory));
}
