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
$assert(str_contains($css, ':where(' . $root . '>.wp-block-post-content,' . $root . '>.wp-block-post-content .page-body,' . $root . '>.wp-block-post-content :has(.page-body)){flex:1;display:flex;flex-direction:column}'), 'Document carriers stretch with zero specificity so authored display rules win.');
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
// Optional browser checks also run against the source; PHP-only hosts retain
// the deterministic CSS/tree assertions above. Assert the content viewport too:
// headless Chrome's CLI window includes 143px of browser chrome.
$probe = <<<'JS'
<script>
const visible = selector => [...document.querySelectorAll(selector)].filter(el => el.getClientRects().length && getComputedStyle(el).display !== 'none');
const footers = visible('.site-footer');
document.body.dataset.geometry = JSON.stringify({
    width: innerWidth, height: innerHeight,
    documentHeight: document.documentElement.scrollHeight,
    desktop: visible('.data-blocks-engine-capture-desktop-document:has(main)').length,
    mobile: visible('.data-blocks-engine-capture-mobile-document:has(main)').length,
    mains: visible('main').length,
    footerBottom: footers.length === 1 ? footers[0].getBoundingClientRect().bottom : null
});
</script>
JS;
$measure = static function (string $html, int $width) use ($assert): array {
    $file = tempnam(sys_get_temp_dir(), 'viewport-shell-');
    unlink($file);
    $file .= '.html';
    file_put_contents($file, $html);
    $profile = sys_get_temp_dir() . '/viewport-shell-browser-' . bin2hex(random_bytes(8));
    try {
        exec(escapeshellarg(getenv('BLOCKS_ENGINE_TEST_CHROMIUM')) . ' --headless --no-sandbox --disable-gpu --user-data-dir=' . escapeshellarg($profile) . ' --window-size=' . $width . ',743 --force-device-scale-factor=1 --dump-dom ' . escapeshellarg('file://' . $file) . ' 2>/dev/null', $output, $status);
        $assert(0 === $status && preg_match('/data-geometry="([^"]+)"/', implode("\n", $output), $match), 'Browser reports geometry.');
        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        unlink($file);
    }
};
foreach ($plan['pages'] as $page) {
    $template = $templates[!empty($page['entrypoint']) ? 'front-page' : 'page'];
    $assert(str_contains($template, '"slug":"header"') && str_contains($template, '"slug":"footer"'), 'Template surrounds post content with shared parts.');
    $html = '<!doctype html><html><head><style>' . $css . "\n" . '.blocks-engine-shell-transport{display:contents}body{margin:0!important}</style></head><body><div class="wp-site-blocks">' . $render($template, $page['canonical_block_markup']) . '</div>' . $probe . '</body></html>';
    // Optional local browser assertion; deterministic CSS/tree assertions above
    // also run on PHP-only suite hosts.
    $browser = getenv('BLOCKS_ENGINE_TEST_CHROMIUM');
    if ($browser) {
        $source = str_replace('</body>', $probe . '</body>', $fixture['files'][$page['source_path']]);
        foreach (array(1000, 500) as $width) {
            $actual = $measure($html, $width);
            $expected = $measure($source, $width);
            $label = $page['source_path'] . ' at ' . $width . 'px: ' . json_encode($actual);
            $assert($width === $actual['width'] && 600 === $actual['height'], 'Browser uses requested viewport: ' . $label);
            $assert((1000 === $width ? 1 : 0) === $actual['desktop'] && (500 === $width ? 1 : 0) === $actual['mobile'], 'Only the selected document variant is visible: ' . $label);
            $assert(1 === $actual['mains'], 'Main content renders once: ' . $label);
            $assert($actual === $expected, 'Rendered geometry matches the source: ' . $label . ' expected ' . json_encode($expected));
            $tall = 'visit/index.html' === $page['source_path'];
            $assert($tall ? $actual['documentHeight'] > 600 : 600 === $actual['documentHeight'], 'Viewport minimum does not constrain tall pages: ' . $label);
            $assert($tall ? $actual['footerBottom'] > 600 : 600 === $actual['footerBottom'], 'Footer remains at the page bottom: ' . $label);
        }
    }
}
echo "viewport-shell: ok\n";
