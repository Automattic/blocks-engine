<?php
declare(strict_types=1);

/**
 * Core restyles every lowered <hr> with `.wp-block-separator{border:none;
 * border-top:2px solid}`, which outranks an author `hr` type rule. The borders
 * of every author rule that reaches a source <hr> are re-emitted scoped to the
 * separator and raised by one uniform class shim: they beat Core's default,
 * stay ordered among themselves, and leave other elements untouched.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$result = ( new HtmlTransformer() )->transform(
    '<html><head><style>:root{--line:1px}hr{margin:1rem 0;color:inherit;border:0;border-top:var(--line) solid;opacity:.25}'
    . '.faint{opacity:.2;border-top-color:red}.rule{border-bottom:3px solid}</style></head>'
    . '<body><main><h2>FAQ</h2><hr class="faint"><p class="rule">Question</p></main></body></html>',
    array()
)->toArray();
$css = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ($asset['kind'] ?? null) ) {
        $css .= (string) ($asset['content'] ?? '');
    }
}
$blocks = (string) ($result['serialized_blocks'] ?? '');

$typeRule = '{border:0;border-top:var(--line) solid}';
$classRule = '{border-top-color:red}';
$typeAt = (int) preg_match('/hr:where\(\.wp-block-separator\):not\(\.[\w-]+\)\{border:0;border-top:var\(--line\) solid\}/', $css, $typeMatch, PREG_OFFSET_CAPTURE);
$classAt = (int) preg_match('/\.faint:where\(\.wp-block-separator\):not\(\.[\w-]+\)\{border-top-color:red\}/', $css, $classMatch, PREG_OFFSET_CAPTURE);

$assert(str_contains($blocks, '<!-- wp:separator'), 'the source hr lowers to core/separator', $blocks);
$assert(1 === $typeAt, 'the hr type rule borders reach the separator above Core\'s class default', $css);
$assert(1 === $classAt, 'an author class rule reaching the hr is raised by the same shim', $css);
$assert(1 === $typeAt && 1 === $classAt && $typeMatch[0][1] < $classMatch[0][1], 'the raised rules keep their authored order', $css);
$assert(str_contains($css, 'opacity:.25') && ! preg_match('/wp-block-separator\)[^{]*\{[^}]*opacity/', $css), 'only border declarations are raised', $css);
$assert(! str_contains($css, '.rule:where(.wp-block-separator)'), 'a rule reaching no hr gains no separator copy', $css);

if ( 0 < $failures ) {
    fwrite(STDERR, "separator author border FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "separator author border passed: {$passes} assertions\n";
