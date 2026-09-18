<?php
declare(strict_types=1);

/**
 * A styled inline span that is the only child of a flow wrapper stays inline in
 * the source. Rewrapping it as a paragraph host adds that host's line box and
 * block spacing on top of the wrapper that already owns the source geometry.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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
    str_contains($markup, 'blocks-engine-inline-layout-carrier')
        && str_contains($markup, '<span class="label"')
        && str_contains($markup, '01 / 05'),
    'a lone styled span in a flow wrapper uses a boxless paragraph carrier',
    $markup
);
$assert(
    str_contains($css, ':where(p.blocks-engine-inline-layout-carrier){display:contents;margin:0!important;padding:0!important;border:0!important}'),
    'the carrier paragraph contributes no box of its own',
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

if ( $failures > 0 ) {
    fwrite(STDERR, "Inline span paragraph host: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Inline span paragraph host passed: {$passes} assertions\n");
