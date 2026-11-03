<?php
declare(strict_types=1);

/** Definite out-of-flow button heights must not become percentage fills. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

foreach ( array( 'fixed', 'absolute' ) as $position ) {
    $out = (new HtmlTransformer())->transform(
        '<!DOCTYPE html><html><head><style>.bcTg04 { background-color: #fff; padding-block: 8px; }'
        . '.yxZj1H { height: 34px; position: ' . $position . '; }</style></head><body>'
        . '<div class="WIX_ADS ub230c bcTg04 aD8jGL data-blocks-engine-capture-desktop-only">'
        . '<a class="ak8WKZ yxZj1H">B</a></div></body></html>'
    )->toArray();
    $css = implode('', array_map(
        static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
        $out['assets'] ?? array()
    ));
    $assert(str_contains((string) ($out['serialized_blocks'] ?? ''), '<!-- wp:button '), $position . ': converts to core/button');
    $assert(! preg_match('/(?:^|[;{])\s*height\s*:\s*100%/i', $css), $position . ': neither carrier nor link gets a percentage height fill');
    $assert(
        (bool) preg_match('/[^{}]*wp-block-button__link[^{}]*\{[^{}]*height:\s*34px(?:[;}]|!important)/', $css),
        $position . ': the link keeps its authored 34px height'
    );
}

// A zero-height positioned carrier is intentionally collapsed layout, not
// a positive definite height that must move onto the link.
$out = (new HtmlTransformer())->transform(
    '<style>.skip{position:absolute;left:50%;width:0;height:0;padding:0 24px}</style><button class="skip">Skip</button>'
)->toArray();
$css = implode('', array_map(
    static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '',
    $out['assets'] ?? array()
));
$assert(
    str_contains($css, ':where(.wp-block-buttons){position:absolute;left:50%;width:0;height:0}'),
    'zero-height positioned control keeps its collapsed native wrapper geometry'
);

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "positioned button definite height tests passed\n");
