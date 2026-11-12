<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// A branching/freeform terminal is not itself a saved block. It must not
// force the surrounding editable divs into a generated shell.
$result = (new HtmlTransformer())->transform('<div id="outer"><div class="middle"><p>First editable child</p><p>Second editable child</p></div></div>')->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
if (str_contains($markup, '/layout-shell') || 2 !== substr_count($markup, '<!-- wp:group')) {
    throw new RuntimeException('Branch terminals must not force div ancestors into generated layout shells: ' . $markup);
}
$runtime = new Runtime();
$saved = $runtime->serializeBlocks($runtime->parseBlocks($markup));
$document = new DOMDocument();
@$document->loadHTML($saved);
$xpath = new DOMXPath($document);
if (2 !== $xpath->query('//*[@id="outer"]/div[contains(concat(" ", normalize-space(@class), " "), " middle ")]/p')->length) {
    throw new RuntimeException('Native wrappers must retain IDs, classes and child-selector edges after save.');
}
echo "Native branching wrapper chain passed\n";
