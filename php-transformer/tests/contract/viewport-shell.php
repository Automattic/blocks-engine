<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$fixture = require dirname(__DIR__) . '/fixtures/viewport-shell.php';
$plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
$parts = array_column($plan['template_parts'], null, 'slug');
$assert(isset($parts['header'], $parts['footer']), 'Both shared landmarks are extracted.');
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content'));
$root = '.wp-site-blocks:has(>.wp-block-post-content .page-body)';
$assert(str_contains($css, $root . '{min-height:100vh;display:flex;flex-direction:column}'), 'The viewport minimum sizes the template containing all three regions.');
$assert(str_contains($css, '.page-body{min-height:0}'), 'The content copy does not add another viewport minimum.');
$assert(str_contains($css, ':has(.page-body){flex:1;display:flex;flex-direction:column}'), 'Document carriers stretch the main region.');
$assert(!str_contains($css, 'height:calc(100vh'), 'Long pages are not constrained by viewport subtraction.');
$runtime = new Runtime();
$render = static function (string $markup, string $content = '') use (&$render, $runtime, $parts): string {
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
$templates = array_column($plan['templates'], 'canonical_block_markup', 'slug');
foreach ($plan['pages'] as $page) {
    $template = $templates[!empty($page['entrypoint']) ? 'front-page' : 'page'];
    $assert(str_contains($template, '"slug":"header"') && str_contains($template, '"slug":"footer"'), 'Template surrounds post content with shared parts.');
    $html = '<!doctype html><html><head><style>' . $css . "\n" . '.blocks-engine-shell-transport{display:contents}body{margin:0!important}</style></head><body><div class="wp-site-blocks">' . $render($template, $page['canonical_block_markup']) . '</div><script>document.body.dataset.height=document.documentElement.scrollHeight;</script></body></html>';
    // Optional local browser assertion; deterministic CSS/tree assertions above
    // also run on PHP-only suite hosts.
    $browser = getenv('BLOCKS_ENGINE_TEST_CHROMIUM');
    if ($browser) {
        $file = tempnam(sys_get_temp_dir(), 'viewport-shell-');
        unlink($file);
        $file .= '.html';
        file_put_contents($file, $html);
        exec(escapeshellarg($browser) . ' --headless --no-sandbox --disable-gpu --user-data-dir=/tmp/blocks-engine-viewport-browser --window-size=1000,743 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null', $output, $status);
        unlink($file);
        $dom = implode("\n", $output); $output = array();
        $assert(0 === $status && preg_match('/data-height="(\d+)"/', $dom, $match), 'Browser reports document height.');
        $height = (int) $match[1];
        // The CLI window includes 143px of browser chrome: its content viewport
        // is 1000x600. Compare with the source as well as the viewport minimum.
        $source = str_replace('</body>', '<script>document.body.dataset.height=document.documentElement.scrollHeight;</script></body>', $fixture['files'][$page['source_path']]);
        file_put_contents($file, $source);
        exec(escapeshellarg($browser) . ' --headless --no-sandbox --disable-gpu --user-data-dir=/tmp/blocks-engine-viewport-browser --window-size=1000,743 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null', $output, $status);
        unlink($file);
        $assert(0 === $status && preg_match('/data-height="(\d+)"/', implode("\n", $output), $sourceMatch), 'Source document reports height.');
        $output = array();
        $assert($height === (int) $sourceMatch[1], $page['source_path'] . ' matches source height.');
        $assert('visit/index.html' === $page['source_path'] ? $height > 600 : 600 === $height, $page['source_path'] . ' preserves viewport/overflow height: ' . $height);
    }
}
echo "viewport-shell: ok\n";
