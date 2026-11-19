<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\AdminBarAccommodation;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$build = static fn (string $css): string => (new AdminBarAccommodation())->supportCss($css);
$fixed = $build('.opaque-shell{position:fixed;top:0}');
$assert('body.admin-bar .opaque-shell{top:calc((0px) + var(--wp-admin--admin-bar--height, 32px))!important}' === $fixed, 'fixed opaque selector receives a logged-in offset');

$sticky = $build('.toc{position:sticky;top:calc(var(--header-height) + 1rem)}');
$assert('body.admin-bar .toc{top:calc((calc(var(--header-height) + 1rem)) + var(--wp-admin--admin-bar--height, 32px))!important}' === $sticky, 'sticky calc top expression is preserved');

$complex = $build('@media (max-width: 700px){:is(.header, [data-layer="a,b"]){position:fixed;top:12px!important;content:";{}"}}');
$assert(str_contains($complex, 'body.admin-bar :is(.header, [data-layer="a,b"]){top:calc((12px) + var(--wp-admin--admin-bar--height, 32px))!important}'), 'nested syntax and opaque selector bytes remain usable');

$ordinary = $build('.card{position:relative;top:12px}.overlay{position:fixed;bottom:0}.auto{position:sticky;top:auto}');
$assert('' === $ordinary, 'ordinary, bottom-anchored, and auto-top rules remain unaffected');

$duplicates = $build('.x{position:fixed;top:0}.x{position:fixed;top:0}');
$assert(1 === substr_count($duplicates, 'body.admin-bar .x{'), 'duplicate authored rules emit one bounded override');

$many = '';
for ($index = 0; $index < 101; ++$index) $many .= '.x' . $index . '{position:fixed;top:0}';
$assert(100 === substr_count($build($many), 'body.admin-bar .x'), 'accommodation caps generated overrides');

// Each emitted selector must still reach the header once body carries `admin-bar`
// (Automattic/blocks-engine#6803): a leading body compound gains the class, and a
// leading html/:root compound stays the root ahead of the body scope.
$rootedCases = array(
    '.hdr' => array('html' => '', 'body' => 'admin-bar'),
    'body.x .hdr' => array('html' => '', 'body' => 'admin-bar x'),
    'html.y .hdr' => array('html' => 'y', 'body' => 'admin-bar'),
    ':root .hdr' => array('html' => '', 'body' => 'admin-bar'),
    ':root body.x .hdr' => array('html' => '', 'body' => 'admin-bar x'),
);
foreach ($rootedCases as $selector => $classes) {
    $emitted = $build($selector . '{position:sticky;top:0}');
    $assert(1 === preg_match('/^(.+)\{top:/', $emitted, $match), "{$selector} emits one offset rule");
    $emittedSelector = $match[1] ?? '';
    $document = new DOMDocument();
    $document->loadHTML('<!DOCTYPE html><html class="' . $classes['html'] . '"><body class="' . $classes['body'] . '"><header class="hdr"></header></body></html>', LIBXML_NOERROR);
    $header = $document->getElementsByTagName('header')->item(0);
    $parsed = CssSelectorMatcher::parse($emittedSelector);
    $result = $header instanceof DOMElement ? CssSelectorMatcher::matches($header, $parsed) : array('supported' => false, 'matches' => false);
    $assert($result['supported'] && $result['matches'], "{$selector} offset selector `{$emittedSelector}` matches the header under body.admin-bar");
}

if ($failures > 0) {
    fwrite(STDERR, "Admin bar accommodation unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Admin bar accommodation unit tests: {$passes} passed\n");
