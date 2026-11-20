<?php
declare(strict_types=1);

/**
 * A shared header is compiled under only the ancestor context its routes
 * share, so its support states the route-free value of the chrome. A route
 * whose own <body> class selects another value publishes that value under the
 * same selector. The route's support must load after the shared baseline, or
 * the baseline overrides it at equal specificity: a home route styled by
 * `.home-route header nav a{font-size:13px}` rendered its menu at the 11px
 * every other route uses.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    }
};

$page = static fn(string $title, string $bodyClass): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head>'
    . '<body class="' . $bodyClass . '"><header><a class="brand" href="/">Brand</a><nav>'
    . '<a href="/">Home</a><a href="/about.html">About</a><a href="/shop.html">Shop</a>'
    . '</nav></header><main><h1>' . $title . '</h1><p>Body copy for ' . $title . '.</p></main></body></html>';
$css = 'header{display:flex;justify-content:space-between}header nav{display:flex;gap:24px}header nav a{font-size:11px;color:#222222;text-decoration:none}'
    . '.home-route header nav a{font-size:13px}@media(max-width:900px){.home-route header nav a{font-size:11px}}';
$plan = (new ArtifactCompiler())->compile(array( 'entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home', 'home-route'),
    'about.html' => $page('About', 'editorial-route'),
    'shop.html'  => $page('Shop', 'editorial-route'),
    'site.css'   => $css,
) ))->toArray()['source_reports']['wordpress_site_plan'] ?? array();

$assert(array() !== ($plan['template_parts'] ?? array()), 'the routes share one header part');

/** Unconditional menu item font sizes a route loads, in delivery order. */
$menuFontSizes = static function (array $plan, string $route): array {
    $sizes = array();
    foreach ( $plan['assets'] ?? array() as $asset ) {
        if ( 'css' !== ($asset['kind'] ?? null) || 'after-author' !== ($asset['stylesheet_placement'] ?? null) || ! is_string($asset['content'] ?? null) ) continue;
        $owners = array_column($asset['scopes'] ?? array(), 'source_path');
        if ( ! in_array('global', array_column($asset['scopes'] ?? array(), 'kind'), true) && ! in_array($route, $owners, true) ) continue;
        $depth = 0;
        preg_match_all('/@[^{;]*\{|\}|([^{}@]+)\{([^{}]*)\}/', $asset['content'], $tokens, PREG_SET_ORDER);
        foreach ( $tokens as $token ) {
            if ( '@' === $token[0][0] ) { ++$depth; continue; }
            if ( '}' === $token[0] ) { --$depth; continue; }
            if ( 0 === $depth && 1 === preg_match('/^\s*\.wp-block-navigation\.[\w-]+ \.wp-block-navigation-item__content\s*$/', $token[1])
                && 1 === preg_match('/font-size:(?:var\([^,]+,)?([0-9.]+px)/', $token[2], $size) ) {
                $sizes[] = $size[1];
            }
        }
    }
    return $sizes;
};

$home = $menuFontSizes($plan, 'index.html');
$assert('13px' === end($home), 'the home route menu resolves to its route-selected size', json_encode($home));
$about = $menuFontSizes($plan, 'about.html');
$assert(array() !== $about && '11px' === end($about) && ! in_array('13px', $about, true), 'other routes keep the shared size and never load the home route value', json_encode($about));

if ( $failures > 0 ) {
    exit(1);
}
fwrite(STDOUT, "shared-shell-support-route-refinement: ok\n");
