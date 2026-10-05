<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedDialogProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$item = static fn (string $id, string $label, string $links): string =>
    '<div class="item"><button id="' . $id . '" type="button" aria-haspopup="menu" aria-controls="p-' . $id . '" aria-expanded="false" data-dla-dialog-trigger="p-' . $id . '">' . $label . '</button>'
    . '<div class="dla-dialog dla-dropdown" role="dialog" aria-modal="true" hidden id="p-' . $id . '" data-dla-dialog-panel="p-' . $id . '"><div class="drop"><div class="box">' . $links . '</div></div></div></div>';
$header = '<header class="top"><nav class="bar"><a href="#top" class="logo"><span>Acme</span></a><div class="row"><a href="#one">One</a>'
    . $item('shop', 'Shop', '<a href="#new">New in</a><a href="#sale">Sale</a>')
    . '<a href="#three">Three</a></div><div class="end"><a href="#join">Join</a></div></nav></header>';

$markup = (string) ( ( new HtmlTransformer() )->transform($header)->toArray()['serialized_blocks'] ?? '' );
$assert(1 === substr_count($markup, '<!-- wp:navigation '), 'the header menu is one core/navigation', $markup);
$assert(1 === preg_match('/<!-- wp:navigation-submenu \{[^}]*"label":"Shop","kind":"custom"\}/', $markup), 'a button with a dropdown panel becomes a submenu with no url', $markup);
$assert(2 === preg_match_all('/<!-- wp:navigation-link \{[^}]*"label":"(?:New in|Sale)","url":"#(?:new|sale)"/', $markup), 'the panel links become navigation links inside the submenu', $markup);
$assert(! str_contains($markup, 'wp:details') && ! str_contains($markup, 'data-dla-dialog-panel'), 'the panel is not kept as a disclosure or a leftover wired panel', $markup);

// The menu row is still one navigation when the surrounding bar also holds
// controls that cannot become menu items (a call to action and icon-only links).
$mixed = '<style>.row{display:flex;gap:28px}.end{display:flex}</style><header><nav class="bar"><a href="#top"><span>Acme</span></a><div class="row"><a href="#one">One</a>'
    . $item('shop', 'Shop', '<a href="#new">New in</a><a href="#sale">Sale</a>')
    . '</div><div class="end"><a href="#join">Join</a><div class="icons"><a href="https://example.test/x" aria-label="X"></a></div></div></nav></header>';
$mixedMarkup = (string) ( ( new HtmlTransformer() )->transform($mixed)->toArray()['serialized_blocks'] ?? '' );
$assert(1 === preg_match('/<!-- wp:navigation-submenu \{[^}]*"label":"Shop","kind":"custom"\}/', $mixedMarkup) && ! str_contains($mixedMarkup, 'wp:details'), 'the menu row converts even beside a call to action and icon links', $mixedMarkup);

// A button that opens a dialog (not a menu of links) is not a submenu.
$dialogItem = '<div class="item"><button type="button" aria-haspopup="dialog" aria-controls="p-d" aria-expanded="false" data-dla-dialog-trigger="p-d">Contact</button>'
    . '<div class="dla-dialog" role="dialog" aria-modal="true" hidden id="p-d" data-dla-dialog-panel="p-d"><p>Write to us.</p><a href="#mail">Mail</a></div></div>';
$notMenu = (string) ( ( new HtmlTransformer() )->transform('<nav class="bar"><a href="#one">One</a><a href="#two">Two</a>' . $dialogItem . '</nav>')->toArray()['serialized_blocks'] ?? '' );
$assert(! str_contains($notMenu, '"label":"Contact"'), 'a dialog button is not read as a submenu', $notMenu);

// The dialog projector leaves a navigation dropdown alone and still projects a real dialog.
$project = static function (string $html, array $trigger, string $dialogHtml): array {
    $files = array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))), JSON_UNESCAPED_SLASHES)),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'states' => array(array(
            'status' => 'captured',
            'trigger' => $trigger,
            'dialog' => array('html' => $dialogHtml, 'htmlBytes' => strlen($dialogHtml), 'htmlTruncated' => false, 'presentation' => 'dropdown'),
        ))))), JSON_UNESCAPED_SLASHES)),
    );
    $result = ( new CapturedDialogProjector() )->project($files);

    return array( (int) ( $result['projected_count'] ?? 0 ), (string) ( $result['files'][0]['content'] ?? '' ) );
};
$linksHtml = '<div class="drop"><div class="box"><a href="#new">New in</a><a href="#sale">Sale</a></div></div>';
[ $navProjected, $navHtml ] = $project('<html><body>' . $header . '</body></html>', array('selector' => '#shop', 'tag' => 'button', 'ariaHaspopup' => 'menu', 'label' => 'Shop', 'dataBindings' => array()), $linksHtml);
$assert(0 === $navProjected && ! str_contains($navHtml, '<dialog'), 'a navigation dropdown is not projected as a native dialog', $navHtml);
$actionHtml = '<html><body><main><button id="open" type="button">Open the record</button></main></body></html>';
[ $actionProjected, $actionOut ] = $project($actionHtml, array('selector' => '#open', 'tag' => 'button', 'ariaHaspopup' => '', 'label' => 'Open the record', 'dataBindings' => array()), '<div role="dialog"><h2>Record</h2><p>Details</p></div>');
$assert(1 === $actionProjected && str_contains($actionOut, '<dialog'), 'a plain action button still projects a native dialog', $actionOut);

if ( 0 < $failures ) {
    fwrite(STDERR, "Navigation button dropdown contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}
echo "Navigation button dropdown contract passed: {$passes} assertions\n";
