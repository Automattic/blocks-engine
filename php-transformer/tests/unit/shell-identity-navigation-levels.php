<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ShellExtraction;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};

$link = static fn (string $label, string $classes, string $url, string $style = ''): string =>
    '<!-- wp:navigation-link {"className":"' . $classes . '","kind":"custom","label":"' . $label . '"' . $style . ',"url":"' . $url . '"} /-->';
$header = static fn (string $currentLabel, string $dialogId): string =>
    '<!-- wp:group {"tagName":"nav"} --><nav class="wp-block-group">'
    . '<!-- wp:navigation {"overlayMenu":"never"} -->'
    . '<!-- wp:navigation-submenu {"className":"item","label":"Shop","kind":"custom"} -->'
    . $link('Start', 'child' . ('Start' === $currentLabel ? ' blocks-engine-current-navigation-item' : ''), '/', '' )
    . $link('Team', 'child' . ('Team' === $currentLabel ? ' blocks-engine-current-navigation-item' : ''), '/team')
    . $link('Press', 'child', '/press')
    . '<!-- /wp:navigation-submenu -->'
    . $link('Contact', 'lone' . ('Contact' === $currentLabel ? ' blocks-engine-current-navigation-item' : ''), '/contact', ',"style":{"typography":{"fontWeight":"500"}}')
    . '<!-- /wp:navigation -->'
    . '<!-- wp:button {"anchor":"blocks-engine-dialog-trigger-' . $dialogId . '-1"} --><div class="wp-block-button" id="blocks-engine-dialog-trigger-' . $dialogId . '-1"></div><!-- /wp:button -->'
    . '</nav><!-- /wp:group -->';

// The page's own item is current on each route; the header is the same header.
$identities = array();
foreach ( array( 'Start' => '1111111111111111', 'Team' => '2222222222222222', 'Contact' => '3333333333333333' ) as $current => $dialogId ) {
    $identities[$current] = ShellExtraction::identityMarkup($header($current, $dialogId));
}
$assert(1 === count(array_unique($identities)), 'one header has one identity whichever of its items is current and whatever hash its dialog trigger id carries');

// A different menu still has a different identity.
$other = str_replace('"label":"Press"', '"label":"News"', $header('Start', '1111111111111111'));
$assert(ShellExtraction::identityMarkup($other) !== $identities['Start'], 'a different menu label is a different header');

// A current top-level item keeps its own classes. It does not take the classes of the submenu items.
$resting = ShellExtraction::withoutCurrentNavigationState($header('Contact', '1111111111111111'));
$assert(1 === preg_match('/"className":"lone[^"]*","kind":"custom","label":"Contact"/', $resting) && ! str_contains(preg_replace('/\{[^{}]*"label":"(?:Start|Team|Press)"[^{}]*\}/', '', $resting) ?? '', 'child'), 'the current top-level item does not take the submenu items classes');

if ( 0 < $failures ) {
    fwrite(STDERR, "Shell identity navigation levels: {$failures} failed\n");
    exit(1);
}
echo "Shell identity navigation levels passed\n";
