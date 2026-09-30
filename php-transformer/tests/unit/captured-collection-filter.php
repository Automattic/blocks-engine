<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedCollectionProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $value, string $message): void { if (!$value) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$item = static fn (string $answer): string => '<div class="card"><button aria-expanded="false" aria-controls="' . $answer . '">Shared question?</button><div role="region" id="' . $answer . '" hidden><p>' . $answer . ' answer</p></div></div>';
$source = '<html><body><main><div class="scope"><div class="choices"><button class="active">All</button><button class="inactive">Alpha</button></div><input type="search" placeholder="Search locally"><div id="results">' . $item('Alpha') . $item('Beta') . '</div></div></main></body></html>';
$evidence = array(
    'field' => array('selector' => 'body > main > div > input', 'value' => ''),
    'target' => array('selector' => 'body > main > div > div#results', 'html' => ''),
    'items' => array(array('key' => 'a', 'text' => 'Shared question? Alpha answer', 'html' => $item('Alpha'), 'categories' => array(0, 1)), array('key' => 'b', 'text' => 'Shared question? Beta answer', 'html' => $item('Beta'), 'categories' => array(0))),
    'categories' => array(array('selector' => 'body > main > div > div > button:nth-of-type(1)', 'label' => 'All', 'index' => 0, 'activeHtml' => '<button class="active">All</button>', 'inactiveHtml' => '<button class="inactive">All</button>'), array('selector' => 'body > main > div > div > button:nth-of-type(2)', 'label' => 'Alpha', 'index' => 1, 'activeHtml' => '<button class="active">Alpha</button>', 'inactiveHtml' => '<button class="inactive">Alpha</button>')),
    'initialCategory' => 0, 'predicate' => 'normalized-text-includes', 'emptyHtml' => '<p>No local matches</p>', 'emptyPlacement' => 'after',
    'probes' => array(array('query' => '', 'category' => 0, 'keys' => array('a', 'b')), array('query' => 'ALPHA', 'category' => 0, 'keys' => array('a')), array('query' => 'Beta', 'category' => 1, 'keys' => array())),
    'restoration' => 'verified', 'replay' => 'verified', 'network' => array('dataRequests' => 'blocked'),
);
$files = static fn (array $evidence): array => array(
    array('path' => 'website/index.html', 'content' => $source),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'states' => array(array('kind' => 'typed-search', 'status' => 'captured', 'collectionFilter' => $evidence))))))),
);
$projected = (new CapturedCollectionProjector())->project($files($evidence));
$baseline = (new HtmlTransformer())->transform($source)->toArray();
$assert(str_contains($baseline['serialized_blocks'], 'wp:html'), 'an unproven bare source field retains its unsupported state rather than inventing local search');
$assert(1 === $projected['projected_count'], 'verified source transitions establish one canonical collection: ' . json_encode($projected['diagnostics']));
$html = $projected['files'][0]['content'];
$result = (new HtmlTransformer())->transform($html)->toArray();
$markup = $result['serialized_blocks'];
$assert(str_contains($markup, 'wp:custom/collection-filter ') && str_contains($markup, 'wp:custom/collection-filter-field ') && str_contains($markup, 'wp:custom/collection-filter-choice '), 'native editable collection controls replace the source field without a global WordPress search');
$assert(!str_contains($markup, 'wp:search') && !str_contains($markup, 'wp:html') && !str_contains($markup, 'wp:tabs'), 'the verified relationship contains no global search, raw HTML or per-category snapshots');
$assert(1 === substr_count($markup, '>Alpha answer</p>') && 1 === substr_count($markup, '>Beta answer</p>'), 'duplicate labels with distinct answers remain one editable copy each');
$assert(str_contains($markup, 'wp:accordion ') && str_contains($markup, 'wp:paragraph'), 'disclosure controls and answer paragraphs remain native blocks');
$assert(strpos($markup, 'collection-filter-choice') < strpos($markup, 'collection-filter-field') && strpos($markup, 'collection-filter-field') < strpos($markup, 'wp:accordion '), 'category, field and collection positions preserve source topology');
$assert(str_contains($markup, 'No local matches') && str_contains($markup, '::state.hasMatches'), 'the editable source empty state is bound to local results');
foreach (array('restoration' => 'unverified', 'replay' => 'unsupported', 'predicate' => 'server-query') as $key => $value) {
    $invalid = $evidence; $invalid[$key] = $value;
    $refused = (new CapturedCollectionProjector())->project($files($invalid));
    $assert(0 === $refused['projected_count'] && $source === $refused['files'][0]['content'], 'incomplete evidence leaves the original source tree unmodified');
}
$invalid = $evidence; $invalid['probes'][1]['keys'] = array('b');
$assert(0 === (new CapturedCollectionProjector())->project($files($invalid))['projected_count'], 'inconsistent probe results cannot promote a guessed predicate');
$invalid = $evidence; $invalid['items'][1]['key'] = 'a';
$assert(0 === (new CapturedCollectionProjector())->project($files($invalid))['projected_count'], 'ambiguous item identity remains visibly unsupported');
echo "Captured collection filter contract passed\n";
