<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedCollectionProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

function collection_filter_artifact_dir(): string
{
    $dir = getenv('COLLECTION_FILTER_ARTIFACT_DIR');
    if (is_string($dir) && '' !== $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Collection filter artifact directory could not be created.');
        }
        return $dir;
    }

    return sys_get_temp_dir();
}

function collection_filter_artifact_path(string $name): string
{
    return rtrim(collection_filter_artifact_dir(), '/') . '/' . $name;
}

/** @return array{markup:string, view:string} */
function collection_filter_finite_browser_artifact(): array
{
    $card = static function (string $heading, string $answer, string $id): string {
        return '<div class="card"><button aria-expanded="false" aria-controls="' . $id . '">' . $heading . '</button><div role="region" id="' . $id . '" data-dla-local-disclosure="true" hidden><p>' . $answer . '</p></div></div>';
    };
    $names = array('apricot', 'blueberry', 'cranberry', 'dewberry', 'elderberry', 'figfruit', 'gooseberry', 'honeydew', 'kiwifruit', 'lemonfruit', 'mangofruit', 'nectarine', 'olivefruit', 'papayafruit', 'quincefruit', 'raspberry', 'strawberry', 'tangerine', 'uglifruit');
    $items = array();
    $membership = array(0 => array(), 1 => array(), 2 => array(), 3 => array());
    for ($index = 0; $index < 19; $index++) {
        $heading = 0 === $index % 7 ? 'Shared question?' : 'Question ' . $index . '?';
        $answer = $names[$index] . ' answer text';
        $key = (string) $index;
        $category = $index % 4;
        $items[] = array('key' => $key, 'text' => $heading . ' ' . $answer, 'html' => $card($heading, $answer, 'answer-' . $index), 'categories' => array($category));
        $membership[$category][] = $key;
    }
    $membership[0] = array('16', '12', '8', '4', '0');
    $categories = array();
    foreach (array('All', 'Alpha', 'Beta', 'Gamma') as $index => $label) {
        $categories[] = array('selector' => 'body > main > div > div > button:nth-of-type(' . ($index + 1) . ')', 'label' => $label, 'index' => $index, 'activeHtml' => '<button class="active">' . $label . '</button>', 'inactiveHtml' => '<button class="inactive">' . $label . '</button>');
    }
    $resting = '';
    foreach ($membership[0] as $key) {
        $resting .= $items[(int) $key]['html'];
    }
    $source = '<html><head><script data-dla-local-disclosure-runtime="true"></script><script data-dla-collection-runtime="true"></script></head><body><main><div class="scope"><div class="choices"><button class="active">All</button><button class="inactive">Alpha</button><button class="inactive">Beta</button><button class="inactive">Gamma</button></div><input type="search" placeholder="Looking for something?" aria-label="Looking for something?"><p class="status">19 questions</p><div id="results" data-dla-exclusive-disclosures="true"><div class="wrap">' . $resting . '</div></div></div></main></body></html>';
    $keys = array_column($items, 'key');
    $legacy = array();
    $categoryProbes = array();
    foreach ($membership as $index => $categoryKeys) {
        $legacy[] = array('query' => '', 'category' => $index, 'keys' => $categoryKeys);
        $categoryProbes[] = array('category' => $index, 'keys' => $categoryKeys);
    }
    $evidence = array(
        'field' => array('selector' => 'body > main > div > input', 'value' => ''),
        'target' => array('selector' => 'body > main > div > div#results', 'html' => ''),
        'items' => $items,
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
            'probes' => array(
                'global' => array(
                    array('query' => 's', 'keys' => $keys),
                    array('query' => 'apricot', 'keys' => array('0')),
                    array('query' => 'APRICOT', 'keys' => array('0')),
                    array('query' => 'dla-no-match-7f39b2', 'keys' => array()),
                ),
                'categories' => $categoryProbes,
            ),
        ),
    );
    $projected = (new CapturedCollectionProjector())->project(array(
        array('path' => 'website/index.html', 'content' => $source),
        array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'data-liberation/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'website/index.html'))))),
        array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'data-liberation/captured-interactions/v1', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'states' => array(array('kind' => 'typed-search', 'status' => 'captured', 'collectionFilter' => $evidence))))))),
    ));
    if (1 !== ($projected['projected_count'] ?? 0)) {
        throw new RuntimeException('Finite collection browser fixture did not project.');
    }
    $result = (new HtmlTransformer())->transform($projected['files'][0]['content'])->toArray();
    $view = '';
    foreach ($result['source_reports']['generated_blocks'] ?? array() as $definition) {
        if ('collection-filter' === ($definition['name'] ?? null)) {
            $view = (string) ($definition['view_js'] ?? '');
        }
    }
    if ('' === $view) {
        throw new RuntimeException('Finite collection browser fixture has no collection runtime.');
    }

    return array('markup' => (string) ($result['serialized_blocks'] ?? ''), 'view' => $view);
}

/** @return array{source:string,evidence:array<string,mixed>,copies:string} */
function collection_filter_status_case(): array
{
    $card = static fn (string $text): string => '<div class="card"><p>' . $text . '</p></div>';
    $rows = array(
        array('0', 'apricot answer text', array(0)),
        array('1', 'blueberry answer text', array(0)),
        array('2', 'cranberry answer text', array(0)),
        array('3', 'dewberry answer text', array(1)),
    );
    $items = array();
    foreach ($rows as $row) $items[] = array('key' => $row[0], 'text' => $row[1], 'html' => $card($row[1]), 'categories' => $row[2]);
    $categories = array();
    foreach (array('All', 'Other') as $index => $label) {
        $categories[] = array('selector' => 'body > main > div > div > button:nth-of-type(' . ($index + 1) . ')', 'label' => $label, 'index' => $index, 'activeHtml' => '<button class="active">' . $label . '</button>', 'inactiveHtml' => '<button class="inactive">' . $label . '</button>');
    }
    $resting = $card($rows[0][1]) . $card($rows[1][1]) . $card($rows[2][1]);
    $source = '<html><head><script data-dla-collection-runtime="true"></script></head><body><main><div class="scope"><div class="choices"><button class="active">All</button><button class="inactive">Other</button></div><input type="search" placeholder="Search" aria-label="Search"><div id="results">' . $resting . '</div></div></main></body></html>';
    $keys = array('0', '1', '2', '3');
    $evidence = array(
        'field' => array('selector' => 'body > main > div > input', 'value' => ''),
        'target' => array('selector' => 'body > main > div > div#results', 'html' => ''),
        'items' => $items,
        'itemDepth' => 0,
        'categories' => $categories,
        'initialCategory' => 0,
        'predicate' => 'normalized-text-includes',
        'mode' => 'category-or-global-search',
        'emptyHtml' => '<p>0 matching results found</p><p>Try another word.</p>',
        'emptyPlacement' => 'after',
        'probes' => array(
            array('query' => '', 'category' => 0, 'keys' => array('0', '1', '2')),
            array('query' => '', 'category' => 1, 'keys' => array('3')),
        ),
        'restoration' => 'verified',
        'replay' => 'verified',
        'network' => array('dataRequests' => 'observed-response-replay', 'verification' => 'intercepted-observed-responses'),
        'finiteBootstrap' => array(
            'schema' => 'data-liberation/finite-bootstrap/v1',
            'mode' => 'category-or-global-search',
            'queryIndependent' => true,
            'completeness' => 'declared-finite',
            'declaredCount' => 4,
            'observedItemCount' => 4,
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
            'order' => array('proof' => 'universal-query', 'query' => 'a', 'keys' => $keys, 'categoriesAgree' => true, 'categoryKeys' => array(array('0', '1', '2'), array('3'))),
            'probes' => array(
                'global' => array(
                    array('query' => 'a', 'keys' => $keys),
                    array('query' => 'berry', 'keys' => array('1', '2', '3')),
                    array('query' => 'APRICOT', 'keys' => array('0')),
                    array('query' => 'dla-no-match-7f39b2', 'keys' => array()),
                ),
                'categories' => array(
                    array('category' => 0, 'keys' => array('0', '1', '2')),
                    array('category' => 1, 'keys' => array('3')),
                ),
            ),
            'status' => array(
                'schema' => 'data-liberation/collection-status/v1',
                'nodes' => array(
                    array('html' => '<div role="status" class="aTKtkk" aria-live="polite" aria-atomic="true">{count} matching results found</div>', 'template' => '{count} matching results found', 'binds' => array('count'), 'placement' => 'before-items', 'hidesAtZero' => true),
                    array('html' => '<div class="styp7eL oY1h81f--resultFound"><div class="sC8z_ff"><span class="sf4zzLJ runningText" data-hook="text">Showing results for: {query}</span></div></div>', 'template' => 'Showing results for: {query}', 'binds' => array('query'), 'placement' => 'before-items', 'hidesAtZero' => true),
                ),
            ),
        ),
    );
    $status = static function (string $id): string {
        return '<div data-dla-collection-status="' . $id . '" hidden data-dla-status-hide-zero="true" role="status" class="aTKtkk" aria-live="polite" aria-atomic="true" data-dla-status-template="{count} matching results found">{count} matching results found</div><div data-dla-collection-status="' . $id . '" hidden data-dla-status-hide-zero="true" class="styp7eL oY1h81f--resultFound"><div class="sC8z_ff"><span class="sf4zzLJ runningText" data-hook="text" data-dla-status-template="Showing results for: {query}">Showing results for: {query}</span></div></div>';
    };
    $copy = static function (string $id) use ($rows, $status): string {
        $items = '';
        foreach ($rows as $row) $items .= '<div class="card" data-dla-collection-item="' . $row[0] . '" data-dla-collection-members="' . htmlspecialchars(json_encode($row[2]), ENT_QUOTES) . '"><p>' . $row[1] . '</p></div>';
        return '<section class="copy"><div role="tab" data-dla-collection-category-control="' . $id . '" data-dla-collection-index="0">All</div><div role="tab" data-dla-collection-category-control="' . $id . '" data-dla-collection-index="1">Other</div><input data-dla-collection-field="' . $id . '" type="search" aria-label="Search">' . $status($id) . '<div data-dla-collection="' . $id . '">' . $items . '</div></section>';
    };
    return array('source' => $source, 'evidence' => $evidence, 'copies' => '<html><head><script data-dla-collection-runtime="true"></script></head><body><main>' . $copy('0') . $copy('1') . '</main></body></html>');
}
