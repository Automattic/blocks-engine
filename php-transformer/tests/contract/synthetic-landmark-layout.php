<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$css = 'body{margin:0}.page{display:flex;flex-direction:column;align-items:center}.masthead{width:100%;background:#eee}.bar{display:flex;justify-content:space-between}.brand{width:623px}';
$document = static fn(string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body><div class="page"><div class="masthead"><div class="bar"><a class="brand" href="/">Example</a><nav><a href="/about/">About</a></nav></div></div><main><h1>' . $title . '</h1></main></div></body></html>';
$fixture = array('entrypoint' => 'index.html', 'files' => array('style.css' => $css, 'index.html' => $document('Home'), 'about/index.html' => $document('About')));
$compiled = (new ArtifactCompiler())->compile($fixture)->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'];
$parts = array_column($plan['template_parts'], null, 'slug');
$headers = array_values(array_filter($parts, static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers), 'Two pages share an extracted header.');
$part = $headers[0];
$assert(str_contains($part['canonical_block_markup'], '<header') && str_contains($part['canonical_block_markup'], 'masthead'), 'A synthetic header surrounds the source container.');
$writes = array_column($plan['writes'], null, 'target_path');
$bootstrap = $writes['functions.php']['payload']['data'] ?? '';
preg_match("/'(:where\\(body[^']+blocks-engine-shell-transport[^']+)'/", $bootstrap, $match);
$transport = $match[1] ?? '';
$assert(str_contains($part['canonical_block_markup'], '"className":"blocks-engine-shell-landmark"') && str_contains($transport, ':where(.blocks-engine-shell-landmark){display:contents}'), 'Synthetic landmarks are boxless through frontend-only transport CSS.');
$assert(str_contains($transport, 'body:not(.editor-styles-wrapper):not(.wp-admin)'), 'Editor selection boxes remain intact.');

// Same lightweight block renderer and optional Chrome CLI geometry probe as viewport-shell.php.
$runtime = new Runtime();
$render = static function(string $markup, string $content = '') use (&$render, $runtime, $parts): string {
    $html = '';
    foreach ($runtime->parseBlocks($markup) as $block) {
        if ('core/template-part' === $block['blockName']) {
            $html .= '<div class="blocks-engine-shell-transport">' . $render($parts[$block['attrs']['slug']]['canonical_block_markup']) . '</div>';
        } elseif ('core/post-content' === $block['blockName']) {
            $html .= '<div class="wp-block-post-content">' . $render($content) . '</div>';
        } else {
            $child = 0;
            foreach ($block['innerContent'] ?? array() as $fragment) $html .= null === $fragment ? $render($runtime->serializeBlocks(array($block['innerBlocks'][$child++]))) : $fragment;
        }
    }
    return $html;
};
$templates = WordPressSitePlan::renderedTemplates($plan);
$page = $plan['pages'][0];
$template = $templates[!empty($page['entrypoint']) ? 'front-page' : 'page'];
$assert(str_contains($template . $page['canonical_block_markup'], '"slug":"' . $part['slug'] . '"'), 'The measured page renders through the extracted part.');
$styles = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content'));
if ($browser = getenv('BLOCKS_ENGINE_TEST_CHROMIUM')) {
    $temporary = tempnam('/tmp', 'synthetic-landmark-');
    $file = $temporary . '.html';
    unlink($temporary);
    $profile = '/tmp/synthetic-landmark-browser-' . bin2hex(random_bytes(8));
    $probe = '<script>document.body.dataset.width=JSON.stringify([innerWidth,document.querySelector(".masthead").getBoundingClientRect().width]);</script>';
    try {
        foreach (array('source' => '<style>' . $css . '</style>' . $fixture['files'][$page['source_path']], 'compiled' => '<style>' . $styles . $transport . '</style><div class="wp-site-blocks">' . $render($template, $page['canonical_block_markup']) . '</div>') as $label => $html) {
            file_put_contents($file, '<!doctype html>' . $html . $probe);
            $output = array();
            exec(escapeshellarg($browser) . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($profile) . ' --window-size=1440,900 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null', $output, $status);
            $assert(0 === $status && preg_match('/data-width="([^"]+)"/', implode("\n", $output), $measurement), 'Chrome reports ' . $label . ' geometry.');
            $widths = json_decode(html_entity_decode($measurement[1], ENT_QUOTES), true);
            $assert(array(1440, 1440) === $widths, $label . ' header fills the viewport: ' . json_encode($widths));
        }
    } finally { unlink($file); }
}
echo "synthetic-landmark-layout: ok\n";
