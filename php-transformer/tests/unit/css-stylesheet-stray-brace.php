<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

// #6513: CSS Syntax error recovery makes a stray top-level `}` part of the
// following qualified rule's prelude. Only that rule is invalid.
$transformer = new CssStylesheetTransformer();
$css = 'body{margin:0}.x{color:red}}.y{color:blue}.z{color:green}';

$visited = array();
$transformer->visitStyleRules($css, static function (string $prelude) use (&$visited): void {
    $visited[] = trim($prelude);
});
$assert(array('body', '.x', '.z') === $visited, 'visitStyleRules visits body, .x and .z but not .y (got ' . implode(' ', $visited) . ')');

$visited = array();
$output = $transformer->transform($css, static function (string $prelude) use (&$visited): string {
    $visited[] = trim($prelude);
    return $prelude . '.t';
});
$assert(array('body', '.x', '.z') === $visited, 'transform visits body, .x and .z but not .y (got ' . implode(' ', $visited) . ')');
$assert('body.t{margin:0}.x.t{color:red}}.y{color:blue}.z.t{color:green}' === $output, 'transform preserves the stray brace and the invalid rule bytes (got ' . $output . ')');

$visited = array();
$output = $transformer->transformStyleRules($css, static function (string $prelude, string $body) use (&$visited): string {
    $visited[] = trim($prelude);
    return $prelude . '{' . $body . ';x:1}';
});
$assert(array('body', '.x', '.z') === $visited, 'transformStyleRules visits body, .x and .z but not .y (got ' . implode(' ', $visited) . ')');
$assert('body{margin:0;x:1}.x{color:red;x:1}}.y{color:blue}.z{color:green;x:1}' === $output, 'transformStyleRules preserves untouched bytes (got ' . $output . ')');

$assert($css === $transformer->transform($css, static fn (string $prelude): string => $prelude), 'a no-op transform is byte-identical');

// Root statements do not end a qualified-rule prelude: the stray `}` still
// invalidates the next block rule, as in the real-site superfish fragment.
$visited = array();
$transformer->visitStyleRules('.a{b:c}*list-style-image:url(about:blank);list-style-type:disc;}.sf{display:none}.ok{color:red}', static function (string $prelude) use (&$visited): void {
    $visited[] = trim($prelude);
});
$assert(array('.a', '.ok') === $visited, 'a stray brace after root declarations invalidates only the next rule (got ' . implode(' ', $visited) . ')');

// Rules inside a group after the invalid rule are still walked.
$visited = array();
$transformer->visitStyleRules('.a{b:c}}.b{c:d}@media screen{.c{d:e}}', static function (string $prelude, string $body, array $ancestors) use (&$visited): void {
    $visited[] = trim($prelude) . '|' . implode(',', $ancestors);
});
$assert(array('.a|', '.c|@media screen') === $visited, 'grouped rules after the invalid rule are still visited (got ' . implode(' ', $visited) . ')');

if ( $failures > 0 ) {
    fwrite(STDERR, "css-stylesheet-stray-brace: {$failures} failure(s), {$passes} passed\n");
    exit(1);
}
echo "css-stylesheet-stray-brace: {$passes} passed\n";
