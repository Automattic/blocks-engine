<?php
declare(strict_types=1);

/**
 * WordPress stamps template classes onto <body> via body_class(). A source
 * stylesheet that centers `.page` then applies its frame to the body as well
 * as its own element, paying max-width and gutters twice.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\WordPressCompatCss;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$compat = static fn (string $css): string => ( new WordPressCompatCss() )->css($css, array(), array());
$neutralizes = static fn (string $css, string $class): bool => str_contains($compat($css), 'body.' . $class . ' {');

$frame = $compat('.page {max-width:44rem;margin:0 auto;padding:5rem 2rem 6rem}');
$assert(str_contains($frame, 'body.page {'), 'a centered page frame neutralizes the WordPress body template class');
$assert(
    str_contains($frame, 'max-width:none!important')
        && str_contains($frame, 'padding:0!important')
        && str_contains($frame, 'margin:0!important')
        && str_contains($frame, 'width:auto!important'),
    'the body reset covers the frame properties that double the source gutters'
);
$assert(! str_contains($frame, 'color'), 'the body reset carries no paint, only frame geometry');

// The block axis matters as much as the inline axis: a page frame's leading and
// trailing space would otherwise be added again outside the content.
$blockAxis = $compat('.page {max-width:44rem;margin:0 auto;padding:5rem 2rem 6rem}');
$assert(str_contains($blockAxis, 'padding:0!important') && ! str_contains($blockAxis, 'padding-inline'), 'the reset clears both axes of the duplicated frame padding');
$assert($neutralizes('.page{padding-block:5rem 6rem}', 'page'), 'a block-axis-only frame is covered');

$assert($neutralizes('@media (min-width:60rem){.page{padding:0 3rem}}', 'page'), 'a responsive frame rule still reaches the body reset');
$assert($neutralizes('.home{max-width:70rem;padding-inline:2rem}', 'home'), 'other reserved template classes are covered');
$assert($neutralizes('.search{width:60rem}', 'search'), 'a reserved class sizing itself is covered');

$assert(! $neutralizes('.page{color:red;font-size:1rem}', 'page'), 'a reserved class without frame geometry is left alone');
$assert(! $neutralizes('.card{max-width:40rem;padding:0 2rem}', 'card'), 'an unreserved source class is left alone');
$assert(! $neutralizes('main.page{max-width:60rem;padding:0 2rem}', 'page'), 'an element-qualified frame cannot match body and is left alone');
$assert(! $neutralizes('.page .inner{max-width:60rem}', 'page'), 'a descendant frame rule is left alone');
$assert('' === trim($compat('')), 'an empty stylesheet emits no compatibility CSS');

$multiple = $compat('.page{max-width:44rem;padding:0 2rem}.home{margin-inline:auto;max-width:70rem}');
$assert(str_contains($multiple, 'body.page') && str_contains($multiple, 'body.home'), 'every colliding template class is listed once in the reset');
$assert(1 === substr_count($multiple, 'WordPress body template classes'), 'colliding classes share a single reset rule');

if ( 0 < $failures ) {
    fwrite(STDERR, "WordPress body-class collision tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "WordPress body-class collision tests: {$passes} passed" . PHP_EOL);
