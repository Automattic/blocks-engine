<?php
declare(strict_types=1);

// Squarespace draws list bullets with `ul[data-rte-list] li>*:first-child::before`.
// The ul lowers to core/group and drops data-rte-list, so the rule must bind
// to the matched first children instead of staying a live selector.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$result = (new HtmlTransformer())->transform(
    '<style>ul[data-rte-list] li>*:first-child::before{content:"•"}</style>'
    . '<main><ul data-rte-list="default"><li><p>Alpha</p></li><li><p>Beta</p><p>Note</p></li></ul></main>'
)->toArray();
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
$markup = (string) ($result['serialized_blocks'] ?? '');

$assert(1 === preg_match('/\.(blocks-engine-attribute-[a-f0-9]+-\d+)\)[^{]*::before\{content:"•"\}/', $css, $match), 'the bullet rule binds to an attribute marker: ' . $css);
$assert(2 === preg_match_all('/<p class="' . $match[1] . '">/', $markup), 'both first-child paragraphs carry the bullet marker, and the second paragraph does not');
$assert(!str_contains($css, '[data-rte-list]'), 'no rule keeps the dropped data-rte-list predicate');
echo "universal structural data ancestry: passed\n";
