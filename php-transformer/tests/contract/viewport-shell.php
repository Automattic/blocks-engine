<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\SharedChromeStylesheets;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeTemplates;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
foreach (array('combined', 'split', 'trailing-chrome') as $variant) {
$fixture = require dirname(__DIR__) . '/fixtures/viewport-shell.php';
if ('trailing-chrome' === $variant) {
    foreach ($fixture['files'] as &$html) {
        $html = str_replace('</footer>', '</footer><div class="bottom-spacer"></div>', $html);
        // Give the empty spacer a box so compilation retains it, without
        // changing the viewport-shell geometry or footer's bottom edge.
        $html = str_replace('</style>', 'body{position:relative}.backdrop{position:absolute;inset:0;background:#fff}.page-body{position:relative}.site-footer{background:#f3f3f3}.bottom-spacer{height:1px;position:absolute}</style>', $html);
    }
    unset($html);
}
if ('split' === $variant) {
    foreach ($fixture['files'] as &$html) $html = str_replace('.page-body{min-height:100vh;display:flex;flex-direction:column}', '.page-body{display:flex;flex-direction:column}.page-body{min-height:100vh}', $html);
    unset($html);
}
$plan = (new ArtifactCompiler())->compile($fixture)->toArray()['source_reports']['wordpress_site_plan'];
$parts = array_column($plan['template_parts'], null, 'slug');
$assert(isset($parts['header'], $parts['footer']), 'Both shared landmarks are extracted.');
$css = implode("\n", array_column(array_filter($plan['assets'], static fn(array $asset): bool => 'css' === $asset['kind']), 'content'));
if ('trailing-chrome' === $variant) {
    $assert(str_contains($parts['footer']['canonical_block_markup'], 'bottom-spacer'), 'Trailing chrome belongs to the multi-root footer part.');
    $assert((bool) preg_match('/:where\([^)]*\.site-footer(?:[,.\s)]|$)[^{}]*\)\{position:relative\}/', $css), 'The footer root retains its zero-specificity paint-order transport with trailing chrome.');
    $assert((bool) preg_match('/:where\([^)]*\.bottom-spacer(?:[,.\s)]|$)[^{}]*\)\{position:relative\}/', $css), 'Trailing roots also receive the paint-order transport.');
}
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
$templates = ThemeTemplates::rendered($plan);
// Optional browser checks also run against the source; PHP-only hosts retain
// the deterministic CSS/tree assertions above. Assert the content viewport too:
// headless Chrome's CLI window includes 143px of browser chrome.
$probe = <<<'JS'
<script>
const visible = selector => [...document.querySelectorAll(selector)].filter(el => el.getClientRects().length && getComputedStyle(el).display !== 'none');
const footers = visible('.site-footer');
// Probe a blank point inside the footer after scrolling it into view. A
// covering absolute backdrop wins hit testing even though computed footer
// background colors alone would still look correct.
if (footers.length === 1) footers[0].scrollIntoView(false);
const footerRect = footers.length === 1 ? footers[0].getBoundingClientRect() : null;
const footerPaints = footerRect ? footers[0].contains(document.elementFromPoint(footerRect.left + 10, footerRect.bottom - 10)) : false;
window.scrollTo(0, 0);
document.body.dataset.geometry = JSON.stringify({
    width: innerWidth, height: innerHeight,
    documentHeight: document.documentElement.scrollHeight,
    desktop: visible('.data-blocks-engine-capture-desktop-document:has(main)').length,
    mobile: visible('.data-blocks-engine-capture-mobile-document:has(main)').length,
    mains: visible('main').length,
    footerPaints,
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
            $assert($actual['footerPaints'], 'Footer paints above the backdrop: ' . $label);
            $assert($actual === $expected, 'Rendered geometry matches the source: ' . $label . ' expected ' . json_encode($expected));
            $tall = 'visit/index.html' === $page['source_path'];
            $assert($tall ? $actual['documentHeight'] > 600 : 600 === $actual['documentHeight'], 'Viewport minimum does not constrain tall pages: ' . $label);
            $assert($tall ? $actual['footerBottom'] > 600 : 600 === $actual['footerBottom'], 'Footer remains at the page bottom: ' . $label);
        }
    }
}
}
// Isolate cascade and media eligibility independently of extraction.
$shellRules = new ReflectionMethod(SharedChromeStylesheets::class, 'detachedViewportShellRules');
$resolve = static function (array $styles) use ($shellRules): string {
    return $shellRules->invoke(null,
        array(array('placement' => array('kind' => 'shared_shell'), 'ancestor_context' => array('classes' => array('page-body')))),
        array_map(static fn(string $css): array => array('kind' => 'css', 'content' => $css), $styles),
        array(array('canonical_block_markup' => '<div class="page-body"><main>Content</main></div>'))
    );
};
$assert(str_contains($resolve(array('.page-body{display:flex}', '.page-body{flex-direction:column}', '.page-body{min-height:100vh}')), $root), 'Layout declarations can come from different CSS assets.');
$assert('' === $resolve(array('.page-body{display:flex;flex-direction:column}.page-body{display:block;min-height:100vh}')), 'Later display overrides prevent a shell.');
$assert(str_contains($resolve(array('.page-body{display:flex!important;flex-direction:column}.page-body{display:block;min-height:100vh}')), $root), 'Important display wins over later normal declarations.');
$assert('' === $resolve(array('div.page-body{display:block}.page-body{display:flex;flex-direction:column;min-height:100vh}')), 'More specific display overrides prevent a shell.');
$media = '@media (min-width:700px)';
$assert(str_contains($resolve(array('.page-body{display:flex;flex-direction:column}', $media . '{.page-body{min-height:100vh}}')), $media . '{' . $root), 'Unconditional layout contributes inside the minimum media context.');
$assert(str_contains($resolve(array($media . '{.page-body{display:flex;flex-direction:column}}', $media . '{.page-body{min-height:100vh}}')), $media . '{' . $root), 'Same-media declarations contribute across assets.');
$assert(!str_contains($resolve(array($media . '{.page-body{display:flex;flex-direction:column}}', '.page-body{min-height:100vh}')), $root), 'Conditional layout does not qualify an unconditional minimum.');
$assert(!str_contains($resolve(array('@media (max-width:699px){.page-body{display:flex;flex-direction:column}}', $media . '{.page-body{min-height:100vh}}')), $root), 'Unrelated media layout does not qualify the minimum.');
echo "viewport-shell: ok\n";
