<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<style>html{line-height:1.5}body{font-family:Arial,sans-serif}.field select{font:inherit;font-size:16px}</style>'
    . '<form class="field" method="post"><label for="topic">Topic</label><select id="topic" name="topic"><option>One</option></select><button>Send</button></form>'
)->toArray();
$styles = $result['fallbacks'][0]['presentation_graph']['controls'][0]['control']['styles'] ?? array();
if ('Arial,sans-serif' !== ($styles['font_family'] ?? null)
    || '1.5' !== ($styles['line_height'] ?? null)
    || '16px' !== ($styles['font_size'] ?? null)
) {
    throw new RuntimeException('Select must inherit its source font, not the provider dropdown shell: ' . json_encode($styles));
}
// Materializers resolve shorthand overlaps using this public provenance.
// The resolved value still belongs to the select's winning inheritance rule:
// giving it the ancestor's lower specificity lets font:inherit reset it again.
$provenance = $result['fallbacks'][0]['presentation_graph']['controls'][0]['control']['provenance'] ?? array();
foreach (array('font-family', 'line-height') as $property) {
    $owners = array_values(array_filter($provenance, static fn (array $fact): bool => in_array($property, $fact['properties'], true)));
    if (1 !== count($owners) || '.field select' !== $owners[0]['selector']) {
        throw new RuntimeException('Resolved select font must retain its own cascade rank: ' . json_encode($provenance));
    }
}
echo "Select source font inheritance passed\n";
