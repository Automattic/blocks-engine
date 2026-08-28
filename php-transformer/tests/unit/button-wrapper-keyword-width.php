<?php
declare(strict_types=1);

/**
 * Keyword widths must not land on `.wp-block-buttons` and override a definite
 * source width (issue #1299).
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
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$out = ( new HtmlTransformer() )->transform(
    '<style>.cta{width:7.82%;margin-left:89%}.cta{width:min-content}</style>'
    . '<main><button class="cta" type="button">Contacts</button></main>'
)->toArray();
$css = '';
foreach ( $out['assets'] ?? array() as $asset ) {
    if ( is_array($asset) && 'css' === ( $asset['kind'] ?? '' ) ) {
        $css .= (string) ( $asset['content'] ?? '' );
    }
}

$assert(str_contains((string) ( $out['serialized_blocks'] ?? '' ), 'wp:buttons'), '1: source button becomes core/buttons', (string) ( $out['serialized_blocks'] ?? '' ));
$assert(
    str_contains($css, '7.82%') && (bool) preg_match('/wp-block-buttons[^{]*\{[^}]*7\.82%/', $css),
    '2: definite source width stays on the buttons wrapper',
    $css
);
$assert(
    ! preg_match('/wp-block-buttons[^{]*\{[^}]*min-content/', $css),
    '3: min-content does not shrink-wrap the buttons wrapper',
    $css
);

if ( $failures > 0 ) {
    fwrite(STDERR, PHP_EOL . "button wrapper keyword width tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "button wrapper keyword width tests: {$passes} passed" . PHP_EOL);
