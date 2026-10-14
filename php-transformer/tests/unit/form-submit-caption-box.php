<?php
declare(strict_types=1);

/**
 * A submit whose caption is painted by a block-level inner carrier takes its
 * box from that carrier: an unpadded `<button>` around a padded block
 * `<span>` is as tall as the span. The presentation graph carries the
 * carrier's padding and text case onto the button so a provider button,
 * which renders the caption directly, keeps the source box and case.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

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

$submit = static function (string $css): array {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body><form class="f" method="post"><input type="text" name="name"><button type="submit" class="btn"><span class="inner">Send</span></button></form></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $graph = (new FormPresentationGraphBuilder())->build($document->getElementsByTagName('form')->item(0), array(), $css);
    return array( 'styles' => array_column($graph['controls'], null, 'index')[1]['control']['styles'] ?? array(), 'variants' => $graph['variants'] ?? array() );
};

$block = $submit('.btn { padding: 0; background-color: #f5a623; } .btn .inner { display: block; padding: .625em 1.9375em; text-transform: uppercase; font-weight: 700; }');
$assert('.625em 1.9375em' === ($block['styles']['padding'] ?? null), 'a block carrier pads the unpadded button', json_encode($block['styles']));
$assert('uppercase' === ($block['styles']['text_transform'] ?? null) && '700' === ($block['styles']['font_weight'] ?? null), 'the carrier sets the caption case and weight', json_encode($block['styles']));

$inline = $submit('.btn { padding: 0; } .btn .inner { padding: 4px 8px; text-transform: uppercase; }');
$assert('0' === ($inline['styles']['padding'] ?? null), 'an inline carrier leaves the button box alone', json_encode($inline['styles']));
$assert('uppercase' === ($inline['styles']['text_transform'] ?? null), 'an inline carrier still sets the caption case', json_encode($inline['styles']));

$padded = $submit('.btn { padding: 10px 20px; } .btn .inner { display: block; padding: 4px; }');
$assert('10px 20px' === ($padded['styles']['padding'] ?? null), 'a padded button keeps its own box', json_encode($padded['styles']));

$responsive = $submit('.btn { padding: 0; } .btn .inner { display: block; padding: 4px; } @media (min-width: 600px) { .btn .inner { padding: 12px; } }');
$patches = array_map(static fn (array $variant): array => $variant['style_patch'] ?? array(), array_filter($responsive['variants'], static fn (array $variant): bool => 1 === ($variant['index'] ?? null) && 'control' === ($variant['role'] ?? null)));
$assert('4px' === ($responsive['styles']['padding'] ?? null) && in_array('12px', array_column($patches, 'padding'), true), 'a conditional carrier padding becomes a variant', json_encode(array( $responsive['styles'], $patches )));

// The control manifest describes the same caption box, through the element
// painting an off-screen native submit, so a consumer that layers a control's
// own style over the graph keeps the carrier's padding and case.
$css = '<style>.btn { display: inline-block; padding: 0; background-color: #f5a623; } .btn .inner { display: block; padding: .625em 1.9375em; text-transform: uppercase; } a { text-decoration: underline; } a.btn { text-decoration: none; }</style>';
foreach ( array(
    'native button' => '<button type="submit" class="btn"><span class="inner">Send</span></button>',
    'off-screen submit presenter' => '<input type="submit" value="Send" style="position:absolute;left:-9999px;width:1px;height:1px"><a class="btn"><span class="inner">Send</span></a>',
) as $name => $submitMarkup ) {
    $result = (new Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform('<main>' . $css . '<form method="post" action="#"><input type="text" name="name" aria-label="Name"><div>' . $submitMarkup . '</div></form></main>')->toArray();
    $controls = array();
    foreach ( array_merge($result['fallbacks'] ?? array(), array_column($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array(), 'payload')) as $entry ) {
        $controls = $entry['controls'] ?? ($entry['entities'][0]['controls'] ?? $controls);
    }
    $style = end($controls)['presentation']['style'] ?? array();
    $assert('.625em' === ($style['spacing']['padding']['top'] ?? null) && '1.9375em' === ($style['spacing']['padding']['left'] ?? null) && 'uppercase' === ($style['typography']['textTransform'] ?? null), $name . ' control style carries the caption box', json_encode($style));
}

if ( 0 < $failures ) {
    fwrite(STDERR, "form submit caption box FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form submit caption box passed: {$passes} assertions\n";
