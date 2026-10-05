<?php
declare(strict_types=1);

/**
 * The control that opens a projected native dialog survives conversion and
 * keeps the id the dialog binds to (BE #2256). A hamburger-shaped trigger beside
 * a navigation was dropped as redundant chrome (or as "navigation chrome" by the
 * brand-carrier recognizer), leaving the dialog's `data-blocks-engine-triggers`
 * id resolving to nothing.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    }
};

$html = '<body><header><nav class="bar"><a href="#top" class="logo">Brand</a><div class="desk"><a href="#a">Alpha</a><a href="#b">Beta</a></div>'
    . '<button aria-label="Toggle menu" class="mob" aria-controls="panel-1" aria-expanded="false" aria-haspopup="menu" id="blocks-engine-dialog-trigger-abc"><svg viewBox="0 0 24 24" width="24" height="24"><line x1="4" x2="20" y1="12" y2="12"></line><line x1="4" x2="20" y1="6" y2="6"></line></svg></button></nav></header>'
    . '<main><p>Body</p></main>'
    . '<dialog id="blocks-engine-dialog-xyz" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="blocks-engine-dialog-trigger-abc" class="grid"><a href="#a">Alpha</a><a href="#b">Beta</a><a href="#c">Gamma</a></dialog></body>';
$blocks = (string) ( ( new HtmlTransformer() )->transform($html, array())->toArray()['serialized_blocks'] ?? '' );

$assert(str_contains($blocks, 'id="blocks-engine-dialog-trigger-abc"'), 'the dialog trigger keeps the id the dialog binds to', $blocks);
$assert(str_contains($blocks, 'data-blocks-engine-triggers="blocks-engine-dialog-trigger-abc"'), 'the dialog still names its trigger', $blocks);
$assert(1 === preg_match('/<button[^>]*>/', $blocks) && str_contains($blocks, 'Toggle menu'), 'the trigger converts to an operable button that keeps its label', $blocks);

// An unbound hamburger beside a converted navigation is still redundant chrome.
$unbound = (string) ( ( new HtmlTransformer() )->transform(str_replace(array(' id="blocks-engine-dialog-trigger-abc"', ' data-blocks-engine-captured-dialog="true"'), '', $html), array())->toArray()['serialized_blocks'] ?? '' );
$assert(! str_contains($unbound, 'Toggle menu'), 'an unbound hamburger is still dropped as redundant chrome', $unbound);

if ( $failures > 0 ) {
    exit(1);
}
echo "captured-dialog-trigger-survives: ok\n";
