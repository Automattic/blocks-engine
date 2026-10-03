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
