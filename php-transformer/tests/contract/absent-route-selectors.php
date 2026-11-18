<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
};

// Four routes link one stylesheet. The home page renders its names as
// <strong> and the others as <p>, so projection scopes the home variant to
// the home route class (`:root:is(.be-r-…)`). Box-tree evidence then owns the
// home page's styles, and the shared sheet is only delivered to the other
// three routes: no document loading it carries the home route class, so the
// home-scoped arms would ship there without ever matching.
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 64, JSON_THROW_ON_ERROR);
$body = '<div class="page"><div class="card"><div class="detail"><span class="city">Makkah</span><strong class="name"><a href="/h/a/">Hotel A</a></strong></div><div class="detail"><span class="city">Madinah</span><strong class="name">Hotel B</strong></div></div></div>';
$document = static fn (string $body): string => '<!doctype html><html><head><title>Hotels</title><link rel="stylesheet" href="/style.css"></head><body>' . $body . '</body></html>';
// Stamp every element with a node id and observe it with the fixture's leaf box.
$observed = static function (string $html) use ($template): array {
    $nodes = $stack = array();
    $count = 0;
    $html = (string) preg_replace_callback('/<(\/?)([a-z][a-z0-9]*)([^>]*)>/i', static function (array $match) use (&$nodes, &$stack, &$count): string {
        if ('/' === $match[1]) {
            array_pop($stack);
            return $match[0];
        }
        $id = 'n' . ++$count;
        $nodes[$id] = array('children' => array());
        if (array() !== $stack) $nodes[end($stack)]['children'][] = $id;
        if ('link' !== strtolower($match[2])) $stack[] = $id;
        return '<' . $match[2] . ' data-blocks-engine-node-id="' . $id . '"' . $match[3] . '>';
    }, $html);
    foreach ($nodes as &$node) $node['viewports'] = $template['nodes']['n7']['viewports'];
    unset($node);
    return array($html, array('schema' => $template['schema'], 'source_path' => 'index.html', 'source_hash' => hash('sha256', $html), 'viewports' => $template['viewports'], 'nodes' => $nodes));
};
[$home, $tree] = $observed($document($body));
$files = array(
    'style.css' => '.name{font:600 12px/1.45 sans-serif;color:#35463f}.page .card .name{font-size:14px;font-weight:750;color:#315a0b}a{color:inherit}',
    'index.html' => $home,
    'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
);
foreach (array('second.html', 'third.html', 'fourth.html') as $path) {
    $files[$path] = $document(str_replace(array('<strong', '</strong>'), array('<p', '</p>'), $body));
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];

$pages = array_column($plan['pages'], null, 'source_path');
$assert(!empty($pages['index.html']['document_metadata']['engine_owned_styles']), 'Evidence owns the home route styles.');
$homeClass = preg_match('/\bbe-r-[0-9a-f]+\b/', (string) $pages['index.html']['document_metadata']['root_attributes']['class'], $match) ? $match[0] : '';
$assert('' !== $homeClass, 'The home route carries a route class.');

$assets = array_column($plan['assets'], null, 'target_path');
$sheets = array_values(array_filter($plan['assets'], static fn (array $asset): bool => str_ends_with((string) $asset['source_path'], 'style.css') && !empty($asset['stylesheet_instances'])));
$assert(1 === count($sheets), 'One projected author sheet is delivered.');
$sheet = $sheets[0];
$assert(!in_array('index.html', array_column($sheet['stylesheet_instances'], 'source_path'), true) && !in_array('index.html', array_column($sheet['scopes'], 'source_path'), true), 'The author sheet is not delivered to the evidence route.');
foreach (array('second.html', 'third.html', 'fourth.html') as $path) {
    $delivered = '';
    foreach (ThemeBootstrap::documentStylesheets($plan, $path) as $row) $delivered .= (string) ($assets[$row['target_path']]['content'] ?? '');
    $assert(str_contains($delivered, 'font-weight:750'), "$path still receives its own author rules.");
}
$required = (string) preg_replace('/:not\([^()]*\)/', '', (string) $sheet['content']);
$assert(!str_contains($required, '.' . $homeClass), 'The delivered sheet ships no arm requiring the route class of a route it is not delivered to.');
