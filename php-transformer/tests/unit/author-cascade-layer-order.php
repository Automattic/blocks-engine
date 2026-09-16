<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\AuthorCascadeLayerOrder;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$order = new AuthorCascadeLayerOrder();
$names = static fn (string $css): array => $order->names($css);
$statement = static fn (string $css): string => $order->statement($css);

// A stylesheet with no named layers has no order to pin.
$assert(array() === $names('.a{color:red}'), 'unlayered CSS reports no layer order');
$assert('' === $statement('.a{color:red}'), 'unlayered CSS emits no statement');

// Tailwind v4's shape: a reset layer registered before the utility layer that
// has to beat it. This is the ordering the editor was losing.
$tailwind = '@layer properties{}@layer theme{:root{--x:1}}@layer base{*{margin:0;padding:0}}'
    . '@layer components;@layer utilities{.container-site{margin-inline:auto}}';
$assert(
    array('properties', 'theme', 'base', 'components', 'utilities') === $names($tailwind),
    'layer names are reported in registration order across block and statement forms'
);
$assert(
    '@layer properties,theme,base,components,utilities;' === $statement($tailwind),
    'the emitted statement reproduces the author registration order'
);

// Registration order is first-appearance; later re-entry cannot reorder.
$assert(
    array('base', 'utilities') === $names('@layer base{a{color:red}}@layer utilities{.b{margin:0}}@layer base{a{color:blue}}'),
    're-entering a layer does not move its position'
);

// A grouping statement registers every name it lists, in list order.
$assert(
    array('theme', 'base', 'components', 'utilities') === $names('@layer theme,base,components,utilities;'),
    'a grouping statement registers each name in order'
);

// Only top-level at-rules register a top-level name.
$assert(
    array('a') === $names('@layer a{@layer b{.x{color:red}}}'),
    'a nested layer does not register a top-level name'
);

// An anonymous layer cannot be named, so it cannot appear in an order statement.
$assert(array() === $names('@layer{.x{color:red}}'), 'an anonymous layer is not orderable');
$assert(
    array('utilities') === $names('@layer{.x{color:red}}@layer utilities{.y{margin:0}}'),
    'an anonymous layer does not displace a named one'
);

// Layer names may be dotted; the top-level segment owns the position.
$assert(array('framework') === $names('@layer framework.base{.x{color:red}}'), 'a dotted layer registers its top-level segment');

// Declaration bodies must not be mistaken for layer preludes.
$assert(
    array('utilities') === $names('@layer utilities{.x{content:"@layer base;"}}'),
    'a layer name inside a string literal is not registered'
);
$assert(
    array('utilities') === $names('.x{content:"}"}@layer utilities{.y{margin:0}}'),
    'a brace inside a string literal does not corrupt depth tracking'
);
$assert(
    array('utilities') === $names("/* @layer base; */@layer utilities{.y{margin:0}}"),
    'a layer name inside a comment is not registered'
);

// A media-nested layer sits inside a block and is not a top-level registration.
$assert(
    array('base') === $names('@layer base{.x{color:red}}@media(width>=48rem){@layer utilities{.y{margin:0}}}'),
    'a layer inside a conditional group rule does not register at top level'
);

// Malformed preludes are left alone rather than guessed at.
$assert(array() === $names('@layer 99 bottles;'), 'a malformed layer prelude registers nothing');

// The statement stays bounded against pathological input.
$many = '';
for ($index = 0; $index < 200; ++$index) $many .= '@layer l' . $index . ';';
$assert(64 === count($names($many)), 'layer collection is bounded');

if ($failures > 0) {
    fwrite(STDERR, "Author cascade layer order unit tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "Author cascade layer order unit tests: {$passes} passed\n");
