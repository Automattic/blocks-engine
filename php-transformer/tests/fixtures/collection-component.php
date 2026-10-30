<?php
declare(strict_types=1);
$members = array();
for ($i = 0; $i < 16; $i++) $members[] = array('key' => 'record-' . $i, 'text' => 'Record ' . $i, 'html' => '<article><h2><a href="/record-' . $i . '">Record ' . $i . '</a></h2><p>Editable record ' . $i . '</p></article>');
$keys = array_column($members, 'key');
$states = array(array('id' => 'initial', 'members' => array_slice($keys, 0, 12)), array('id' => 'initial-page-1', 'trigger' => array('selector' => '#next', 'action' => 'click'), 'members' => array_slice($keys, 12)), array('id' => 'all-pages', 'members' => $keys));
for ($i = 0; $i < 4; $i++) $states[] = array('id' => 'control-' . $i, 'trigger' => array('selector' => '#filter-' . $i, 'action' => 'click'), 'members' => $i === 0 ? $keys : array_values(array_filter($keys, static fn($key) => (int) substr($key, 7) % 3 === $i - 1)));
$html = '<html><body><main><section><button id="filter-0">Reset</button><button id="filter-1">Alpha</button><button id="filter-2">Beta</button><button id="filter-3">Gamma</button><input id="query" type="search"><div id="records">' . implode('', array_column(array_slice($members, 0, 12), 'html')) . '</div><button id="next">Next</button></section></main></body></html>';
$states[] = array('id' => 'search-empty', 'trigger' => array('selector' => '#query', 'action' => 'fill', 'value' => ''), 'members' => $keys);
$component = array('kind' => 'collection', 'selector' => '#records', 'status' => 'captured', 'states' => array(), 'collection' => array('members' => $members, 'states' => $states, 'controls' => array('#filter-0', '#filter-1', '#filter-2', '#filter-3', '#query', '#next'), 'coverage' => 'complete', 'limits' => array('pages' => 20, 'members' => 200, 'controls' => 24), 'replay' => 'unsupported'));
$files = array(array('path' => 'index.html', 'content' => $html), array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'index.html'))))), array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => array($component)))))));
return $files;
