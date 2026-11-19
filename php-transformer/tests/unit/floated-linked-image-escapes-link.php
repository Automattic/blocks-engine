<?php
declare(strict_types=1);

/**
 * #6694: an author-floated image inside an inline link lets following
 * siblings wrap beside it. Core's `.wp-block-image a{display:inline-block}`
 * would contain that float, so the imported link must stay inline. A linked
 * image without a float keeps core's anchor box.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

const INLINE_LINK_CLASS = 'blocks-engine-inline-image-link';

$convert = static function (string $html): array {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $css = implode("\n", array_column(array_filter(
        is_array($result['assets'] ?? null) ? $result['assets'] : array(),
        static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')
    ), 'content'));

    return array( (string) $result['serialized_blocks'], $css );
};

$fixture = static fn (string $imageCss): string => '<style>.views-field-field-picture img{' . $imageCss . '}.row{width:300px}</style>'
    . '<div class="row"><div class="views-field-field-picture"><div class="field-content"><a href="/news/1/"><img src="/t.png" width="75" height="56" alt=""></a></div></div>'
    . '<div class="views-field-date"><span class="date">Fri, 01/29/2027</span></div><div class="views-field-title"><a href="/news/1/">AI in Science Symposium</a></div></div>';

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ( ! $ok ) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$figureClasses = static function (string $blocks): array {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $blocks);
    $classes = array();
    foreach ( ( new DOMXPath($dom) )->query('//figure[contains(concat(" ", normalize-space(@class), " "), " wp-block-image ")][a/img]') as $figure ) {
        $classes[] = preg_split('/\s+/', trim($figure->getAttribute('class'))) ?: array();
    }
    return $classes;
};

$linkInlineRule = 'figure.wp-block-image:where(.' . INLINE_LINK_CLASS . ') > a{display:inline}';

// Floated image: the float must escape the link.
[ $blocks, $css ] = $convert($fixture('float:left;margin:0 5px 5px 0'));
$figures = $figureClasses($blocks);
$assert(1 === count($figures), 'floated fixture lowers to one linked core/image: ' . $blocks);
$assert(str_contains($blocks, '"linkDestination":"custom"'), 'image keeps its custom link');
$assert(isset($figures[0]) && in_array(INLINE_LINK_CLASS, $figures[0], true), 'linked image figure carries the inline-link carrier');
$assert(str_contains($css, $linkInlineRule), 'emitted CSS keeps the source link inline so it does not contain the float: ' . $css);
$assert(1 === preg_match('/\.views-field-field-picture img\{[^}]*float:left/', $css), 'author float still reaches the image');

// Right floats escape the same way.
[ $blocks, $css ] = $convert($fixture('float:right'));
$assert(str_contains($blocks, INLINE_LINK_CLASS) && str_contains($css, $linkInlineRule), 'right-floated linked image also escapes its link');

// Non-floated linked image keeps current output: core's anchor box stays.
[ $blocks, $css ] = $convert($fixture('margin:0 5px 5px 0'));
$figures = $figureClasses($blocks);
$assert(1 === count($figures), 'non-floated fixture lowers to one linked core/image');
$assert(! str_contains($blocks, INLINE_LINK_CLASS), 'non-floated linked image gains no inline-link carrier: ' . $blocks);
$assert(! str_contains($css, $linkInlineRule), 'non-floated linked image emits no link display override');

[ $blocks ] = $convert($fixture('float:none'));
$assert(! str_contains($blocks, INLINE_LINK_CLASS), 'float:none linked image gains no inline-link carrier');

if ( 0 < $failures ) {
    exit(1);
}
echo "floated linked image escapes link passed\n";
