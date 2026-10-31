<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Reduced from the mebenedetto closed responsive navigation capture: the
// responsive container is promoted to a navigation carrying only core colour
// classes. Keep its links and inherited type; omit unrelated overlay controls.
$html = <<<'HTML'
<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body>
<nav class="wp-block-navigation has-primary-color has-secondary-background-color" style="font-size:0.75rem">
<a class="wp-block-navigation-item__content" href="/blog"><span class="wp-block-navigation-item__label">Blog</span></a>
<a class="wp-block-navigation-item__content" href="/foto"><span class="wp-block-navigation-item__label">Foto</span></a>
</nav>
</body></html>
HTML;
$css = <<<'CSS'
.has-primary-color{color:var(--wp--preset--color--primary)!important}
.has-secondary-background-color{background-color:var(--wp--preset--color--secondary)!important}
CSS;
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html, 'style.css' => $css),
))->toArray();

$out = '';
foreach ($result['source_reports']['compiled_site']['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')) $out .= $asset['content'];
}
if (!preg_match('/([^{}]+ \\.wp-block-navigation-item__content)\\{[^{}]*font-size:0.75rem[^{}]*\\}/', $out, $match)) {
    throw new RuntimeException('Missing recovered navigation presentation');
}
$selector = trim($match[1]);
if (!str_contains($selector, '.blocks-engine-')) {
    throw new RuntimeException('Recovered presentation is not importer-scoped');
}
// Every host class in the emitted selector must be carried by the imported
// core/navigation, not merely invented by the stylesheet generator.
$markup = (string) ($result['serialized_blocks'] ?? '');
if (!preg_match('/<!-- wp:navigation (\\{.*?\\}) -->/', $markup, $block)) {
    throw new RuntimeException('Missing imported navigation');
}
$attrs = json_decode($block[1], true, 512, JSON_THROW_ON_ERROR);
$classes = preg_split('/\\s+/', $attrs['className'] ?? '') ?: array();
$host = strstr($selector, ' ', true);
foreach (explode('.', ltrim($host, '.')) as $class) {
    if ('wp-block-navigation' !== $class && !in_array($class, $classes, true)) {
        throw new RuntimeException('Recovered selector does not match imported navigation: ' . $class);
    }
}
echo "Core navigation inherited presentation passed\n";
