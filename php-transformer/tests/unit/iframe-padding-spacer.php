<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Tests\Support\IframeEmbedRendererFixture;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

// Reduced single-page fixture from #6380, including the authored size attributes.
$source = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Map</title><style>
body{margin:0;font-family:sans-serif}.col{width:464px;margin:40px}
.wrap{line-height:0;position:relative;width:100%}
.frame{border:none;position:absolute;top:0;left:0;width:100%;height:100%}
</style></head><body><main><h1>Location</h1><div class="col"><div class="wrap"><div style="padding-top: 74.0740740741%"></div><iframe class="frame" src="https://maps-api-ssl.google.com/maps?ll=34.2168,-77.8844&amp;output=embed&amp;z=17" width="464" height="344"></iframe></div></div><p>Below the map.</p></main></body></html>
HTML;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$result = (new HtmlTransformer())->transform($source)->toArray();
$assert(! str_contains($result['serialized_blocks'], '<!-- wp:html'), 'The sizer and iframe must remain editable, without raw HTML fallback.');
$assert(str_contains($result['serialized_blocks'], 'blocks-engine-empty-visual-group'), 'The geometry-bearing empty element survives as an editable group.');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'Saved blocks must remain editor-valid.');

// Resolve the actual rendered markup, not source styles or internal block attrs.
// The cascade supplies declarations; this fixture's simple used-height equation
// is padding percentage * containing width, with an absolute 100%-height frame.
$measure = static function (string $html, string $css) use ($assert): float {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $cascade = new StaticCssCascade($dom, $css);
    $frame = $dom->getElementsByTagName('iframe')->item(0);
    $assert($frame instanceof DOMElement, 'The iframe survives conversion.');
    $wrap = $frame->parentNode;
    $assert($wrap instanceof DOMElement, 'The iframe retains its containing box.');
    $frameStyle = $cascade->resolve($frame, array('position', 'height', 'width', 'top', 'left'), array());
    $wrapStyle = $cascade->resolve($wrap, array('position', 'width'), array());
    $assert('absolute' === ($frameStyle['position'] ?? '') && '100%' === ($frameStyle['height'] ?? '') && '100%' === ($frameStyle['width'] ?? ''), 'The frame still fills the positioned wrapper.');
    $assert('0' === ($frameStyle['top'] ?? '') && '0' === ($frameStyle['left'] ?? ''), 'The frame starts at the wrapper origin.');
    $assert('relative' === ($wrapStyle['position'] ?? '') && '100%' === ($wrapStyle['width'] ?? ''), 'The wrapper remains the containing block.');
    $col = $wrap->parentNode;
    $assert($col instanceof DOMElement, 'The fixed-width column survives.');
    $width = $cascade->resolve($col, array('width'), array())['width'] ?? '';
    $assert('464px' === $width, 'The source column width is retained.');
    $height = 0.0;
    foreach ($wrap->childNodes as $sizer) {
        if (! $sizer instanceof DOMElement || $sizer === $frame) continue;
        $style = $cascade->resolve($sizer, array('padding-top', 'position', 'display'), array());
        if (in_array($style['position'] ?? '', array('absolute', 'fixed'), true) || in_array($style['display'] ?? '', array('none', 'contents'), true)) continue;
        $padding = $style['padding-top'] ?? '';
        if (preg_match('/^([0-9.]+)%$/', $padding, $match)) {
            $height += (float) $width * (float) $match[1] / 100;
        }
    }
    return $height;
};
$before = $measure($source, '');
$after = $measure(IframeEmbedRendererFixture::render($result['serialized_blocks']), implode("\n", array_column($result['assets'], 'content')));
$assert($after > 0 && abs($after - 464 * 0.740740740741) < 0.01 && abs($after - $before) < 0.01, 'The iframe must resolve to approximately 343.70px, not ' . $after . 'px.');
echo "Iframe padding sizer: source={$before}px, converted={$after}px\n";
