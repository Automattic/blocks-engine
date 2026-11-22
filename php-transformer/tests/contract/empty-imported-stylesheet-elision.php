<?php
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
};

// A widget-library theme (the shape of a jQuery UI theme folder): the page
// links all.css, which imports base.css and used.css; base.css only imports
// widget.css and tabs.css, whose selectors no route renders. Projection
// empties widget.css and tabs.css. Their imports load nothing, so base.css is
// empty once they go, and the whole chain is neither written nor imported.
// An inline <style> on the other route imports a font sheet whose faces have
// no source (a fetched Google Fonts response stripped of its font URLs):
// writing empties it, and its import goes with it. The home route has box-tree
// evidence and the other route has none, so each shared sheet also has an
// evidence-retained sibling that empties the same way.
$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 64, JSON_THROW_ON_ERROR);
$observed = static function (string $html, string $sourcePath) use ($template): array {
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
        if (!in_array(strtolower($match[2]), array('link', 'img', 'meta'), true)) $stack[] = $id;
        return '<' . $match[2] . ' data-blocks-engine-node-id="' . $id . '"' . $match[3] . '>';
    }, $html);
    foreach ($nodes as &$node) $node['viewports'] = $template['nodes']['n7']['viewports'];
    unset($node);
    return array($html, array('schema' => $template['schema'], 'source_path' => $sourcePath, 'source_hash' => hash('sha256', $html), 'viewports' => $template['viewports'], 'nodes' => $nodes));
};
$fonts = 'external/0123456789abcdef/css2-0123456789ab.css';
$document = static fn (string $title, string $head): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/theme/all.css">' . $head . '</head><body><main><h1>' . $title . '</h1><p class="lead">Body copy for ' . $title . '.</p></main></body></html>';
[$home, $tree] = $observed($document('Home', ''), 'index.html');
$files = array(
    'index.html' => $home,
    'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR),
    'about.html' => $document('About', '<style>@import url("/' . $fonts . '");' . "\n" . '.lead{letter-spacing:1px}</style>'),
    'theme/all.css' => '@import "base.css";@import "used.css";',
    'theme/base.css' => "@import url(\"/theme/widget.css\");\n\n@import url(\"/theme/tabs.css\");\n",
    'theme/widget.css' => '.ui-widget{font-family:Arial,sans-serif}.ui-state-default{border:1px solid #327e04}',
    'theme/tabs.css' => '.ui-tabs{padding:.2em}.ui-tabs .ui-tabs-nav li{float:left}',
    'theme/used.css' => '.lead{font-weight:700}',
    $fonts => "/* latin */\n@font-face {\n  font-family: 'Test';\n  font-style: normal;\n  font-weight: 400;\n  font-display: swap;\n  unicode-range: U+0000-00FF;\n}\n",
);
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(array() !== $plan, 'The site plan compiles.');
WordPressSitePlan::assertValid($plan);
$pages = array_column($plan['pages'], null, 'source_path');
$assert(!empty($pages['index.html']['document_metadata']['engine_owned_styles']) && empty($pages['about.html']['document_metadata']['engine_owned_styles']), 'The home route is an evidence route and the other route is not.');

$writes = array_column($plan['writes'], null, 'target_path');
$assert(0 === ($plan['quality']['metrics']['empty_asset_count'] ?? null), 'The site plan reports empty_asset_count 0.');
$assert(array() === array_filter($plan['writes'], static fn (array $write): bool => 'theme_asset' === $write['kind'] && str_ends_with($write['target_path'], '.css') && '' === trim((string) ($write['payload']['data'] ?? 'x'))), 'No zero-byte stylesheet is written.');
foreach (array('theme/base', 'theme/widget', 'theme/tabs', substr($fonts, 0, -4)) as $stem) {
    $assert(array() === array_filter(array_keys($writes), static fn (string $target): bool => str_starts_with($target, 'assets/' . $stem . '.')), "No write targets the emptied $stem sheet or its evidence-retained sibling.");
}
$tokens = array_column($plan['reference_tokens'], 'target_path', 'token');
$all = (string) ($writes['assets/theme/all.css']['payload']['data'] ?? '');
$assert(preg_match_all('/asset:([^}]+)\}\}/', $all, $imports) && array('assets/theme/used.css') === array_map(static fn (string $token): ?string => $tokens[$token] ?? null, $imports[1]), 'all.css still imports used.css and nothing else.');
$assert(str_contains((string) ($writes['assets/theme/used.css']['payload']['data'] ?? ''), 'font-weight:700'), 'The imported sheet with live rules is still written.');
foreach ($plan['writes'] as $write) {
    $data = (string) ($write['payload']['data'] ?? '');
    if ('utf8' !== ($write['payload']['encoding'] ?? null) || !preg_match_all('/asset:([^}]+)\}\}/', $data, $named)) continue;
    foreach ($named[1] as $token) $assert(isset($tokens[$token]), 'Every reference token written is declared: ' . $write['target_path']);
}
foreach ($plan['pages'] as $page) foreach ($page['document_metadata']['links'] ?? array() as $order => $link) {
    $assert($order === $link['order'], 'Head link declarations stay densely ordered.');
    $assert(preg_match('/asset:([^}]+)\}\}/', (string) ($link['asset_reference'] ?? ''), $match) && isset($tokens[$match[1]]), 'Every head link declaration names a declared asset.');
}
$delivered = array_column(ThemeBootstrap::documentStylesheets($plan, 'about.html'), 'target_path');
$assert(in_array('assets/theme/all.css', $delivered, true), 'The route without evidence still enqueues all.css.');
$assert(!preg_match('/(?:base|widget|tabs|css2-0123456789ab)\.(?:evidence-retained\.)?css/', (string) ($writes['functions.php']['payload']['data'] ?? '')), 'The bootstrap does not reference an emptied sheet.');
