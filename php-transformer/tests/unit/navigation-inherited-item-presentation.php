<?php
declare(strict_types=1);

/**
 * Inherited navigation presentation may only land on every item when every
 * source anchor actually shares it.
 *
 * A uniquely styled child — a gradient-text wordmark whose glyphs are
 * `color: transparent` so a clipped background can show through — must not
 * project that type onto sibling links. A property every item does share
 * still belongs on the nav-descendant rule.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$css = static function (string $html): string {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();

    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        is_array($result['assets'] ?? null) ? $result['assets'] : array()
    ));
};

$contentRule = static function (string $css): string {
    foreach ( explode('}', $css) as $chunk ) {
        if ( str_contains($chunk, '.wp-block-navigation-item__content{')
            && str_contains($chunk, '.wp-block-navigation.')
            && ! str_contains($chunk, '.wp-block-navigation-item.')
        ) {
            return trim($chunk) . '}';
        }
    }

    return '';
};

$uniqueChild = $css(
    '<style>'
    . '.site-nav{color:rgb(0, 0, 0)}'
    . '.wordmark{color:transparent;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:1.875rem;letter-spacing:-0.05em;background-image:linear-gradient(to right,#ec4899,#f43f5e);background-clip:text}'
    . '</style>'
    . '<nav class="site-nav" aria-label="Main">'
    . '<a href="/" class="wordmark">Brand</a>'
    . '<a href="/now">Now</a><a href="/blog">Archive</a><a href="/contact">Contact</a>'
    . '</nav>'
);
$uniqueRule = $contentRule($uniqueChild);
$assert(
    '' === $uniqueRule
        || (
            ! str_contains($uniqueRule, 'color:transparent')
            && ! str_contains($uniqueRule, 'font-size:1.875rem')
            && ! str_contains($uniqueRule, 'ui-monospace')
        ),
    'a gradient-text wordmark does not project transparent ink or its unique type onto every navigation item',
    $uniqueRule
);
$assert(
    1 !== preg_match('/\.wp-block-navigation\.site-nav(?:\.[A-Za-z0-9_-]+)*\s+\.wp-block-navigation-item__content\{[^}]*color:transparent/', $uniqueChild),
    'the nav-descendant item selector never carries the wordmark\'s transparent color',
    $uniqueChild
);

$shared = $css(
    '<style>.labelBox{color:rgb(238,255,255);font-family:helvetica-w01-roman;font-size:15.75px}</style>'
    . '<nav class="menu navbar" aria-label="Main"><ul>'
    . '<li><div class="labelBox"><a href="/features">Features</a></div></li>'
    . '<li><div class="labelBox"><a href="/benefits">Benefits</a></div></li>'
    . '</ul></nav>'
);
$assert(
    str_contains($shared, '.wp-block-navigation.menu.navbar .wp-block-navigation-item__content{color:rgb(238,255,255);font-family:helvetica-w01-roman;font-size:15.75px}'),
    'presentation every item shares is still recovered onto the native navigation item',
    $shared
);

$allTransparent = $css(
    '<style>.ghost{color:transparent;font-size:16px}</style>'
    . '<nav class="menu" aria-label="Main">'
    . '<a href="/a" class="ghost">Alpha</a><a href="/b" class="ghost">Beta</a>'
    . '</nav>'
);
$ghostRule = $contentRule($allTransparent);
$assert(
    '' === $ghostRule || ! str_contains($ghostRule, 'color:transparent'),
    'transparent ink is not projected even when every item shares it',
    $ghostRule
);
$assert(
    str_contains($ghostRule, 'font-size:16px'),
    'a shared non-transparent type size still projects when transparent color is refused',
    $ghostRule
);

if ( 0 < $failures ) {
    fwrite(STDERR, "navigation inherited item presentation FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}

fwrite(STDOUT, "navigation inherited item presentation passed: {$passes} assertions\n");
