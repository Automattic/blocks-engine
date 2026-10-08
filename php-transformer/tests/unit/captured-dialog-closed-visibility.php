<?php
declare(strict_types=1);

/**
 * A captured dialog keeps its source utility classes (`grid`, `flex`), whose
 * `display` overrides the user agent's closed-dialog `display:none`. The
 * generated dialog block must own closed visibility so a closed dialog never
 * renders, covers the page, or intercepts pointer events (BE #2439).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    }
};

$definition = ( new CapturedDialogBlockGenerator() )->definition('site/captured-dialog');
$css = (string) ( $definition['assets']['style.css'] ?? '' );

$assert('file:./style.css' === ( $definition['block_json']['style'] ?? null ), 'the dialog block ships its stylesheet');
$assert(str_contains($css, 'dialog[data-blocks-engine-triggers]:not([open])'), 'the guard targets closed generated dialogs only', $css);
$assert(1 === preg_match('/display:none!important/', $css), 'the guard beats author display utilities', $css);
$assert(! str_contains($css, '[open]{') && ! str_contains($css, ':not([open]){display:block'), 'open dialogs keep their author layout', $css);

$view = (string) ( $definition['view_js'] ?? '' );
$assert(str_contains($view, 'function closeControl') && str_contains($view, "indexOf( 'close' )"), 'a converted source close button closes the dialog by its accessible name', $view);
$assert(str_contains($view, "hasAttribute( 'data-blocks-engine-dialog-close' )"), 'the explicit close marker still closes the dialog');

if ( $failures > 0 ) {
    exit(1);
}
echo "captured-dialog-closed-visibility: ok\n";
