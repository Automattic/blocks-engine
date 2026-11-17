<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A header bar holds the brand, a link-run wrapper and a CTA. core/navigation
// flattens the wrapper's links into its own items, so the wrapper box is
// gone. Its breakpoint `display:none` must follow the links onto those items,
// with enough weight to beat Core's `.wp-block-navigation-item{display:flex}`;
// the brand and CTA beside them stay visible.

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$result = ( new HtmlTransformer() )->transform(
    '<style>*{box-sizing:border-box;border:0 solid;margin:0;padding:0}.bar{display:flex;align-items:center;gap:20px}.links{display:flex;align-items:center;gap:24px}.links a{font-size:11px}'
    . '.cta{display:inline-flex;padding:0 24px;background:#c00;color:#fff}@media(max-width:800px){.links{display:none}}</style>'
    . '<nav class="site-nav" aria-label="Main navigation"><div class="bar"><a class="brand" href="#top">Brand</a>'
    . '<div class="links"><a href="#about">About</a><a href="#book">Book</a><a href="#blog">Blog</a></div>'
    . '<a class="cta" href="#book">Get the book</a></div></nav><main id="top"><h1>Hi</h1></main>'
)->toArray();
$blocks = (string) ( $result['serialized_blocks'] ?? '' );
$css = implode("\n", array_map(
    static fn (array $asset): string => (string) ( $asset['content'] ?? '' ),
    array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ( $asset['kind'] ?? '' ))
));

$links = array();
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $blocks, $matches);
foreach ( $matches[1] as $json ) {
    $attrs = json_decode($json, true);
    $links[(string) ( $attrs['label'] ?? '' )] = explode(' ', (string) ( $attrs['className'] ?? '' ));
}
$carrierOf = static fn (string $label): array => array_values(array_filter(
    $links[$label] ?? array(),
    static fn (string $token): bool => str_starts_with($token, 'blocks-engine-semantic-')
));
$carriers = array();
foreach ( array( 'About', 'Book', 'Blog' ) as $label ) {
    $assert(isset($links[$label]), $label . ' is a navigation link');
    $assert(1 === count($carrierOf($label)), $label . ' carries the flattened wrapper carrier');
    $carriers[] = $carrierOf($label)[0];
}
$assert(1 === count(array_unique($carriers)), 'every flattened link carries the same wrapper carrier');
$assert(array() === $carrierOf('Brand') && array() === $carrierOf('Get the book'), 'the brand and CTA beside the wrapper are not hidden with it');

$hidden = '/@media\s*\(max-width:\s*800px\)\s*\{[^@]*\.wp-block-navigation-item\.' . preg_quote($carriers[0], '/') . '[^{,]*\{display:none\}/';
$assert(1 === preg_match($hidden, $css), 'the wrapper breakpoint display:none lands on the native items it was flattened into');

// The universal reset already reaches every native item. Restating it on
// the stronger carrier selector would override unrelated author item styles.
$assert(0 === preg_match('/[^{}]*\\.wp-block-navigation-item\\.' . preg_quote($carriers[0], '/') . '[^{}]*\\{[^{}]*box-sizing:/', $css), 'universal resets are not promoted onto the wrapper carrier');

echo "Navigation flattened wrapper visibility contract passed\n";
