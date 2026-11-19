<?php
declare(strict_types=1);

// A slideshow whose track and slides fill a sized root through height:100%
// must keep that fill through the inserted stage wrappers (#6808).

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = static function (bool $chain): string {
    $css = '.hero{height:100vh}'
        . ($chain ? '.hero .carousel,.hero .carousel-inner,.hero .carousel-item,.hero .carousel-item img{height:100%;width:100%}' : '')
        . '.carousel{position:relative}.carousel-inner{position:relative;width:100%;overflow:hidden}.carousel-item{position:relative;display:none;float:left;width:100%;margin-right:-100%}.carousel-item.active{display:block}';
    $slides = '';
    foreach (array(1, 2, 3) as $n) {
        $slides .= '<div class="carousel-item' . (1 === $n ? ' active' : '') . '"><img src="https://example.com/' . $n . '.jpg" width="1600" height="1077" alt="Slide ' . $n . '"></div>';
    }
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body><main><div class="hero"><div id="hero" class="carousel slide"><div class="carousel-inner">' . $slides
        . '</div><button class="carousel-control-prev" type="button" data-bs-target="#hero" data-bs-slide="prev">Previous</button><button class="carousel-control-next" type="button" data-bs-target="#hero" data-bs-slide="next">Next</button></div></div></main></body></html>';
    $markup = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'];
    if (1 !== preg_match('/<div[^>]*class="[^"]*\bblocks-engine-authored-carousel\b[^"]*"[^>]*>/', $markup, $match)) {
        throw new RuntimeException('The slideshow must convert to an authored carousel.');
    }
    return $match[0];
};

if (!str_contains($root(true), 'blocks-engine-authored-carousel--positioned-stage')) {
    throw new RuntimeException('A height:100% chain must make the converted stage fill the carousel root.');
}
if (str_contains($root(false), 'blocks-engine-authored-carousel--positioned-stage')) {
    throw new RuntimeException('A carousel without the height chain must keep its natural-ratio stage.');
}
echo "PASS percentage-chain carousel stage\n";
