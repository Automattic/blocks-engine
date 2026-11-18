<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fixture = static fn (string $end): string => '<!doctype html><html><head><style>
.card[data-kind="media"] .media-inner { height: 100%; }
.card[data-kind="media"] .media-frame { height: 100%; position: relative; }
</style></head><body><main>
<div class="embed"><style>.note { font-weight: bold;' . $end . '</style></div>
<div class="card" data-kind="media" style="height:300px"><div class="media-inner"><div class="media-frame"><img src="https://example.com/a.jpg" alt="Example"></div></div></div>
</main></body></html>';
$rules = array();
foreach (array('' => 'open', ' }' => 'closed') as $end => $label) {
    $result = (new HtmlTransformer())->transform($fixture($end))->toArray();
    $css = implode("\n", array_column($result['assets'], 'content'));
    if (str_contains($css, '.card[data-kind="media"]')) {
        throw new RuntimeException($label . ' sheet disabled attribute rewriting');
    }
    preg_match_all('/[^{}]*blocks-engine-attribute-[^{}]*\{[^{}]*\}/', $css, $matches);
    $rules[$label] = $matches[0];
    if (array() === $rules[$label]) throw new RuntimeException('Missing attribute marker rules');
}
if ($rules['open'] !== $rules['closed']) throw new RuntimeException('EOF recovery changed card rules');
// A dynamic state selector ships verbatim rather than freezing hover state.
$result = (new HtmlTransformer())->transform('<style>.card[data-kind="media"]:hover{color:red}</style><main><div class="card" data-kind="media"><p>Example</p></div></main>')->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
if (!str_contains($css, '[data-kind="media"]:hover')) throw new RuntimeException('Expected a live attribute predicate');
if (!str_contains($result['serialized_blocks'], '"data-kind":"media"')) throw new RuntimeException('Verbatim selector lost its attribute transport');
echo "author stylesheet EOF recovery passed\n";
