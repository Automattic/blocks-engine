<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$cards = array('one' => 'a', 'two' => 'a', 'three' => 'b', 'four' => 'c');
$options = array('all' => 'All categories', 'a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma');
$article = static fn ($key, $category) => '<article class="card" data-cat="' . $category . '"><h3>Card ' . $key . '</h3><p>Category ' . $category . '</p></article>';
$member = static fn ($key) => json_encode(array('card-' . $key));
$subset = static fn ($category) => array_values(array_map($member, array_keys(array_filter($cards, static fn ($value) => 'all' === $category || $value === $category))));

foreach (array('b', null) as $selected) {
    $native = array('version' => 1, 'kind' => 'select', 'options' => array_map(static fn ($value) => array('selected' => $value === $selected, 'defaultSelected' => false), array_keys($options)));
    $select = '<select id="cat-filter" data-blocks-engine-capture-native-control-state="' . htmlspecialchars(json_encode($native), ENT_QUOTES) . '">';
    foreach ($options as $value => $text) $select .= '<option value="' . $value . '">' . $text . '</option>';
    $select .= '</select>';
    $html = '<!doctype html><html><head><style>.filters{display:flex;flex-direction:column;gap:12px}.filters label{display:grid;gap:6px;font-weight:700}.cards{display:grid;gap:16px}.card{padding:16px;border:1px solid #ddd}</style></head><body><main><section class="browser"><h2>Browse</h2><div class="filters"><label>Category' . $select . '</label><input id="q" type="search" placeholder="Search"></div><div id="card-list" class="cards">' . implode('', array_map($article, array_keys($cards), $cards)) . '</div></section></main></body></html>';
    $initialMembers = $subset(null === $selected ? 'all' : $selected);
    $states = array(array('id' => 'initial', 'members' => $initialMembers), array('id' => 'all-pages', 'members' => $initialMembers));
    foreach (array_keys($options) as $index => $value) $states[] = array('id' => 'control-0-' . $index, 'trigger' => array('selector' => '#cat-filter', 'action' => 'select', 'value' => $value), 'members' => $subset($value));
    $component = array('selector' => '#card-list', 'kind' => 'collection', 'initial' => 'initial', 'states' => array(array('id' => 'initial', 'html' => '<div id="card-list" class="cards">' . $article('three', 'b') . '</div>')), 'transitions' => array(), 'status' => 'captured',
        'collection' => array('members' => array_map(static fn ($key, $category) => array('key' => $member($key), 'url' => '', 'text' => 'Card ' . $key . 'Category ' . $category, 'html' => $article($key, $category)), array_keys($cards), $cards), 'states' => $states, 'controls' => array('#cat-filter'), 'coverage' => 'complete', 'limits' => array('pages' => 20, 'members' => 200, 'controls' => 24), 'replay' => 'unsupported'),
        'reason' => 'Collection membership captured; collection control replay unsupported');
    $ref = 'sha256:' . hash('sha256', json_encode($component));
    $schema = 'blocks-engine-capture/captured-interactions/v3';
    $report = array('schema' => $schema, 'sharedComponents' => array($ref => $component), 'pages' => array(array('schema' => $schema, 'sourceUrl' => 'https://example.test/', 'routeNavigation' => array(), 'viewport' => array('width' => 1440, 'height' => 900), 'capturedAt' => '2026-01-01T00:00:00Z', 'components' => array(array('ref' => $ref)))), 'totals' => new stdClass());
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $html,
        'interaction-states.json' => json_encode($report),
        'capture-receipt.json' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'index.html')))),
    )))->toArray();
    $assert('failed' !== $result['status'], 'Neutral captured collection compiles.');
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $markup = $plan['pages'][0]['canonical_block_markup'] ?? '';
    $choices = array();
    $root = null;
    preg_match_all('/<!-- wp:[^ ]+ (\{[^\n]*?\}) -->/', $markup, $matches);
    foreach ($matches[1] as $json) {
        $attrs = json_decode($json, true);
        if (isset($attrs['initialCategory'], $attrs['items'])) $root = $attrs;
        if (isset($attrs['index'], $attrs['initial'])) $choices[] = $attrs;
    }
    $assert(4 === count($choices), 'Each option becomes one choice.');
    $document = HtmlSerialization::parseDocument($markup);
    $xpath = new DOMXPath($document);
    $buttons = $xpath->query('//button[@data-wp-on--click]');
    $texts = array();
    foreach ($buttons as $button) {
        $texts[] = trim($button->textContent);
        $assert(0 === $xpath->query('ancestor::label', $button)->length, 'Choices are never nested inside a label.');
    }
    $assert(array_values($options) === $texts, 'Choices retain option text, not the source label.');
    $assert(1 === $xpath->query('//text()[normalize-space(.)="Category"]')->length, 'Source label text renders exactly once.');
    $assert(1 === $xpath->query('//*[@role="group" and @aria-label="Category"]')->length, 'The source label names the choice group.');
    $initial = array_values(array_filter($choices, static fn ($choice) => $choice['initial']));
    $assert((null === $selected ? 0 : 1) === count($initial), 'Only a captured selection is initially active.');
    $assert(null !== $root, 'The compiled collection carries its initial membership.');
    if (null !== $selected) $assert($choices[2] === $initial[0] && $root['initialCategory'] === $choices[2]['index'], 'Beta is the single initial choice.');
    // The rendered collection runtime uses this category and these item memberships
    // on init to hide non-members, rather than showing the captured union.
    $visible = array_values(array_filter($root['items'], static fn ($item) => in_array($root['initialCategory'], $item['categories'], true)));
    $assert((null === $selected ? array_column($root['items'], 'marker') : array($root['items'][2]['marker'])) === array_column($visible, 'marker'), 'Initial visible membership is only the b card, or the union without selection.');
}
echo "captured-select-collection: ok\n";
