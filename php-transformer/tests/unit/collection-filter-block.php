<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedCollectionProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } };
$item = static fn (string $answer, string $id): string => '<div class="card"><button aria-expanded="false" aria-controls="' . $id . '">Same question?</button><div id="' . $id . '" role="region" hidden><p>Answer ' . $answer . '.</p></div></div>';
$source = '<html><body><main><div class="scope"><div class="choices"><button class="active">All</button><button class="inactive">First</button></div><input placeholder="Search"><div id="results">' . $item('orchid', 'one') . $item('violet', 'two') . '</div></div></main></body></html>';
$evidence = array(
    'field' => array('selector' => 'body > main > div > input', 'value' => ''),
    'target' => array('selector' => 'body > main > div > div#results', 'html' => ''),
    'items' => array(array('key' => '0', 'text' => 'Same question? Answer orchid.', 'html' => $item('orchid', 'one'), 'categories' => array(0, 1)), array('key' => '1', 'text' => 'Same question? Answer violet.', 'html' => $item('violet', 'two'), 'categories' => array(0))),
    'categories' => array(array('selector' => 'body > main > div > div > button:nth-of-type(1)', 'label' => 'All', 'index' => 0, 'activeHtml' => '<button class="active">All</button>', 'inactiveHtml' => '<button class="inactive">All</button>'), array('selector' => 'body > main > div > div > button:nth-of-type(2)', 'label' => 'First', 'index' => 1, 'activeHtml' => '<button class="active">First</button>', 'inactiveHtml' => '<button class="inactive">First</button>')),
    'initialCategory' => 0, 'predicate' => 'normalized-text-includes', 'emptyHtml' => '<p>No matches.</p>', 'emptyPlacement' => 'after',
    'probes' => array(array('query' => '', 'category' => 0, 'keys' => array('0', '1')), array('query' => 'ORCHID', 'category' => 0, 'keys' => array('0')), array('query' => 'violet', 'category' => 1, 'keys' => array())),
    'restoration' => 'verified', 'replay' => 'verified', 'network' => array('dataRequests' => 'blocked'),
);
$files = static function (string $html, array $evidence): array {
    return array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test', 'path' => 'website/index.html'))))),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://example.test', 'states' => array(array('kind' => 'typed-search', 'status' => 'captured', 'collectionFilter' => $evidence))))))),
    );
};
$projected = (new CapturedCollectionProjector())->project($files($source, $evidence));
$result = (new HtmlTransformer())->transform($projected['files'][0]['content'])->toArray();
$markup = $result['serialized_blocks'];
file_put_contents(sys_get_temp_dir() . '/collection-filter-result.json', json_encode($result, JSON_PRETTY_PRINT));
$assert(str_contains($markup, 'custom/collection-filter'), 'verified source evidence becomes an editable collection companion');
$assert(!str_contains($markup, 'wp:search'), 'local filtering never becomes global WordPress search');
$assert(1 === substr_count($markup, 'Answer orchid.') && 1 === substr_count($markup, 'Answer violet.'), 'each distinct answer has one canonical editable copy');
$assert(2 === substr_count($markup, '<!-- wp:accordion-item '), 'answers are native accordion items');
$assert(1 === substr_count($markup, '<!-- wp:accordion '), 'all items share one native accordion tree');
$assert(str_contains($markup, 'blocks-engine-collection-item-'), 'the native collection is addressable after serialization');
$assert(str_contains($markup, 'wp:paragraph'), 'answers remain native editor paragraphs');
$assert(str_contains($markup, 'No matches.'), 'the external source empty state remains editable and local');
$assert((bool) preg_match('/<input[^>]+type="text"/', $markup), 'an omitted source input type keeps the native HTML text default');
$search = $files(str_replace('<input placeholder=', '<input type="search" placeholder=', $source), $evidence);
$searchResult = (new HtmlTransformer())->transform((new CapturedCollectionProjector())->project($search)['files'][0]['content'])->toArray();
$assert((bool) preg_match('/<input[^>]+type="search"/', $searchResult['serialized_blocks']), 'an explicit source search input keeps its search type');
$assert(64 === strlen(RuntimeDeclarations::hash($result['source_reports']['generated_blocks'])), 'companion declarations cross the actual staged runtime transport contract');
$evidence['replay'] = 'unsupported';
$rejected = (new CapturedCollectionProjector())->project($files($source, $evidence));
$assert(!str_contains($rejected['files'][0]['content'], 'data-blocks-engine-collection-root'), 'unverified predicates are not promoted');
file_put_contents(sys_get_temp_dir() . '/collection-filter-view.mjs', $result['source_reports']['generated_blocks'][0]['view_js']);
$cards = '<html><body><main><div class="scope"><div class="choices"><button class="active">All</button><button class="inactive">First</button></div><input placeholder="Search"><div id="results"><article class="card"><h2>Same card</h2><p>Answer orchid.</p></article><article class="card"><h2>Same card</h2><p>Answer violet.</p></article></div></div></main></body></html>';
$cardEvidence = $evidence;
$cardEvidence['replay'] = 'verified';
$cardEvidence['items'][0]['text'] = 'Same card Answer orchid.';
$cardEvidence['items'][1]['text'] = 'Same card Answer violet.';
$cardResult = (new HtmlTransformer())->transform((new CapturedCollectionProjector())->project($files($cards, $cardEvidence))['files'][0]['content'])->toArray();
$assert(str_contains($cardResult['serialized_blocks'], 'blocks-engine-collection-item-') && !str_contains($cardResult['serialized_blocks'], 'wp:accordion'), 'ordinary native card collections use the same verified filter primitive');
file_put_contents(sys_get_temp_dir() . '/collection-filter-cards.json', json_encode($cardResult));
fwrite(STDOUT, "PASS: verified canonical collection projection and native answer authoring\n");
