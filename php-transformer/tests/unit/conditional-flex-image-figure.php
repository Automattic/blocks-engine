<?php
declare(strict_types=1);

/**
 * A source image is a flex item whenever any viewport makes its parent a
 * flex or grid container. Responsive captures state each document's layout
 * only inside its media condition, so a media-scoped `display:flex` must mark
 * the synthetic core/image figure exactly like an unconditional one. With no
 * figure box, the core/image `max-width:100%` cap is lifted so the image keeps
 * its native replaced-element min-content, as in the source.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$flex = SourceBlockAttributeProjector::SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS;
$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();
$cssOf = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
            $css .= (string) ($asset['content'] ?? '');
        }
    }
    return $css;
};
$figureClasses = static function (string $css, string $icon) use ($transform): array {
    $html = '<html><head><style>' . $css . '</style></head><body><main><div class="faq"><div class="row">'
        . '<h4>Question text long enough to wrap</h4><div class="icon">' . $icon . '</div>'
        . '</div></div></main></body></html>';
    preg_match('/<figure class="([^"]*)"/', (string) ($transform($html)['serialized_blocks'] ?? ''), $match);

    return preg_split('/\s+/', trim($match[1] ?? '')) ?: array();
};

$svg = '<svg width="24" height="24" viewBox="0 0 24 24"><path d="M2 2L22 22"></path></svg>';
$img = '<img src="chevron.png" width="24" height="24" alt="">';
foreach ( array( 'svg' => $svg, 'img' => $img ) as $kind => $icon ) {
    $assert(in_array($flex, $figureClasses('.faq .row{display:flex}.faq .icon{display:flex}', $icon), true), "an unconditional flex parent marks the {$kind} figure");
    $assert(in_array($flex, $figureClasses('@media (max-width:1400px){.faq .row{display:flex}.faq .icon{display:flex}}', $icon), true), "a media-scoped flex parent marks the {$kind} figure");
    $assert(in_array($flex, $figureClasses('@media (min-width:768px){.faq .icon{display:grid}}', $icon), true), "a media-scoped grid parent marks the {$kind} figure");
    $assert(! in_array($flex, $figureClasses('@media (max-width:1400px){.faq .row{display:flex}}', $icon), true), "a block parent leaves the {$kind} figure unmarked");
}

// An author img rule (`img,svg{vertical-align:middle}`) makes the projection
// bridge cap every core/image <img> at `max-width:100%`, as core also does.
// Without a figure box that cap is not the source's; an authored image
// max-width still reaches the image.
$capped = $transform(
    '<html><head><style>img,svg{vertical-align:middle}.row{display:flex}.icon{display:flex}.icon.capped img{max-width:10px}</style></head><body><main>'
    . '<div class="row"><h4>Q</h4><div class="icon"><img src="a.png" style="width:24px;height:24px" alt=""></div></div>'
    . '<div class="row"><h4>Q2</h4><div class="icon capped"><img src="a.png" style="width:24px;height:24px" alt=""></div></div>'
    . '</main></body></html>'
);
$cappedBlocks = (string) ($capped['serialized_blocks'] ?? '');
$cappedCss = $cssOf($capped);
$assert(2 === preg_match_all('/<figure class="[^"]*\b' . preg_quote($flex, '/') . '\b/', $cappedBlocks), 'both flex-item images lower to flex image figures', $cappedBlocks);
$assert(str_contains($cappedCss, ':root :where(figure.' . $flex . ').wp-block-image>img{max-width:none}'), 'the flex image figure lifts the core/image max-width cap', $cappedCss);
$assert(1 === preg_match('/\{max-width:10px\}/', $cappedCss), 'an authored image max-width is still projected', $cappedCss);

if ( 0 < $failures ) {
    fwrite(STDERR, "conditional flex image figure FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "conditional flex image figure passed: {$passes} assertions\n";
