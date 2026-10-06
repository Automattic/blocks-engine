<?php
declare(strict_types=1);

/**
 * WordPress stamps template classes onto <body> via body_class(). A source
 * stylesheet that centers `.page` can then acquire an unintended body subject.
 * The existing collision classifier feeds route-owned document reconciliation,
 * preserving genuine source body subjects instead of resetting their geometry.
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
$collisions = static fn (string $css): array => (new WordPressCompatCss())->bodyClassCollisionClasses($css);
$collides = static fn (string $css, string $class): bool => in_array($class, $collisions($css), true);

$frame = $compat('.page {max-width:44rem;margin:0 auto;padding:5rem 2rem 6rem}');
$assert($collides('.page {max-width:44rem;margin:0 auto;padding:5rem 2rem 6rem}', 'page'), 'a centered page frame identifies the WordPress-owned collision');
$assert(
    !str_contains($frame, '!important') && !str_contains($frame, 'body.page'),
    'route reconciliation replaces the broad body frame reset'
);
$assert(! str_contains($frame, 'color'), 'collision ownership emits no compensating paint');

// The block axis matters as much as the inline axis: a page frame's leading and
// trailing space would otherwise be added again outside the content.
$blockAxis = $compat('.page {max-width:44rem;margin:0 auto;padding:5rem 2rem 6rem}');
$assert('' === trim($blockAxis), 'body geometry remains under the authored selector authority');
$assert($collides('.page{padding-block:5rem 6rem}', 'page'), 'a block-axis-only frame is covered');

$assert($collides('@media (min-width:60rem){.page{padding:0 3rem}}', 'page'), 'responsive frame rules retain collision ownership');
$assert($collides('.home{max-width:70rem;padding-inline:2rem}', 'home'), 'other reserved template classes are covered');
$assert($collides('.search{width:60rem}', 'search'), 'a reserved class sizing itself is covered');

$assert(! $collides('.page{color:red;font-size:1rem}', 'page'), 'a reserved class without frame geometry is left alone');
$assert(! $collides('.card{max-width:40rem;padding:0 2rem}', 'card'), 'an unreserved source class is left alone');
$assert(! $collides('main.page{max-width:60rem;padding:0 2rem}', 'page'), 'an element-qualified frame cannot match body and is left alone');
$assert(! $collides('.page .inner{max-width:60rem}', 'page'), 'a descendant frame rule is left alone');
$assert($collides('.page:not(.blocks-engine-specificity-class-site-0){margin:4px}', 'page'), 'canonical margin specificity shims retain collision ownership');
$assert('' === trim($compat('')), 'an empty stylesheet emits no compatibility CSS');

$multiple = $collisions('.page{max-width:44rem;padding:0 2rem}.home{margin-inline:auto;max-width:70rem}.page{margin:2rem}');
$assert(in_array('page', $multiple, true) && in_array('home', $multiple, true), 'all colliding template classes are available to the root owner');
$assert(2 === count($multiple), 'colliding classes are unique across the authored stream');

if ( 0 < $failures ) {
    fwrite(STDERR, "WordPress body-class collision tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "WordPress body-class collision tests: {$passes} passed" . PHP_EOL);
