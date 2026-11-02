<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$compile = static function (string $position): array {
    $page = static function (string $title): string {
        $documents = '';
        foreach (array('desktop', 'mobile') as $device) {
            $documents .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope=""><div class="bg"></div><div class="body"><main><h1>' . $title . '</h1><p>Content</p></main><footer class="site-footer"><p>&copy; Studio</p>' . ('desktop' === $device ? '<p>Open daily</p>' : '') . '</footer></div></div>';
        }
        return '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body>' . $documents . '</body></html>';
    };
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $page('Home'),
        'about/index.html' => $page('About'),
        'visit/index.html' => $page('Visit'),
        'style.css' => 'body{position:relative;margin:0}.bg{position:absolute;inset:0;background:#fff}.body{position:' . $position . '}.site-footer{background:#f3f3f3;padding:40px}@media(max-width:600px){.data-blocks-engine-capture-desktop-document{display:none}}@media(min-width:601px){.data-blocks-engine-capture-mobile-document{display:none}}',
    )))->toArray()['source_reports']['wordpress_site_plan'];
};

foreach (array('relative', 'static') as $position) {
    $plan = $compile($position);
    $footer = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'footer' === ($part['area'] ?? null)))[0] ?? array();
    if ('shared_shell' !== ($footer['placement']['kind'] ?? null) || !empty($footer['ancestor_context']['backdrop_ids'])) throw new RuntimeException('Reduction must detach a class-only footer via responsive partition.');
    $css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => str_contains($asset['path'] ?? '', 'shared-chrome-context')), 'content'));
    // Both variant roots, not their text descendants or template-part wrapper,
    // must enter the positioned paint layer after the absolute .bg sibling.
    preg_match_all('/<footer\b[^>]*class="([^"]+)"/', $footer['canonical_block_markup'], $matches);
    if (2 !== count($matches[1])) throw new RuntimeException('Expected two responsive footer roots.');
    foreach ($matches[1] as $classes) {
        $targeted = false;
        foreach (explode(' ', $classes) as $class) {
            if (preg_match('/:where\([^)]*\.' . preg_quote($class, '/') . '(?=[,)])[^{]*\{position:relative\}/', $css)) $targeted = true;
        }
        if (('relative' === $position) !== $targeted) throw new RuntimeException('Detached footer root paint order must follow source ancestor positioning: ' . $css);
    }
    if (str_contains($css, '{position:static}')) throw new RuntimeException('Class-only fix must not move backdrop containment.');
}
echo "Class-only detached footer paint order passed.\n";
