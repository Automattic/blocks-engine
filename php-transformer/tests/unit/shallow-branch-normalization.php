<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A single div before a branching section is not a long wrapper chain.
// Building a synthetic freeform terminal here incorrectly activates the mixed
// semantic chain's local cap. Keep this tree within the group-heavy budget.
$branch = '<p>Editable leaf</p>';
for ($i = 0; $i < 4; ++$i) {
    $branch = '<div id="leaf-' . $i . '">' . $branch . '</div>';
}
$html = '<div id="outer"><section id="fork">' . $branch
    . '<p>Sibling</p></section></div>';
$result = (new HtmlTransformer())->transform($html)->toArray();
if (str_contains($result['serialized_blocks'], '/layout-shell')) {
    throw new RuntimeException('Within-budget branching ancestry must remain native.');
}
if (2 !== substr_count($result['serialized_blocks'], '<!-- wp:paragraph')) {
    throw new RuntimeException('Both leaves must remain independently editable.');
}
echo "Shallow branch normalization passed\n";
