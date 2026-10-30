<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\AdminBarAccommodation;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\TemplatePartStickyContainment;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$offset = static fn (string $selector, string $top = '0px'): string => 'body.admin-bar ' . $selector . '{top:calc((' . $top . ') + var(--wp-admin--admin-bar--height, 32px))!important}';

// Author CSS => exact admin-bar support CSS.
$cases = array(
    'theme header comment before the first rule' => array(
        "/*\nTheme Name: Example\n*/\n\n/* ---- Header ---- */\n.site-header{position:fixed;top:0;left:0;right:0}",
        $offset('.site-header'),
    ),
    'comment between rules' => array(
        ".x{color:red}\n/* the header */ .site-header{position:sticky;top:0}",
        $offset('.site-header'),
    ),
    'comment inside the selector list' => array(
        '.site-header /* fixed bar */ {position:fixed;top:0;left:0;right:0}',
        $offset('.site-header'),
    ),
    'comment between selectors of a list' => array(
        '.a,/* second */.b{position:fixed;top:4px}',
        $offset('.a', '4px') . "\n" . $offset('.b', '4px'),
    ),
    'comment containing braces' => array(
        "/* .old{position:fixed} } { */\n.site-header{position:fixed;top:0}",
        $offset('.site-header'),
    ),
    'comment inside a conditional group prelude' => array(
        '@media /* wide */ (min-width: 600px){/* bar */.site-header{position:fixed;top:0}}',
        '@media  (min-width: 600px){' . $offset('.site-header') . '}',
    ),
    'comment glued inside a compound keeps the compound' => array(
        '.site-header/* x */.is-pinned{position:fixed;top:0}',
        $offset('.site-header.is-pinned'),
    ),
    'comment openers inside strings are selector bytes' => array(
        '/* lead */ [data-note="/* keep */"]{position:fixed;top:0}',
        $offset('[data-note="/* keep */"]'),
    ),
);

foreach ($cases as $label => [$css, $expected]) {
    $actual = (new AdminBarAccommodation())->supportCss($css);
    $assert($expected === $actual, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// The shared walker hands every consumer comment-free preludes and ancestors.
$seen = array();
(new CssStylesheetTransformer())->visitStyleRules(
    "/* a { } */ @supports /* s */ (top:0){ /* b */ .a /* c */, .b{color:red}}",
    static function (string $prelude, string $body, array $ancestors) use (&$seen): void {
        $seen[] = array(trim($prelude), $ancestors);
    }
);
$assert(array(array('.a , .b', array('@supports  (top:0)'))) === $seen, 'visited prelude and ancestors carry no comments: ' . var_export($seen, true));

// Other support writers built on the walker get the same selectors.
$sticky = (new TemplatePartStickyContainment())->supportCss("/* sticky header { } */\n.site-header /* bar */{position:sticky;top:0}");
$assert(str_contains($sticky, ':has(> .site-header){display:contents}') && !str_contains($sticky, '/*'), 'sticky containment selector carries no comment: ' . $sticky);

// End to end: no support stylesheet carries an author comment in a selector.
$html = '<header class="site-header"><a class="brand" href="/">Home</a><nav><a href="/shop/">Shop</a></nav></header><main><h1>Hello</h1><p>Text</p></main>';
foreach (array_slice($cases, 0, 3, true) + array('comment containing braces' => $cases['comment containing braces']) as $label => [$css]) {
    $result = (new HtmlTransformer())->transform($html, array('static_css' => $css))->toArray();
    $support = implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_filter($result['assets'], static fn (array $asset): bool => 'author' !== ($asset['stylesheet_placement'] ?? ''))
    ));
    $assert(1 === preg_match('/body\.admin-bar\s+\.site-header\s*\{/', $support), $label . ': transformed support CSS offsets .site-header');
    $assert(0 === preg_match('/body\.admin-bar[^{]*\/\*/', $support), $label . ': transformed support selector carries no comment');
}

if ($failures > 0) {
    fwrite(STDERR, "Admin bar comment selector unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Admin bar comment selector unit tests: {$passes} passed\n");
