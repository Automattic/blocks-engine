<?php
declare(strict_types=1);

/**
 * Real authored CSS values routinely exceed a few hundred bytes: Tailwind's
 * default `--font-sans` stack resolves to ~200 bytes. The form presentation
 * graph must carry such a value instead of failing the whole page compile.
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

$stack = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", "Noto Sans", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"';
$css   = ':root { --font-sans: ' . $stack . ' }'
    . ' input, textarea, button { font-family: var(--default-font-family, ' . $stack . ') }'
    . ' .field { padding: 12px; border: 1px solid #ccc }';

$html = '<form method="post">'
    . '<label for="email">Email</label><input id="email" class="field" name="email" type="email">'
    . '<label for="message">Message</label><textarea id="message" class="field" name="message"></textarea>'
    . '<button type="submit">Send message</button>'
    . '</form>';

try {
    $result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();
} catch ( Throwable $error ) {
    $result = null;
    $assert(false, 'a form styled with a long authored font stack compiles', get_class($error) . ': ' . $error->getMessage());
}

if ( null !== $result ) {
    $fallback = $result['fallbacks'][0] ?? array();
    $families = array();
    foreach ( $fallback['presentation_graph']['controls'] ?? array() as $row ) {
        if ( isset($row['control']['styles']['font_family']) ) $families[] = $row['control']['styles']['font_family'];
    }
    $assert('html_form_fallback' === ($fallback['diagnostic_code'] ?? null), 'the form stays provider-materializable', json_encode($fallback['diagnostic_code'] ?? null));
    $assert(array() !== $families && strlen($families[0]) > 160, 'the long font stack is carried as a control style fact', json_encode($families));
}

fwrite(STDOUT, sprintf('form-presentation-long-style-value: %d passed, %d failed%s', $passes, $failures, PHP_EOL));
exit( $failures > 0 ? 1 : 0 );
