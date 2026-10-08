<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\Support\RenderEquivalentMarkup;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

foreach (array('0', '0px', '0%', '-0em', '0.0rem', 'calc(0px)', 'calc( calc(0%) )', '+0vh') as $zero) {
    $assert(RenderEquivalentMarkup::isZeroLength($zero), "{$zero} is a zero length.");
}
foreach (array('', 'auto', '1px', '0.5px', '10%', 'calc(0px + 1px)', 'var(--gap)', 'inherit') as $value) {
    $assert(! RenderEquivalentMarkup::isZeroLength($value), "{$value} is not a zero length.");
}

// A server-rendered string and the browser's reserialization of the same
// element read the same: attribute order, class order, style spacing, the
// grid-area shorthand, zero spellings, and capture diagnostics.
$server = '<div style="grid-row:1 / span 1;grid-column:1 / span 12;margin:0 0 0 0;display:flex" class="cell a"><a href="#x" data-dla-anchor-unresolved="timeout">X</a></div>';
$browser = '<div class="a cell" style="grid-area: 1 / 1 / span 1 / span 12; display: flex; margin: calc(0px) 0% 0% 0px;"><a href="#x">X</a></div>';
$assert(RenderEquivalentMarkup::canonical($server) === RenderEquivalentMarkup::canonical($browser), 'Render-equivalent serializations are one canonical form: ' . RenderEquivalentMarkup::canonical($server) . ' vs ' . RenderEquivalentMarkup::canonical($browser));
// Real differences stay different, and overlapping shorthands keep their order.
$assert(RenderEquivalentMarkup::canonical('<div style="margin:1px">x</div>') !== RenderEquivalentMarkup::canonical('<div style="margin:0">x</div>'), 'A non-zero margin differs from a zero one.');
$assert('margin:0px;margin-top:4px' === RenderEquivalentMarkup::canonicalStyle('margin: 0; margin-top: 4px'), 'A shorthand and its longhand keep their written order.');
$assert(RenderEquivalentMarkup::canonical('<div style="margin-top:4px;margin:0">x</div>') !== RenderEquivalentMarkup::canonical('<div style="margin:0;margin-top:4px">x</div>'), 'Order that changes the cascade is kept.');

echo "Canonical zero lengths passed.\n";
