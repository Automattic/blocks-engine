<?php
/**
 * Shared template parts render on evidence routes without carriers, so the
 * author rules that style them survive retirement there. A header element
 * whose hook route content also uses (`py-2`) keeps its rules in the route's
 * author sheet rather than in shared chrome; that sheet retires on the
 * evidence route, and its retained part still styles the header.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

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
        if (!in_array(strtolower($match[2]), array('link', 'img'), true)) $stack[] = $id;
        return '<' . $match[2] . ' data-blocks-engine-node-id="' . $id . '"' . $match[3] . '>';
    }, $html);
    foreach ($nodes as &$node) $node['viewports'] = $template['nodes']['n7']['viewports'];
    unset($node);
    return array($html, array('schema' => $template['schema'], 'source_path' => $sourcePath, 'source_hash' => hash('sha256', $html), 'viewports' => $template['viewports'], 'nodes' => $nodes));
};
$document = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head><body>'
    . '<header class="masthead"><a class="brand py-2" href="/"><img src="/logo.png" alt="Logo"></a><nav class="primary"><a href="/">Home</a> <a href="/about.html">About</a> <a href="/team.html">Team</a></nav></header>'
    . '<main><h1>' . $title . '</h1><p class="lede py-2">Intro for ' . $title . '.</p><p>Body copy for ' . $title . '.</p></main></body></html>';
$css = '.brand img{height:68px;width:auto}.py-2{padding-top:.5rem;padding-bottom:.5rem}.primary a{color:#c00}.lede{font-size:20px}';

$files = array('site.css' => $css, 'logo.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
foreach (array('index.html' => 'Home', 'about.html' => 'About', 'team.html' => 'Team') as $path => $title) {
    $html = $document($title);
    if ('about.html' === $path) {
        [$html, $tree] = $observed($html, $path);
        $files['box-tree/about.json'] = json_encode($tree, JSON_THROW_ON_ERROR);
    }
    $files[$path] = $html;
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$pages = array_column($plan['pages'], null, 'source_path');
$failures = array();
if (empty($pages['about.html']['document_metadata']['engine_owned_styles'])) $failures[] = 'about.html is not an evidence route.';
$header = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'header' === ($part['area'] ?? null) && str_contains((string) $part['canonical_block_markup'], 'brand')));
if (array() === $header) $failures[] = 'the masthead is not a shared header part.';
if (str_contains((string) $pages['about.html']['canonical_block_markup'], 'masthead')) $failures[] = 'about.html still renders the masthead inline.';
// What a route delivers on the frontend: sheets scoped to it or global.
$delivered = static function (string $route) use ($plan): string {
    $css = '';
    foreach ($plan['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? null) || 'editor' === ($asset['stylesheet_target'] ?? 'both')) continue;
        foreach ($asset['scopes'] ?? array() as $scope) {
            if ('global' === $scope['kind'] || $route === ($scope['source_path'] ?? null)) { $css .= (string) ($asset['content'] ?? ''); break; }
        }
    }
    return $css;
};
foreach (array('index.html', 'about.html') as $route) {
    $css = $delivered($route);
    if (1 !== preg_match('/\.brand img\s*\{[^}]*height:\s*68px/', $css)) $failures[] = "$route loses the header's .brand img rule.";
    if (1 !== preg_match('/\.primary a\s*[,{][^}]*color:[^;}]*#c00/', $css)) $failures[] = "$route loses the header's .primary a rule.";
}
if (str_contains($delivered('about.html'), 'font-size:20px')) $failures[] = 'about.html keeps a resting rule only its own content uses.';
if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "PASS: evidence routes keep the author rules that style shared template parts.\n";
