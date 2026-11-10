<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\HeadlessChrome;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Real-site shape: the generated stage and viewport are static unless the
// carousel owns a containing block; the absolute source track retains grid-area.
$source = '<html><head><style>
body{margin:0}.slideshow.carousel{display:grid;grid-template-columns:auto minmax(0,1fr) auto;width:100%;height:355px}
.viewport{height:355px}.track-slider{position:absolute!important;width:100%;height:355px;grid-area:1/1/1/1}
.carousel__slide{position:absolute;width:100%}.controls{display:grid;grid-template-columns:1fr 1fr;align-items:center;height:100%}
.arrow{width:32px;height:32px}.previous{justify-self:var(--nav-button-prev-self-justify)}
.next{justify-self:var(--nav-button-next-self-justify)}.next svg{transform:scaleX(var(--nav-button-next-circle-scaleX-transform))}
</style></head><body><main><div class="slideshow carousel" style="--nav-button-prev-self-justify:start;--nav-button-next-self-justify:end;--nav-button-next-circle-scaleX-transform:-1">
<div class="controls"><button class="arrow previous" aria-label="Previous slide"><svg viewBox="0 0 20 20"><path d="M15 0L5 10L15 20"/></svg></button><button class="arrow next" aria-label="Next slide"><svg viewBox="0 0 20 20"><path d="M15 0L5 10L15 20"/></svg></button></div>
<div class="viewport"><div class="track-slider"><div class="carousel__slide"><p>It was a pleasure to work together.</p></div><div class="carousel__slide"><p>Second quote.</p></div><div class="carousel__slide"><p>Third quote.</p></div></div></div></div></main></body></html>';
// Model the captured stylesheet's element-hook alternative plus an inactive
// route-state alternative. Neither source rule may depend on the route matching.
$scopedSource = str_replace(
    array(
        '.arrow{width:32px;height:32px}',
        '<div class="controls"><button class="arrow previous"',
        '<button class="arrow next"',
    ),
    array(
        '.arrow{width:32px;height:32px}
:where(.blocks-engine-attribute-4fdd40aaa7a0-0).arrow.previous,:root.be-rs-576cd3f50d45787b .arrow.previous{position:absolute;left:var(--nav-button-offset);justify-self:start;transform:none}
:where(.blocks-engine-attribute-bf14915ba78c-0).arrow.next,:root.be-rs-576cd3f50d45787b .arrow.next{position:absolute;right:var(--nav-button-offset);justify-self:end;transform:scaleX(var(--nav-button-next-scaleX-transform))}
.slideshow.carousel{--nav-button-offset:100px;--nav-button-next-scaleX-transform:-1}',
        '<div class="controls"><button class="arrow previous blocks-engine-attribute-4fdd40aaa7a0-0"',
        '<button class="arrow next blocks-engine-attribute-bf14915ba78c-0"',
    ),
    $source
);
$scopedArtwork = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $scopedSource)))->toArray();
$scopedCss = implode("\n", array_column($scopedArtwork['assets'], 'content'));
$assert(str_contains($scopedCss, ':where(.blocks-engine-attribute-'), 'Compiled control rules retain their element-scoped style hooks.');
$artwork = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();

$assert(str_contains($artwork['serialized_blocks'], '"slideCount":3'), 'Artwork slideshow retains three slides.');
$assert(str_contains($artwork['serialized_blocks'], '"presentation":"slideshow"'), 'Artwork overlay is a slideshow.');
// count is deliberately initialized by callbacks.init from rendered children;
// items-1 means one visible slide, not one slide in the collection.
$assert(str_contains($artwork['serialized_blocks'], 'blocks-engine-authored-carousel--items-1'), 'Slideshow shows one of its three slides.');
$definition = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator())->definition('custom');
$assert(str_contains($definition['view_js'], 'context.count = slidesOf( ref ).length'), 'Runtime counts the rendered slides.');

// Exercise real layout without a WordPress or JS-package dependency. The DOM
// check runs after layout in headless Chrome, against actual projected CSS and
// the saved block markup, at both issue viewport widths.
$chrome = getenv('CHROME_PATH') ?: '/usr/bin/google-chrome';
$assert(is_executable($chrome), 'Chrome is required for the slideshow layout contract.');
$directory = '/tmp/artwork-slideshow-' . bin2hex(random_bytes(6));
mkdir($directory);
try {
    foreach (array(array($artwork, 1440, 0), array($artwork, 390, 0), array($scopedArtwork, 1440, 100), array($scopedArtwork, 390, 100)) as [$artifact, $width, $offset]) {
        $css = $definition['assets']['style.css'] . "\n" . implode("\n", array_column($artifact['assets'], 'content'));
        $document = '<!doctype html><html><head><style>' . $css . ' body{width:' . $width . 'px}</style></head><body>' . $artifact['serialized_blocks'] . '<script>
const root = document.querySelector(".blocks-engine-authored-carousel");
const stage = root.querySelector(".blocks-engine-authored-carousel__source-stage");
const track = root.querySelector(".blocks-engine-authored-carousel__track");
const slide = track.firstElementChild;
const next = root.querySelector("[data-carousel-next]");
const prev = root.querySelector("[data-carousel-previous]");
const box = el => el.getBoundingClientRect();
const text = document.createRange(); text.selectNodeContents(slide.querySelector("p"));
const result = {
    root: box(root).width, stage: box(stage).width, slide: box(slide).width, text: text.getBoundingClientRect().width,
    column: getComputedStyle(stage).gridColumnStart,
    trackPosition: getComputedStyle(track).position, trackArea: getComputedStyle(track).gridArea,
    track: box(track).width,
    visibility: getComputedStyle(slide).visibility, opacity: getComputedStyle(slide).opacity,
    nextTransform: getComputedStyle(next.querySelector("svg")).transform,
    buttonTransform: getComputedStyle(next).transform,
    previousJustify: getComputedStyle(prev).justifySelf, nextJustify: getComputedStyle(next).justifySelf,
    grid: getComputedStyle(next.parentElement).display,
    left: box(prev).left - box(root).left, right: box(root).right - box(next).right
};
document.body.innerHTML = "<pre id=measurement>" + JSON.stringify(result) + "</pre>";
</script></body></html>';
        file_put_contents($directory . '/fixture.html', $document);
        $command = HeadlessChrome::tmpdirPrefix() . escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --no-proxy-server --user-data-dir=' . escapeshellarg($directory . '/profile')
            . ' --window-size=' . $width . ',800 --dump-dom ' . escapeshellarg('file://' . $directory . '/fixture.html') . ' 2>/dev/null';
        exec($command, $lines, $exitCode);
        $dom = implode("\n", $lines);
        $lines = array();
        $assert(0 === $exitCode && 1 === preg_match('/<pre id="measurement">(.*?)<\/pre>/s', $dom, $matches), 'Browser emits layout measurements.');
        $measure = json_decode(html_entity_decode($matches[1]), true, 512, JSON_THROW_ON_ERROR);
        $assert('1' === $measure['column'], $width . ': overlay stage spans the grid columns: ' . json_encode($measure));
        $assert('absolute' === $measure['trackPosition'] && '1 / 1 / 1 / 1' === $measure['trackArea'], $width . ': track retains source absolute positioning and grid placement.');
        $assert(abs($measure['stage'] - $measure['track']) < 1, $width . ': source grid placement cannot collapse the track into an outer control column: ' . json_encode($measure));
        $assert($measure['stage'] > 0 && abs($measure['root'] - $measure['stage']) < 1 && abs($measure['stage'] - $measure['slide']) < 1 && $measure['text'] > 0, $width . ': active slide and quote have stage width, not zero width.');
        $assert('visible' === $measure['visibility'] && '1' === $measure['opacity'], $width . ': active quote is visible.');
        $assert('matrix(-1, 0, 0, 1, 0, 0)' === $measure['nextTransform'], $width . ': next artwork retains its source mirror.');
        if (100 === $offset) {
            $assert('matrix(-1, 0, 0, 1, 0, 0)' === $measure['buttonTransform'], $width . ': scoped next control retains its source mirror: ' . json_encode($measure));
            $assert('start' === $measure['previousJustify'] && 'end' === $measure['nextJustify'], $width . ': scoped controls retain source justification.');
        }
        $assert('grid' === $measure['grid'] && abs($measure['left'] - $offset) < 1 && abs($measure['right'] - $offset) < 1, $width . ': controls retain the source grid and offset: ' . json_encode($measure));
    }
} finally {
    exec('rm -rf ' . escapeshellarg($directory));
}
echo "Artwork slideshow layout contract passed.\n";
