<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$svg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" class="icon icon-account" viewBox="0 0 18 19"><path fill="currentColor" fill-rule="evenodd" d="M6 4.5a3 3 0 1 1 6 0 3 3 0 0 1-6 0m3-4a4 4 0 1 0 0 8 4 4 0 0 0 0-8" clip-rule="evenodd"></path></svg>';
$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
// Reduced from #6076: the link's text is outside the textless custom host.
foreach ( array(
    'issue fixture' => '<span class="svg-wrapper">' . $svg . '</span>',
    'neutral nested wrappers' => '<div id="graphic" style="display:inline-block"><span class="arbitrary-graphic"> \n<!-- decoration -->' . $svg . '</span></div>',
    'wrapped image' => '<span><img src="icon.png" alt=""></span>',
    'wrapped picture' => '<div><span><picture><source srcset="icon.webp"><img src="icon.png" alt=""></picture></span></div>',
) as $name => $content ) {
    $content = str_replace('\\n', "\n", $content);
    $result = ( new HtmlTransformer() )->transform('<header><a href="/account/login" rel="nofollow"><account-icon>' . $content . '</account-icon><span class="visually-hidden">Log in</span></a></header>')->toArray();
    $blocks = (string) ($result['serialized_blocks'] ?? '');
    $assert(array() === ($result['fallbacks'] ?? array()), $name . ' has no fallbacks');
    $blockName = 'wrapped picture' === $name ? 'custom/responsive-media' : 'image';
    $assert(1 === substr_count($blocks, '<!-- wp:' . $blockName), $name . ' produces exactly one graphic block');
    $assert(str_contains($blocks, 'Log in') && str_contains($blocks, '/account/login'), $name . ' retains the link and its label');
    $assert(! str_contains($blocks, '<!-- wp:html'), $name . ' does not hide content in an HTML block');
}

foreach ( array(
    'runtime widget' => '<motion-widget role="button"><span>' . $svg . '</span></motion-widget>',
    'runtime wrapper' => '<icon-glyph><span onclick="animate()">' . $svg . '</span></icon-glyph>',
    'configured wrapper' => '<icon-glyph><span data-controller="motion">' . $svg . '</span></icon-glyph>',
    'branching wrapper' => '<icon-glyph><span>' . $svg . $svg . '</span></icon-glyph>',
    'empty graphic' => '<icon-glyph><span><svg></svg></span></icon-glyph>',
    'unsafe graphic' => '<icon-glyph><span><svg><path d="M0 0L1 1" onclick="animate()"/></svg></span></icon-glyph>',
) as $name => $html ) {
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $codes = array_column($result['fallbacks'] ?? array(), 'diagnostic_code');
    $assert(in_array('html_unsupported_element', $codes, true), $name . ' still refuses transparent lowering');
}
echo "Wrapped static graphic custom element tests passed\n";
