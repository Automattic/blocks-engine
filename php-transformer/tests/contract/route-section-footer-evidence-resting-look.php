<?php
/**
 * A route below a menu section (/focus/deep/) rests the footer link naming
 * that section with the look the link has on other routes. An evidence route
 * renders the same link with box-tree carriers and inline typography, which
 * the shell identity ignores. That raw copy must not reach the section route:
 * every route still shares one footer part and one page template.
 */
declare(strict_types=1);

// Exercises box-tree decisions, which are opt-in (#7352).
putenv('BLOCKS_ENGINE_BOX_TREE_INTAKE=1');

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
    . '<header class="masthead"><a class="brand" href="/">Acme</a></header>'
    . '<main><h1>' . $title . '</h1><p>Body copy for ' . $title . '.</p></main>'
    . '<footer class="site-footer"><p class="addr">Acme AB, 1 Main St</p><ul class="footer-nav"><li><a href="/about/">About</a></li><li><a href="/focus/">Focus</a></li><li><a href="/contact/">Contact</a></li></ul></footer></body></html>';
$css = '.site-footer{background:#123;color:#fff;padding:2rem}.footer-nav{list-style:none;margin:0;padding:0}.footer-nav li{padding-bottom:1em}.footer-nav a{color:#fff;font-weight:300}';

$files = array('site.css' => $css);
foreach (array('index.html' => 'Home', 'focus/index.html' => 'Focus', 'focus/deep/index.html' => 'Deep', 'focus/wide/index.html' => 'Wide', 'contact.html' => 'Contact', 'about/index.html' => 'About') as $path => $title) {
    $html = $document($title);
    if ('contact.html' === $path) {
        [$html, $tree] = $observed($html, $path);
        $files['box-tree/contact.json'] = json_encode($tree, JSON_THROW_ON_ERROR);
    }
    $files[$path] = $html;
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$pages = array_column($plan['pages'], null, 'source_path');
$failures = array();
if (empty($pages['contact.html']['document_metadata']['engine_owned_styles'])) $failures[] = 'contact is not an evidence route.';
$footers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'footer' === ($part['area'] ?? null)));
if (1 !== count($footers)) $failures[] = 'expected one footer part, got ' . implode(', ', array_column($footers, 'slug')) . '.';
$custom = array_values(array_filter(array_column($plan['templates'], 'slug'), static fn (string $slug): bool => str_starts_with($slug, 'page-')));
if (array() !== $custom) $failures[] = 'section routes split into custom templates: ' . implode(', ', $custom) . '.';
if (array() !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "PASS: section routes rest footer links with the shared look, not an evidence route's raw copy.\n";
