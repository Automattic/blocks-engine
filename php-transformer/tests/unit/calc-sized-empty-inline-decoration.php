<?php
declare(strict_types=1);

/**
 * An empty inline decoration sized by a `calc()` length must survive the same
 * way one sized by a plain length does.
 *
 * Tailwind v4 sizing utilities resolve to `calc(var(--spacing) * N)`, so a
 * `<span class="inline-block h-2 w-2" style="background:…">` dot or a
 * `<span class="h-px w-3 bg-…">` rule carries width and height as
 * `calc(.25rem * 2)` once variables resolve. The empty-decoration checks only
 * counted plain lengths as a definite size, so these boxes read as sizeless,
 * were dropped, and their rich-text markers stayed named by CSS while nothing
 * carried them.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$serialize = static fn (string $size, string $rule): string => (string) ( ( new HtmlTransformer() )->transform(
    '<style>:root{--spacing:.25rem;--dot:#21d9c0;--line:#182c55}'
    . '.points{display:flex;list-style:none}.point{display:flex;align-items:center;gap:.5rem}'
    . '.dot{display:inline-block;width:' . $size . ';height:' . $size . ';border-radius:2px}'
    . '.brand{display:flex;align-items:center;gap:.375rem}.rule{height:1px;width:' . $rule . ';background-color:var(--line)}</style>'
    . '<main><ul class="points"><li class="point"><span class="dot" style="background:var(--dot)"></span>Direct</li></ul>'
    . '<div class="brand"><span class="rule"></span><span class="label">Digital <span class="accent">UK</span></span><span class="rule"></span></div></main>'
)->toArray()['serialized_blocks'] ?? '' );

$plain = $serialize('8px', '12px');
$calc = $serialize('calc(var(--spacing) * 2)', 'calc(var(--spacing) * 3)');

$dots = static fn (string $markup): int => preg_match_all('/<mark\b[^>]*\bclass="dot(?: blocks-engine-[^"]*)?"[^>]*><\/mark>/', $markup);
$rules = static fn (string $markup): int => preg_match_all('/<div\b[^>]*\bclass="[^"]*\brule\b[^"]*"/', $markup);

$assert(1 === $dots($plain), 'A plain-length dot stays as an empty mark in its list item.');
$assert(2 === $rules($plain), 'Both plain-length rules flanking the label stay as boxes.');
$assert(1 === $dots($calc), 'A calc()-sized dot stays as an empty mark in its list item.');
$assert(2 === $rules($calc), 'Both calc()-sized rules flanking the label stay as boxes.');
$assert(str_contains($calc, 'Direct</li>') && str_contains($calc, 'Digital <mark'), 'The text beside the decorations is unchanged.');

if ( 0 < $failures ) {
    fwrite(STDERR, sprintf('calc-sized empty inline decoration: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf('calc-sized empty inline decoration tests: %d passed%s', $passes, PHP_EOL));
