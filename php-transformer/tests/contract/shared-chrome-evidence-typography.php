<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Only the secondary route was captured. The shared footer is compiled from
// the uncaptured entry route and therefore still needs its author typography.
$template = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true);
$files = array('site.css' => ':root{--tracking:3px}.footer-copy{font-size:18px;line-height:28px;letter-spacing:var(--tracking);padding:37px}@media(min-width:768px){.footer-copy{font-size:20px}}.page-copy{font-size:43px}');
foreach (array('index.html' => 'Home', 'about.html' => 'About') as $path => $title) {
    $html = '<html><head><title>' . $title . '</title><link rel="stylesheet" href="/site.css"></head><body><main><h1>' . $title . '</h1><p class="page-copy">Page copy</p></main><footer><p class="footer-copy">Shared contact details</p></footer></body></html>';
    if ('about.html' === $path) {
        $nodes = $stack = array(); $count = 0;
        $html = preg_replace_callback('/<(\/?)([a-z][a-z0-9]*)([^>]*)>/i', static function (array $match) use (&$nodes, &$stack, &$count, $template): string {
            if ('/' === $match[1]) { array_pop($stack); return $match[0]; }
            $id = 'n' . ++$count;
            $nodes[$id] = array('children' => array(), 'viewports' => $template['nodes']['n7']['viewports']);
            if ($stack) $nodes[end($stack)]['children'][] = $id;
            if ('link' !== $match[2]) $stack[] = $id;
            return '<' . $match[2] . ' data-blocks-engine-node-id="' . $id . '"' . $match[3] . '>';
        }, $html);
        $files['box-tree/about.json'] = json_encode(array('schema' => $template['schema'], 'source_path' => $path, 'source_hash' => hash('sha256', $html), 'viewports' => $template['viewports'], 'nodes' => $nodes));
    }
    $files[$path] = $html;
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$css = '';
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? null) || 'editor' === ($asset['stylesheet_target'] ?? 'both')) continue;
    foreach ($asset['scopes'] ?? array() as $scope) {
        if ('global' === $scope['kind'] || 'about.html' === ($scope['source_path'] ?? '')) { $css .= $asset['content']; break; }
    }
}
if (!array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === $part['area'])) throw new RuntimeException('Expected a shared footer.');
if (!preg_match('/\.footer-copy[^{}]*\{[^}]*line-height:28px/', $css)) throw new RuntimeException('Shared footer typography lost on the captured route.');
if (!str_contains($css, '--tracking:3px')) throw new RuntimeException('Shared typography token definition lost.');
if (!preg_match('/@media[^{}]*min-width:768px[^{}]*\{[^{}]*\.footer-copy[^{}]*\{[^}]*font-size:/', $css)) throw new RuntimeException('Shared typography breakpoint lost.');
if (preg_match('/\.footer-copy[^{}]*\{[^}]*padding:37px/', $css)) throw new RuntimeException('Shared geometry must not be resurrected on the captured route.');
if (preg_match('/\.page-copy[^{}]*\{[^}]*font-size:/', $css)) throw new RuntimeException('Page-owned author typography must remain retired.');
echo "Shared chrome evidence typography passed.\n";
