<?php
declare(strict_types=1);

/**
 * Regression coverage for native core/button style emission.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$html = <<<'HTML'
<style>
.btn { padding:9px 20px; border-radius:6px; font-weight:600; text-transform:uppercase; }
.btn-primary { background:#e8a020; color:#050d1a; box-shadow:0 0 24px rgba(232,160,32,0.3); }
</style>
<a class="btn btn-primary" href="/demo">Get early access</a>
HTML;

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$button = $result['blocks'][0]['innerBlocks'][0] ?? array();
$attrs = is_array($button['attrs'] ?? null) ? $button['attrs'] : array();
$markup = (string) ($result['serialized_blocks'] ?? '');

$assert('core/button' === ($button['blockName'] ?? ''), 'button signal becomes core/button', (string) ($button['blockName'] ?? '(none)'));
$assert('0 0 24px rgba(232,160,32,0.3)' === ($attrs['style']['shadow'] ?? ''), 'button box-shadow maps to canonical style.shadow', json_encode($attrs['style'] ?? array()));
$assert(str_contains($markup, 'box-shadow:0 0 24px rgba(232,160,32,0.3)'), 'rendered core/button carries source box-shadow', $markup);
$assert(str_contains($markup, 'background-color:#e8a020'), 'rendered core/button carries source fill', $markup);
$assert(str_contains($markup, 'color:#050d1a'), 'rendered core/button carries source text color', $markup);

if ( $failures > 0 ) {
    fwrite(STDERR, "Button style resolver tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Button style resolver tests: {$passes} passed\n");
