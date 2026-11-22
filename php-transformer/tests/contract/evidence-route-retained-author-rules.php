<?php
/**
 * An evidence route (box-tree bound, ADR 0005) retires author stylesheets but
 * keeps what its carriers cannot replace: @font-face, interaction states and
 * the capture's device-island gate. That holds whether the sheet is linked by
 * evidence routes only or is shared with a route that has no box tree.
 */
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

$template = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 64, JSON_THROW_ON_ERROR);
// Stamp every element with a node id and observe it with the fixture's leaf box.
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
$desktop = CaptureEvidence::DESKTOP_ONLY_CLASS;
$mobile = CaptureEvidence::MOBILE_ONLY_CLASS;
$document = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/site.css"><link rel="stylesheet" href="/islands.css"></head><body><main>'
    . '<div class="logo ' . $desktop . '"><p class="mark">Wide ' . $title . '</p></div><div class="logo ' . $mobile . '"><p class="mark">Narrow ' . $title . '</p></div>'
    . '<h1 class="display" style="font-family:\'Display Serif\',serif">' . $title . '</h1><p>Body copy for ' . $title . '.</p><p><a class="cta" href="/">Go</a></p></main></body></html>';
$island = '@media(min-width:1024px){.' . $mobile . '{display:none!important}}@media(max-width:1023px){.' . $desktop . '{display:none!important}}';
$shared = "@font-face{font-family:'Display Serif';font-style:normal;font-weight:400;src:url(\"fonts/display.woff2\") format(\"woff2\")}"
    // The display family is block-only: only the heading's own style names it.
    . "body{font-family:Arial,sans-serif}.display{font-size:40px}.cta:hover{color:#c00}";

$rows = array(
    // Every route has box-tree evidence: both sheets retire outright.
    'evidence-only site' => array('index.html', 'about.html'),
    // `about.html` has no box tree, so the shared sheet stays scoped to it.
    'mixed site' => array('index.html'),
);
$failures = array();
foreach ($rows as $shape => $evidenceRoutes) {
    $files = array('site.css' => $shared, 'islands.css' => $island, 'fonts/display.woff2' => 'wOF2-fixture');
    foreach (array('index.html' => 'Home', 'about.html' => 'About') as $path => $title) {
        $html = $document($title);
        if (in_array($path, $evidenceRoutes, true)) {
            [$html, $tree] = $observed($html, $path);
            $files['box-tree/' . substr($path, 0, -5) . '.json'] = json_encode($tree, JSON_THROW_ON_ERROR);
        }
        $files[$path] = $html;
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $pages = array_column($plan['pages'], null, 'source_path');
    if (empty($pages['index.html']['document_metadata']['engine_owned_styles'])) { $failures[] = "$shape: index.html is not an evidence route."; continue; }
    // What the home route delivers: frontend sheets scoped to it, plus theme.json faces.
    $delivered = '';
    foreach ($plan['assets'] as $asset) {
        if ('css' !== ($asset['kind'] ?? null) || 'editor' === ($asset['stylesheet_target'] ?? 'both')) continue;
        foreach ($asset['scopes'] ?? array() as $scope) {
            if ('global' === $scope['kind'] || 'index.html' === ($scope['source_path'] ?? null)) { $delivered .= (string) ($asset['content'] ?? ''); break; }
        }
    }
    $theme = json_decode((string) (array_column($plan['writes'], null, 'target_path')['theme.json']['payload']['data'] ?? '{}'), true);
    $faces = array();
    foreach ($theme['settings']['typography']['fontFamilies'] ?? array() as $family) foreach ($family['fontFace'] ?? array() as $face) $faces[] = strtolower((string) ($face['family'] ?? $face['fontFamily'] ?? ''));
    $hasFace = in_array('display serif', $faces, true) || 1 === preg_match("/@font-face\s*\{[^}]*Display Serif/i", $delivered);
    if (!$hasFace) $failures[] = "$shape: the evidence route loses the 'Display Serif' @font-face.";
    if (1 !== preg_match('/\.cta:hover\s*\{[^}]*color/i', $delivered)) $failures[] = "$shape: the evidence route loses the .cta:hover state rule.";
    foreach (array($mobile, $desktop) as $class) {
        if (1 !== preg_match('/\.' . preg_quote($class, '/') . '\s*\{\s*display\s*:\s*none\s*!important/', $delivered)) $failures[] = "$shape: the evidence route loses the .$class frontend gate.";
    }
}
if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "PASS: evidence routes keep retained author rules from evidence-only and shared sheets.\n";
