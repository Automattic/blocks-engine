<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\AdminBarAccommodation;

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

if ($failures > 0) {
    fwrite(STDERR, "Admin bar accommodation unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Admin bar accommodation unit tests: {$passes} passed\n");
