<?php
declare(strict_types=1);

/**
 * A styled inline span that is the only child of a flow wrapper stays inline in
 * the source. Rewrapping it as a paragraph host adds that host's line box and
 * block spacing on top of the wrapper that already owns the source geometry.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;

$failures = 0;
$passes   = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$cssOf = static function (array $result): string {
    $css = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'css' === ( $asset['kind'] ?? '' ) ) {
            $css .= "\n" . (string) ( $asset['content'] ?? '' );
        }
    }

    return $css;
};

$result = ( new HtmlTransformer() )->transform(
    '<style>.label-row{padding:.5rem 1rem;border-bottom:1px solid #242424}'
    . '.label{font-size:10px;line-height:15px;letter-spacing:1px;font-weight:500}</style>'
    . '<div class="card"><div class="label-row"><span class="label">01 / 05</span></div>'
    . '<p>Script vidéo 20 secondes</p></div>'
)->toArray();

$markup = (string) ( $result['serialized_blocks'] ?? '' );
$css    = $cssOf($result);

$assert(
    1 === preg_match('/<p class="label blocks-engine-synthetic-paragraph blocks-engine-semantic-[^"]*">01 \/ 05<\/p>/', $markup)
        && ! str_contains($markup, 'blocks-engine-inline-layout-carrier'),
    'a lone styled span in a flow wrapper is its own inline paragraph',
    $markup
);
$assert(
    str_contains($css, ':where(p.blocks-engine-synthetic-paragraph:not(.blocks-engine-source-box-paragraph)){display:inline}')
        && str_contains($css, '{font-size:10px;line-height:15px;letter-spacing:1px;font-weight:500}'),
    'the paragraph lays out inline, like the span, and keeps the span typography',
    $css
);
$assert(
    ! str_contains($markup, '<p class="blocks-engine-synthetic-paragraph"><mark'),
    'the span is not rewrapped as a synthetic paragraph host',
    $markup
);
$assert(
    'pass' === ( $result['source_reports']['wp_block_validity']['status'] ?? '' ),
    'the boxless carrier remains editor-valid'
);

$ordinary = ( new HtmlTransformer() )->transform(
    '<style>p{margin:13px 0 7px}</style><p>Ordinary <span class="callout">inline</span> prose.</p>'
)->toArray();
$ordinaryMarkup = (string) ( $ordinary['serialized_blocks'] ?? '' );

$assert(
    1 === substr_count($ordinaryMarkup, '<!-- wp:paragraph')
        && str_contains($ordinaryMarkup, 'Ordinary')
        && str_contains($ordinaryMarkup, 'inline')
        && ! str_contains($ordinaryMarkup, 'blocks-engine-inline-layout-carrier'),
    'ordinary spans inside authored paragraphs stay RichText',
    $ordinaryMarkup
);
$assert(
    ! str_contains($ordinaryMarkup, 'blocks-engine-synthetic-paragraph'),
    'authored paragraphs keep their own spacing contract',
    $ordinaryMarkup
);

$divBacked = ( new HtmlTransformer() )->transform(
    '<style>div.paragraph{padding-bottom:20px}</style><div class="paragraph"><span>Responsive copy.</span></div>'
)->toArray();
$divBackedMarkup = (string) ( $divBacked['serialized_blocks'] ?? '' );

$assert(
    str_contains($divBackedMarkup, '<p class="paragraph blocks-engine-synthetic-paragraph')
        && str_contains($divBackedMarkup, '<span>Responsive copy.</span></p>'),
    'an unmarked span in a div-backed paragraph still becomes one native paragraph',
    $divBackedMarkup
);

$paddedText = ( new HtmlTransformer() )->transform(
    '<style>.copy{padding:1rem;font-size:.875rem;line-height:1.625}</style>'
    . '<div class="copy">PLAN 1 (0-3s)</div>'
)->toArray();
$paddedTextMarkup = (string) ( $paddedText['serialized_blocks'] ?? '' );
$paddedTextCss    = $cssOf($paddedText);

$assert(
    str_contains($paddedTextMarkup, '<p class="copy blocks-engine-lowered-paragraph">PLAN 1 (0-3s)</p>'),
    'a childless padded text wrapper is one lowered paragraph that keeps its padded box',
    $paddedTextMarkup
);
$assert(
    str_contains($paddedTextCss, ':root :where(p.blocks-engine-lowered-paragraph){margin-block-start:0;margin-block-end:0}'),
    'engine-support CSS still zeros the introduced paragraph host margins',
    $paddedTextCss
);

$inlineTargets = (new HtmlTransformer())->transform(
    '<style>.footer{display:flex;justify-content:space-between}.clock{font-size:12px}.clock span{display:inline}</style>'
    . '<footer class="footer"><div class="clock"><span id="hours">09</span><span id="colon">:</span><span id="minutes">43</span> <span id="timezone">(GMT -4)</span></div><div>Status</div></footer>'
)->toArray();
$inlineMarkup = (string) ($inlineTargets['serialized_blocks'] ?? '');
$assert(
    1 === substr_count($inlineMarkup, 'class="wp-block-group clock')
        && str_contains($inlineMarkup, '<p class="blocks-engine-inline-layout-carrier">')
        && str_contains($inlineMarkup, 'id="colon"')
        && str_contains($inlineMarkup, 'id="timezone"')
        && 1 === substr_count($inlineMarkup, '"className":"blocks-engine-inline-layout-carrier"')
        && 'pass' === ($inlineTargets['source_reports']['wp_block_validity']['status'] ?? ''),
    'a flex item with multiple addressable inline spans stays one editable inline run with stable IDs',
    $inlineMarkup
);

// Carrier prediction must agree with the serialized topology, including leaves
// that become the paragraph itself rather than retaining a source wrapper.
foreach (array(
    'retained wrapper' => array(
        'html' => '<span class="item__text"><strong class="item__title">Local service</strong><span class="item__desc">Town and nearby areas</span></span>',
        'css' => '.item__text{display:block;min-width:0}.item__title{display:block;margin:0 0 3px;font-size:15px;font-weight:700}.item__desc{display:block;color:#b3b3b3;font-size:13px}',
        'targets' => array('item__title' => array('display' => 'block', 'font-size' => '15px'), 'item__desc' => array('display' => 'block', 'font-size' => '13px', 'color' => '#b3b3b3')),
        'retained' => true,
    ),
    'retained wrapper child combinators' => array(
        'html' => '<span class="item__text"><strong class="item__title">Local service</strong><span class="item__desc">Town and nearby areas</span></span>',
        'css' => '.item__text{display:block;min-width:0}.item__text > .item__title{display:block;font-size:15px}.item__text > .item__desc{display:block;font-size:13px;color:#b3b3b3}',
        'targets' => array('item__title' => array('display' => 'block', 'font-size' => '15px'), 'item__desc' => array('display' => 'block', 'font-size' => '13px', 'color' => '#b3b3b3')),
        'retained' => true,
    ),
    'unwrapped addressable leaf' => array(
        'html' => '<span id="service" class="item__title">Local service</span>',
        'css' => '.item__title{display:block;font-size:15px}',
        'targets' => array('item__title' => array('display' => 'inline', 'font-size' => '15px')),
        'retained' => false,
    ),
    'unwrapped plain leaf' => array(
        'html' => '<span class="item__title">Local service</span>',
        'css' => '.item__title{display:block;font-size:15px}',
        'targets' => array('item__title' => array('display' => 'block', 'font-size' => '15px')),
        'retained' => false,
    ),
) as $shape => $fixture) {
    $compiled = (new HtmlTransformer())->transform(
        '<style>.item{display:flex;gap:12px;align-items:center}.item__icon{display:flex;width:34px;height:34px}'
        . $fixture['css'] . '</style><div class="item"><span class="item__icon"><svg viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="10" r="3"/></svg></span>'
        . $fixture['html'] . '</div>'
    )->toArray();
    $emitted = (string) ($compiled['serialized_blocks'] ?? '');
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML($emitted);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    $cascade = new StaticCssCascade($dom, $cssOf($compiled));
    $assert((0 < $xpath->query('//p[contains(@class,"blocks-engine-inline-layout-carrier")]/span[@class="item__text"]')->length) === $fixture['retained'], $shape . ': emitted wrapper topology', $emitted);
    foreach ($fixture['targets'] as $class => $expected) {
        $target = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," ' . $class . ' ")]')->item(0);
        $actual = $target instanceof DOMElement ? $cascade->resolve($target, array_keys($expected), array()) : array();
        $assert($expected == $actual, $shape . ': compiled rules match ' . $class, json_encode($actual));
    }
}

if ( $failures > 0 ) {
    fwrite(STDERR, "Inline span paragraph host: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Inline span paragraph host passed: {$passes} assertions\n");
