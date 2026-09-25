<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormCustomPropertyResolver;

$passes = 0;
$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$passes, &$failures): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}" . ('' !== $detail ? " - {$detail}" : '') . "\n");
};

$resolve = static function (string $css, string $value): string {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8"?><html><body><div class="scope"><h2 class="title">Contact</h2></div></body></html>');
    $title = null;
    foreach ( $document->getElementsByTagName('h2') as $element ) {
        $title = $element;
    }
    $rules = (new CssRuleAnalyzer())->analyze(array(), $css, array('--*'), 1 << 20, 1024, 2048, 8)['rules'];
    return FormCustomPropertyResolver::resolve($value, $title, null, $rules);
};

// A defined variable is substituted and its fallback is never evaluated, even
// when the fallback itself references a variable that cannot resolve.
$assert(
    'rgb(39,95,73)' === $resolve('.scope{--heading:rgb(39,95,73);--text:rgb(var(--missing))}', 'var(--heading,var(--text,#212121))'),
    'a defined outer variable wins over an unresolvable nested fallback',
    $resolve('.scope{--heading:rgb(39,95,73);--text:rgb(var(--missing))}', 'var(--heading,var(--text,#212121))')
);
$assert(
    'rgb(39,95,73)' === $resolve('.scope{--heading:rgb(var(--tone,0,0,0));--tone:39,95,73}', 'var(--heading,var(--text,#212121))'),
    'a defined outer variable with its own nested var() resolves through the chain'
);

// The nested fallback resolves to a value containing parentheses. Substituting
// it first must not stop the outer, defined variable from being substituted.
$assert(
    'rgb(39,95,73)' === $resolve('.scope{--heading:rgb(39,95,73);--text:rgb(33,33,33)}', 'var(--heading,var(--text,#212121))'),
    'a defined outer variable wins when its nested fallback resolves to a function value',
    $resolve('.scope{--heading:rgb(39,95,73);--text:rgb(33,33,33)}', 'var(--heading,var(--text,#212121))')
);
$assert(
    'rgb(33,33,33)' === $resolve('.scope{--text:rgb(33,33,33)}', 'var(--heading,var(--text,#212121))'),
    'an undefined outer variable uses a function-valued nested fallback',
    $resolve('.scope{--text:rgb(33,33,33)}', 'var(--heading,var(--text,#212121))')
);
$assert(
    'rgba(0,0,0,.5)' === $resolve('.scope{--other:1px}', 'var(--shade,rgba(0,0,0,.5))'),
    'a literal function fallback is used when the variable is undefined',
    $resolve('.scope{--other:1px}', 'var(--shade,rgba(0,0,0,.5))')
);

// An undefined outer variable falls back, and the nested fallback resolves.
$assert(
    '#abcdef' === $resolve('.scope{--text:#abcdef}', 'var(--heading,var(--text,#212121))'),
    'an undefined outer variable uses its nested fallback variable'
);
$assert(
    '#212121' === $resolve('.scope{--other:1px}', 'var(--heading,var(--text,#212121))'),
    'an undefined chain uses the innermost literal fallback'
);

// Plain fallbacks keep working.
$assert('12px' === $resolve('.scope{--other:1px}', 'var(--size,12px)'), 'a single-level fallback is unchanged');
$assert('15px' === $resolve('.scope{--size:15px}', 'var(--size,12px)'), 'a defined single-level variable is unchanged');

if ( 0 < $failures ) {
    fwrite(STDERR, "form custom property nested fallback FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}

echo "form custom property nested fallback passed: {$passes} assertions\n";
