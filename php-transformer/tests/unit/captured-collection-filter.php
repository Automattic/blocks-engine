<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedCollectionProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedSelectableSetProjector;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$assert = static function (bool $value, string $message): void { if (!$value) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$item = static fn (string $answer): string => '<div class="card"><button aria-expanded="false" aria-controls="' . $answer . '">Shared question?</button><div role="region" id="' . $answer . '" hidden><p>' . $answer . ' answer</p></div></div>';
$source = '<html><body><main><div class="scope"><div class="choices"><button class="active">All</button><button class="inactive">Alpha</button></div><input type="search" placeholder="Search locally"><div id="results" data-dla-exclusive-disclosures="true">' . $item('Alpha') . $item('Beta') . '</div></div></main></body></html>';
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
$result = (new HtmlTransformer())->transform($html, array('static_css' => '.scope{max-width:640px}.card{background:white}.active{background:black;color:white}.inactive{background:white;color:black}'))->toArray();
$markup = $result['serialized_blocks'];
$assert('' !== RuntimeDeclarations::canonicalJson($result['source_reports']['generated_blocks']), 'generated definitions retain the bounded canonical runtime payload contract');
$assert(str_contains($markup, 'wp:custom/collection-filter ') && str_contains($markup, 'wp:custom/collection-filter-field ') && str_contains($markup, 'wp:custom/collection-filter-choice '), 'native editable collection controls replace the source field without a global WordPress search');
$assert(!str_contains($markup, 'wp:search') && !str_contains($markup, 'wp:html') && !str_contains($markup, 'wp:tabs'), 'the verified relationship contains no global search, raw HTML or per-category snapshots');
$assert(1 === substr_count($markup, '>Alpha answer</p>') && 1 === substr_count($markup, '>Beta answer</p>'), 'duplicate labels with distinct answers remain one editable copy each');
$assert(str_contains($markup, 'wp:accordion ') && str_contains($markup, 'wp:paragraph'), 'disclosure controls and answer paragraphs remain native blocks');
$assert(str_contains($markup, '"autoclose":true'), 'source-observed exclusive disclosure groups retain native single-open behavior');
$independent = (new HtmlTransformer())->transform(str_replace(' data-dla-exclusive-disclosures="true"', '', $source))->toArray();
$assert(!str_contains($independent['serialized_blocks'], '"autoclose":true'), 'unproven disclosure groups retain the native independent-open default');
$assert(2 === preg_match_all('/<div class="wp-block-accordion-item[^\"]*blocks-engine-collection-item-[a-f0-9]{16}-[0-9]+/', $markup), 'behavior identity markers survive on the actual native item wrappers, not only in collection metadata');
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

$card = static function (string $heading, string $answer, string $id): string {
    return '<div class="card"><button aria-expanded="false" aria-controls="' . $id . '">' . $heading . '</button><div role="region" id="' . $id . '" data-dla-local-disclosure="true" hidden><p>' . $answer . '</p></div></div>';
};
$names = array('apricot', 'blueberry', 'cranberry', 'dewberry', 'elderberry', 'figfruit', 'gooseberry', 'honeydew', 'kiwifruit', 'lemonfruit', 'mangofruit', 'nectarine', 'olivefruit', 'papayafruit', 'quincefruit', 'raspberry', 'strawberry', 'tangerine', 'uglifruit');
$finiteItems = array();
$membership = array(0 => array(), 1 => array(), 2 => array(), 3 => array());
for ($index = 0; $index < 19; $index++) {
    $heading = 0 === $index % 7 ? 'Shared question?' : 'Question ' . $index . '?';
    $answer = $names[$index] . ' answer text';
    $key = (string) $index;
    $category = $index % 4;
    $html = $card($heading, $answer, 'answer-' . $index);
    $finiteItems[] = array('key' => $key, 'text' => $heading . ' ' . $answer, 'html' => $html, 'categories' => array($category));
    $membership[$category][] = $key;
}
$membership[0] = array('16', '12', '8', '4', '0');
$labels = array('All', 'Alpha', 'Beta', 'Gamma');
$categories = array();
foreach ($labels as $index => $label) {
    $categories[] = array('selector' => 'body > main > div > div > button:nth-of-type(' . ($index + 1) . ')', 'label' => $label, 'index' => $index, 'activeHtml' => '<button class="active">' . $label . '</button>', 'inactiveHtml' => '<button class="inactive">' . $label . '</button>');
}
$resting = '';
foreach ($membership[0] as $key) $resting .= $finiteItems[(int) $key]['html'];
$finiteSource = '<html><head><script data-dla-local-disclosure-runtime="true"></script><script data-dla-collection-runtime="true"></script></head><body><main><div class="scope"><div class="choices"><button class="active">All</button><button class="inactive">Alpha</button><button class="inactive">Beta</button><button class="inactive">Gamma</button></div><input type="search" placeholder="Looking for something?" aria-label="Looking for something?"><p class="status">19 questions</p><div id="results" data-dla-exclusive-disclosures="true"><div class="wrap">' . $resting . '</div></div></div></main></body></html>';
$keys = array_column($finiteItems, 'key');
$global = array(
    array('query' => 's', 'keys' => $keys),
    array('query' => 'apricot', 'keys' => array('0')),
    array('query' => 'APRICOT', 'keys' => array('0')),
    array('query' => 'dla-no-match-7f39b2', 'keys' => array()),
);
$legacy = array();
$categoryProbes = array();
foreach ($membership as $index => $categoryKeys) {
    $legacy[] = array('query' => '', 'category' => $index, 'keys' => $categoryKeys);
    $categoryProbes[] = array('category' => $index, 'keys' => $categoryKeys);
}
$finite = array(
    'field' => array('selector' => 'body > main > div > input', 'value' => ''),
    'target' => array('selector' => 'body > main > div > div#results', 'html' => ''),
    'items' => $finiteItems,
    'itemDepth' => 1,
    'categories' => $categories,
    'initialCategory' => 0,
    'predicate' => 'normalized-text-includes',
    'mode' => 'category-or-global-search',
    'emptyHtml' => '<p>No local matches</p>',
    'emptyPlacement' => 'after',
    'probes' => $legacy,
    'restoration' => 'verified',
    'replay' => 'verified',
    'network' => array('dataRequests' => 'observed-response-replay', 'verification' => 'intercepted-observed-responses'),
    'finiteBootstrap' => array(
        'schema' => 'data-liberation/finite-bootstrap/v1',
        'mode' => 'category-or-global-search',
        'queryIndependent' => true,
        'completeness' => 'declared-finite',
        'declaredCount' => 19,
        'observedItemCount' => 19,
        'coverage' => 'complete',
        'verification' => 'intercepted-observed-responses',
        'replayedResponses' => 1,
        'blockedFollowUps' => 1,
        'sourceFollowUpsBlocked' => 0,
        'unmatchedProbeBlocked' => true,
        'categoryControlsDuringSearch' => 'hidden',
        'emptyQueryRestoresCategory' => true,
        'answers' => 'observed',
        'answerOnly' => 'verified',
        'resources' => 'text-only',
        'order' => array('proof' => 'universal-query', 'query' => 's', 'keys' => $keys, 'categoriesAgree' => false, 'categoryKeys' => array_values($membership)),
        'probes' => array('global' => $global, 'categories' => $categoryProbes),
    ),
);
$finiteFiles = static function (array $evidence) use ($finiteSource): array {
    return array(
        array('path' => 'website/index.html', 'content' => $finiteSource),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'states' => array(array('kind' => 'typed-search', 'status' => 'captured', 'collectionFilter' => $evidence))))))),
    );
};
$finiteProjected = (new CapturedCollectionProjector())->project($finiteFiles($finite));
$assert(1 === $finiteProjected['projected_count'], 'finite bootstrap evidence projects one canonical collection: ' . json_encode($finiteProjected['diagnostics']));
$assert(!str_contains($finiteProjected['files'][0]['content'], 'data-dla-collection-runtime') && !str_contains($finiteProjected['files'][0]['content'], 'data-dla-local-disclosure-runtime'), 'native accordion and collection blocks supersede the portable runtimes');
$finiteResult = (new HtmlTransformer())->transform($finiteProjected['files'][0]['content'])->toArray();
$finiteMarkup = $finiteResult['serialized_blocks'];
$assert('pass' === ($finiteResult['source_reports']['wp_block_validity']['status'] ?? null), 'finite collection serialization is Gutenberg-valid: ' . json_encode($finiteResult['source_reports']['wp_block_validity'] ?? null));
$assert(str_contains($finiteMarkup, 'wp:custom/collection-filter-choices') && str_contains($finiteMarkup, 'Looking for something?') && !str_contains($finiteMarkup, 'wp:search') && !str_contains($finiteMarkup, 'wp:html') && !str_contains($finiteMarkup, 'wp:tabs'), 'category strip, editable field and native tree replace global search and raw islands');
$assert(strpos($finiteMarkup, 'collection-filter-choices') < strpos($finiteMarkup, 'collection-filter-field') && strpos($finiteMarkup, '19 questions') > strpos($finiteMarkup, 'collection-filter-field') && strpos($finiteMarkup, '19 questions') < strpos($finiteMarkup, 'wp:accordion '), 'hiding the category strip does not hide the input, status or results');
$assert(19 === substr_count($finiteMarkup, ' answer text</p>') && 3 === substr_count($finiteMarkup, 'Shared question?'), 'nineteen answers stay one editable copy, including duplicate headings');
$assert(str_contains($finiteMarkup, '"autoclose":true') && strpos($finiteMarkup, '>apricot answer text</p>') < strpos($finiteMarkup, '>raspberry answer text</p>'), 'exclusive parent-scoped disclosures stay native and the canonical tree uses universal-query order');
$assert(str_contains($finiteMarkup, '"mode":"category-or-global-search"') && str_contains($finiteMarkup, 'No local matches') && !str_contains($finiteMarkup, 'dla-no-match-7f39b2'), 'empty state stays query-independent while global order is stored for runtime movement');
$generated = $finiteResult['source_reports']['generated_blocks'] ?? array();
$rootDefinition = null;
foreach ($generated as $definition) if ('collection-filter' === ($definition['name'] ?? null)) $rootDefinition = $definition;
$assert(is_array($rootDefinition) && 'category-and-query' === ($rootDefinition['block_json']['attributes']['mode']['default'] ?? null) && array() === ($rootDefinition['block_json']['attributes']['order']['default'] ?? null), 'runtime payload schema defaults remain canonical');
$assert('' !== RuntimeDeclarations::canonicalJson($generated), 'finite generated definitions retain the bounded canonical runtime payload contract');
$assert(str_contains((string) ($rootDefinition['view_js'] ?? ''), 'export function refresh') && str_contains((string) ($rootDefinition['view_js'] ?? ''), 'appendChild') && !str_contains((string) ($rootDefinition['view_js'] ?? ''), 'cloneNode'), 'finite runtime moves existing nodes and reads owner-edited text');
foreach (array(
    'completeness' => 'paginated',
    'resources' => 'localized',
    'answers' => 'guessed',
) as $key => $value) {
    $invalid = $finite;
    $invalid['finiteBootstrap'][$key] = $value;
    $refused = (new CapturedCollectionProjector())->project($finiteFiles($invalid));
    $assert(0 === $refused['projected_count'] && $finiteSource === $refused['files'][0]['content'], $key . ' mismatch leaves the source unmodified');
}
foreach (array(
    array('restoration', 'unverified'),
    array('replay', 'unsupported'),
    array('network', array('dataRequests' => 'query-dependent', 'verification' => 'unverified')),
) as $change) {
    $invalid = $finite;
    $invalid[$change[0]] = $change[1];
    $assert(0 === (new CapturedCollectionProjector())->project($finiteFiles($invalid))['projected_count'], $change[0] . ' failure cannot waive finite admission');
}
$invalid = $finite;
$invalid['finiteBootstrap']['order']['proof'] = 'source-token';
$assert(0 === (new CapturedCollectionProjector())->project($finiteFiles($invalid))['projected_count'], 'unverifiable order proof is rejected');
$invalid = $finite;
$invalid['finiteBootstrap']['order']['query'] = 'token';
$assert(0 === (new CapturedCollectionProjector())->project($finiteFiles($invalid))['projected_count'], 'order query must be the shared character observed in every item');
$invalid = $finite;
$invalid['finiteBootstrap']['order']['keys'] = array_reverse($keys);
$assert(0 === (new CapturedCollectionProjector())->project($finiteFiles($invalid))['projected_count'], 'items must follow the universal-query key order');
$invalid = $finite;
$invalid['finiteBootstrap']['order']['categoriesAgree'] = true;
$assert(0 === (new CapturedCollectionProjector())->project($finiteFiles($invalid))['projected_count'], 'categoriesAgree is recomputed and cannot be waived');
$invalid = $finite;
$invalid['finiteBootstrap']['probes']['global'][1]['keys'] = array('1', '0');
$assert(0 === (new CapturedCollectionProjector())->project($finiteFiles($invalid))['projected_count'], 'global probes must be the ordered source filter, not a set or category intersection');
$invalid = $finite;
$invalid['items'][1]['text'] = $invalid['items'][0]['text'];
$invalid['items'][1]['html'] = $invalid['items'][0]['html'];
$assert(0 === (new CapturedCollectionProjector())->project($finiteFiles($invalid))['projected_count'], 'ambiguous finite identities are rejected');
$invalid = $finite;
$invalid['itemDepth'] = 4;
$assert(0 === (new CapturedCollectionProjector())->project($finiteFiles($invalid))['projected_count'] && $finiteSource === (new CapturedCollectionProjector())->project($finiteFiles($invalid))['files'][0]['content'], 'item depth must uniquely match the only-child wrapper chain');
$invalid = $finite;
$invalid['items'][0]['html'] = str_replace('<div class="card">', '<div class="card"><img src="photo.jpg" alt="">', $invalid['items'][0]['html']);
$assert(0 === (new CapturedCollectionProjector())->project($finiteFiles($invalid))['projected_count'], 'resource-bearing finite items are not portable');
$invalid = $finite;
unset($invalid['finiteBootstrap']);
$invalid['network'] = array('dataRequests' => 'blocked');
$assert(0 === (new CapturedCollectionProjector())->project($finiteFiles($invalid))['projected_count'], 'blocked requests still require the Ward verifier');
require_once __DIR__ . '/collection-filter-finite-fixture.php';
file_put_contents(collection_filter_artifact_path('collection-filter-finite.json'), json_encode(array('markup' => $finiteMarkup, 'view' => $rootDefinition['view_js'])));
$statusCase = collection_filter_status_case();
$statusFiles = static function (string $html, array $evidence): array {
    return array(
        array('path' => 'website/index.html', 'content' => $html),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'states' => array(array('kind' => 'typed-search', 'status' => 'captured', 'collectionFilter' => $evidence))))))),
    );
};
$legacy = $statusCase['evidence'];
unset($legacy['finiteBootstrap']['status']);
$legacyProjected = (new CapturedCollectionProjector())->project($statusFiles($statusCase['source'], $legacy));
$assert(1 === $legacyProjected['projected_count'] && !str_contains($legacyProjected['files'][0]['content'], 'data-blocks-engine-collection-status'), 'missing status remains a valid collection and invents no label');
$invalidStatus = $statusCase['evidence'];
$invalidStatus['finiteBootstrap']['status']['schema'] = 'data-liberation/collection-status/v2';
$invalidProjected = (new CapturedCollectionProjector())->project($statusFiles($statusCase['source'], $invalidStatus));
$assert(0 === $invalidProjected['projected_count'] && str_contains($invalidProjected['files'][0]['content'], 'data-dla-collection-runtime'), 'invalid present status is not claimed and does not retire the capture helper');
$assert(str_contains(json_encode($invalidProjected['diagnostics']), 'not portable') && str_contains(json_encode($invalidProjected['diagnostics']), 'Observed unsupported status schema'), 'invalid status names the observed unsupported reason');
$onclick = $statusCase['evidence'];
$onclick['finiteBootstrap']['status']['nodes'][1]['html'] = str_replace('<span ', '<span onclick="alert(1)" ', $onclick['finiteBootstrap']['status']['nodes'][1]['html']);
$onclickProjected = (new CapturedCollectionProjector())->project($statusFiles($statusCase['source'], $onclick));
$assert(0 === $onclickProjected['projected_count'] && str_contains(json_encode($onclickProjected['diagnostics']), 'Observed unsupported status onclick'), 'an event handler on a source status body is named and not projected');
$copied = (new CapturedCollectionProjector())->project($statusFiles($statusCase['copies'], $statusCase['evidence']));
$assert(2 === $copied['projected_count'] && 4 === substr_count($copied['files'][0]['content'], 'data-blocks-engine-collection-status'), 'each responsive copy gets the source status once');
$statusRuntime = 'document.querySelector("[data-dla-collection-empty]");';
$statusHtml = str_replace('</head>', '<script data-dla-collection-runtime="true">' . $statusRuntime . '</script></head>', $statusCase['copies']);
$statusCompiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => array('website/index.html' => $statusHtml, 'capture-receipt.json' => $statusFiles($statusHtml, $statusCase['evidence'])[1]['content'], 'interaction-states.json' => $statusFiles($statusHtml, $statusCase['evidence'])[2]['content'])))->toArray();
$statusContracts = array_values(array_filter($statusCompiled['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dependency_contract_failed' === ($diagnostic['code'] ?? '')));
$statusBodies = '';
foreach (array($statusCompiled['files'] ?? array(), $statusCompiled['assets'] ?? array()) as $group) foreach ($group as $file) if (is_array($file) && is_string($file['content'] ?? null)) $statusBodies .= $file['content'];
$assert(array() === $statusContracts && !str_contains($statusBodies, 'data-dla-collection-empty'), 'both assembled status copies retire the extracted collection script: ' . json_encode($statusContracts));
$assert(2 === substr_count((string) ($statusCompiled['serialized_blocks'] ?? ''), 'aria-hidden="false"') && 2 === substr_count((string) ($statusCompiled['serialized_blocks'] ?? ''), 'data-hook="questions-results-found"') && 2 === substr_count((string) ($statusCompiled['serialized_blocks'] ?? ''), 'role="status"'), 'compiled status keeps both source count and query wrappers');
$partialCopies = str_replace('data-dla-collection-item="3"', 'data-dla-collection-item="missing"', $statusHtml);
$partialStatus = (new CapturedCollectionProjector())->project($statusFiles($partialCopies, $statusCase['evidence']));
$assert(0 === $partialStatus['projected_count'] && str_contains($partialStatus['files'][0]['content'], 'data-dla-collection-runtime'), 'an unmatched portable copy keeps the collection script instead of retiring a partial page');
$markedItem = static fn (string $key, string $members, string $answer): string => '<div data-dla-collection-item="' . $key . '" data-dla-collection-members="' . htmlspecialchars($members, ENT_QUOTES) . '"><button aria-expanded="false" aria-controls="m-' . $key . '">Shared question?</button><div id="m-' . $key . '" role="region" hidden><p>' . $answer . '</p></div></div>';
$markedCopy = static function (string $id) use ($markedItem): string {
    return '<section class="copy"><div role="tab" data-dla-collection-category-control="' . $id . '" data-dla-collection-index="0">All</div><div role="tab" data-dla-collection-category-control="' . $id . '" data-dla-collection-index="1">Alpha</div><input data-dla-collection-field="' . $id . '" placeholder="Search locally"><div data-dla-collection="' . $id . '"><div>' . $markedItem('a', '[0,1]', 'Alpha answer') . $markedItem('b', '[0]', 'Beta answer') . '</div></div></section>';
};
$markedSource = '<html><body><main>' . $markedCopy('0') . $markedCopy('1') . '</main></body></html>';
$markedEvidence = $evidence;
$markedEvidence['field']['selector'] = 'input.missing';
$markedEvidence['target']['selector'] = 'div.missing';
foreach ($markedEvidence['categories'] as &$markedCategory) $markedCategory['selector'] = 'button.missing';
unset($markedCategory);
$markedFiles = $files($markedEvidence);
$markedFiles[0]['content'] = $markedSource;
$markedProjected = (new CapturedCollectionProjector())->project($markedFiles);
$assert(2 === $markedProjected['projected_count'], 'portable collection identities project each assembled copy when source selectors no longer match one tree: ' . json_encode($markedProjected['diagnostics']));
$assert(2 === substr_count($markedProjected['files'][0]['content'], 'data-blocks-engine-collection-root'), 'each visible copy keeps its own collection root');
$markedResult = (new HtmlTransformer())->transform($markedProjected['files'][0]['content'])->toArray();
$assert(2 === substr_count($markedResult['serialized_blocks'], '<!-- wp:accordion ') && 2 === substr_count($markedResult['serialized_blocks'], '>Alpha answer</p>') && 2 === substr_count($markedResult['serialized_blocks'], '>Beta answer</p>'), 'each portable copy becomes one native accordion without duplicating that copy');
$markedEvidence['items'][0]['categories'] = array(0);
$markedFiles = $files($markedEvidence);
$markedFiles[0]['content'] = $markedSource;
$assert(0 === (new CapturedCollectionProjector())->project($markedFiles)['projected_count'], 'portable item membership that disagrees with evidence is not projected');

$collectionRuntime = 'document.querySelectorAll("[data-dla-collection-category-control]");document.querySelector("[data-dla-collection-empty]");';
$disclosureRuntime = 'document.querySelectorAll("[data-dla-local-disclosure]");document.querySelector("[data-dla-exclusive-disclosures]");';
$keptRuntime = 'document.getElementById("keep-me").dataset.ready="1";';
$openItem = '<div data-dla-collection-item="a" data-dla-collection-members="[0,1]"><button aria-expanded="true" aria-controls="m-a">Shared question?</button><div id="m-a" role="region" data-dla-local-disclosure="true"><p>Alpha answer</p></div></div>';
$closedItem = '<div data-dla-collection-item="b" data-dla-collection-members="[0]"><button aria-expanded="false" aria-controls="m-b">Shared question?</button><div id="m-b" role="region" data-dla-local-disclosure="true" hidden><p>Beta answer</p></div></div>';
$extractedCopy = '<section class="copy"><div role="tab" data-dla-collection-category-control="0" data-dla-collection-index="0">All</div><div role="tab" data-dla-collection-category-control="0" data-dla-collection-index="1">Alpha</div><input data-dla-collection-field="0" placeholder="Search locally"><div data-dla-collection="0" data-dla-exclusive-disclosures="true"><div>' . $openItem . $closedItem . '</div></div></section>';
$extractedHtml = static fn (string $sibling): string => '<html><head><script>' . $keptRuntime . '</script><script data-dla-collection-runtime="true">' . $collectionRuntime . '</script><script data-dla-local-disclosure-runtime="true">' . $disclosureRuntime . '</script></head><body><main><p id="keep-me">Keep</p>' . $extractedCopy . $sibling . '</main></body></html>';
$runtimeEvidence = $evidence;
$runtimeEvidence['field']['selector'] = 'input.missing';
$runtimeEvidence['target']['selector'] = 'div.missing';
foreach ($runtimeEvidence['categories'] as &$runtimeCategory) $runtimeCategory['selector'] = 'button.missing';
unset($runtimeCategory);
$extractedArtifact = static function (string $html) use ($files, $runtimeEvidence): array {
    $rows = $files($runtimeEvidence);
    $mapped = array();
    foreach ($rows as $row) $mapped[$row['path']] = 'website/index.html' === $row['path'] ? $html : $row['content'];
    return array('entrypoint' => 'website/index.html', 'files' => $mapped);
};
$scriptBodies = static function (array $compiled): string {
    $bodies = array();
    foreach ($compiled['files'] ?? array() as $file) if (is_array($file) && is_string($file['content'] ?? null)) $bodies[] = $file['content'];
    foreach ($compiled['assets'] ?? array() as $file) if (is_array($file) && is_string($file['content'] ?? null)) $bodies[] = $file['content'];
    foreach ($compiled['source_reports']['wordpress_site_plan']['pages'] ?? array() as $page) foreach ($page['document_metadata']['scripts'] ?? array() as $script) if (is_string($script['content'] ?? null)) $bodies[] = $script['content'];
    return implode("\n", $bodies);
};
$complete = (new ArtifactCompiler())->compile($extractedArtifact($extractedHtml('')))->toArray();
$completeBodies = $scriptBodies($complete);
$completeContracts = array_values(array_filter($complete['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'runtime_dependency_contract_failed' === ($diagnostic['code'] ?? '')));
$assert(array() === $completeContracts, 'extracted capture runtimes are omitted after every binding is native: ' . json_encode($completeContracts));
$assert(!str_contains($completeBodies, 'data-dla-collection-category-control') && !str_contains($completeBodies, 'data-dla-local-disclosure') && str_contains($completeBodies, 'keep-me'), 'only the proven collection and disclosure script files are omitted');
$replacements = $complete['source_reports']['native_runtime_replacements'] ?? array();
$assert(2 === count($replacements) && array('website/index.inline-2.js', 'website/index.inline-3.js') === array_column($replacements, 'asset_source_path'), 'script policy names the extracted files superseded before projection: ' . json_encode(array_column($replacements, 'asset_source_path')));
$completeMarkup = (string) ($complete['serialized_blocks'] ?? '');
$assert(1 === substr_count($completeMarkup, '>Alpha answer</p>') && 1 === substr_count($completeMarkup, '>Beta answer</p>') && str_contains($completeMarkup, '"openByDefault":true'), 'native items stay one tree and the source-open disclosure stays open');
$partial = (new ArtifactCompiler())->compile($extractedArtifact($extractedHtml('<div data-dla-local-disclosure="true" id="untouched-sibling"><p>Sibling region</p></div>')))->toArray();
$partialBodies = $scriptBodies($partial);
$partialPaths = array_column($partial['source_reports']['native_runtime_replacements'] ?? array(), 'asset_source_path');
$assert(array('website/index.inline-2.js') === $partialPaths && str_contains($partialBodies, 'data-dla-local-disclosure') && !str_contains($partialBodies, 'data-dla-collection-category-control'), 'an unconverted sibling keeps its disclosure runtime while the completed collection runtime is still omitted: ' . json_encode($partialPaths));
$snapshotEvidence = $evidence;
$snapshotEvidence['field']['selector'] = 'input.missing';
$snapshotEvidence['target']['selector'] = 'div.snapshot-region';
$snapshotEvidence['categories'][0]['selector'] = '#cat-all';
$snapshotEvidence['categories'][1]['selector'] = '#cat-alpha';
$snapshotFiles = $files($snapshotEvidence);
$snapshotFiles[0]['content'] = $markedSource;
$snapshotProjected = (new CapturedCollectionProjector())->project($snapshotFiles);
$snapshotBindings = $snapshotProjected['consumed_selectable_bindings']['website/index.html'] ?? array();
$assert(1 === count($snapshotBindings) && array('#cat-all', '#cat-alpha') === $snapshotBindings[0]['categories'] && 'div.snapshot-region' === $snapshotBindings[0]['target'], 'a completed native collection records the category trigger and dialog identities it bound');
$selectableState = static function (string $trigger, string $dialog, string $html, int $index, string $set = 'div.category-set'): array {
    return array(
        'status' => 'captured',
        'kind' => 'selectable-set',
        'trigger' => array('selector' => $trigger, 'tag' => 'button', 'label' => 'Category', 'ariaHaspopup' => '', 'dataBindings' => array()),
        'dialog' => array('selector' => $dialog, 'tag' => 'div', 'html' => $html, 'htmlBytes' => strlen($html), 'htmlTruncated' => false),
        'set' => array('selector' => $set, 'size' => 2, 'index' => $index),
    );
};
$snapshotHtml = '<div><p>Alpha answer snapshot</p></div>';
$siblingHtml = '<div><p>Sibling answer</p></div>';
$withSelectable = static function (array $projectedFiles, array $states): array {
    $rows = $projectedFiles;
    foreach ($rows as &$row) {
        if ('interaction-states.json' !== ($row['path'] ?? '')) continue;
        $report = json_decode((string) $row['content'], true);
        $report['pages'][0]['states'] = array_merge($report['pages'][0]['states'], $states);
        $row['content'] = json_encode($report);
    }
    unset($row);
    return $rows;
};
$consumedSelectable = (new CapturedSelectableSetProjector())->project($withSelectable($snapshotProjected['files'], array(
    $selectableState('#cat-all', 'div.snapshot-region', $snapshotHtml, 0),
    $selectableState('#cat-alpha', 'div.snapshot-region', $snapshotHtml, 1),
    $selectableState('#cat-all', 'div.mobile-snapshot-region', $snapshotHtml, 0, 'div.mobile-category-set'),
    $selectableState('#cat-alpha', 'div.mobile-snapshot-region', $snapshotHtml, 1, 'div.mobile-category-set'),
)), array('website/index.html' => $snapshotBindings));
$consumedMarkup = (string) ($consumedSelectable['files'][0]['content'] ?? '');
$assert(!str_contains($consumedMarkup, 'Alpha answer snapshot') && !str_contains($consumedMarkup, 'data-tabs') && !in_array('captured_selectable_set_region_appended', array_column($consumedSelectable['diagnostics'], 'code'), true), 'completed collection bindings do not append a category snapshot: ' . json_encode(array_column($consumedSelectable['diagnostics'], 'code')));
$assert(2 === substr_count($consumedMarkup, '>Alpha answer</p>') && 2 === substr_count($consumedMarkup, '>Beta answer</p>'), 'each responsive copy keeps one source answer');
$partialSelectable = (new CapturedSelectableSetProjector())->project($withSelectable($snapshotProjected['files'], array(
    $selectableState('#cat-all', 'div.snapshot-region', $snapshotHtml, 0),
    $selectableState('#cat-alpha', 'div.snapshot-region', $snapshotHtml, 1),
    $selectableState('#other-trigger', 'div.sibling-region', $siblingHtml, 0, 'div.sibling-set'),
    $selectableState('#another-trigger', 'div.sibling-region', $siblingHtml, 1, 'div.sibling-set'),
)), array('website/index.html' => $snapshotBindings));
$partialMarkup = (string) ($partialSelectable['files'][0]['content'] ?? '');
$assert(str_contains($partialMarkup, 'Sibling answer') && !str_contains($partialMarkup, 'Alpha answer snapshot'), 'an unrelated sibling selectable group is retained while the consumed collection group is not');
echo "Captured collection filter contract passed\n";
