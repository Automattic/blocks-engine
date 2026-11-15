<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$result = (new HtmlTransformer())->transform('<nav class="menu-toggle"><div class="control-box"><button>Open menu</button></div></nav>')->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
if (str_contains($markup, '/layout-shell') || !str_contains($markup, '"tagName":"nav"')) {
    throw new RuntimeException('Navigation must retain its native landmark Group around editable controls: ' . $markup);
}
$runtime = new Runtime();
$document = new DOMDocument();
@$document->loadHTML($runtime->serializeBlocks($runtime->parseBlocks($markup)));
$xpath = new DOMXPath($document);
if (1 !== $xpath->query('//nav/div//button')->length) {
    throw new RuntimeException('Native navigation must preserve the control box ancestry through save.');
}
// The captured site's control box also contains a dialog. A landmark is a
// boundary even when its terminal branches, not only around a lone button.
$branching = (new HtmlTransformer())->transform('<nav class="menu-toggle" aria-label="Site"><div class="control-box"><button>Open menu</button><div role="dialog" hidden><p>Menu content</p></div></div></nav>')->toArray();
$branchingMarkup = (string) ($branching['serialized_blocks'] ?? '');
if (str_contains($branchingMarkup, '/layout-shell') || !str_contains($branchingMarkup, '"tagName":"nav"')) {
    throw new RuntimeException('Navigation landmark identity must survive branching controls: ' . $branchingMarkup);
}
echo "Native navigation wrapper chain passed\n";
