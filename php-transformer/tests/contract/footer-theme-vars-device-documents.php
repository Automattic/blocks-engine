<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

/**
 * Per-page theme variables reach a shared footer hoisted out of the frame.
 *
 * A site builder declares its theme variables once per page, grouping the
 * page's root with the shared footer's container under the page frame
 * (`#frame :where(.page-root),#frame :where(.footer-vars){--theme-…}`). A
 * device-document capture names both documents' frames
 * (`:is(#frame,#frame--data-blocks-engine-capture-mobile)`). The footer
 * becomes one template part outside the frame, so the variables must follow
 * it into shared chrome, re-anchored to the part and still gated to their
 * route; otherwise every `var(--theme-…)` in the footer resolves empty and
 * the footer loses its background, font and colour.
 */

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

$suffixes = array('desktop' => '', 'mobile' => '--data-blocks-engine-capture-mobile');
$frame = static function (string $suffix, string $root, string $title): string {
    return '<div id="container' . $suffix . '" class="container"><div id="frame' . $suffix . '" class="frame">'
        . '<header id="site-header' . $suffix . '" class="site-header"><a href="/">Example</a><nav><a href="/">Home</a><a href="/about/">About</a><a href="/events/">Events</a></nav></header>'
        . '<main id="pages' . $suffix . '" class="' . $root . '"><h1>' . $title . '</h1><p>' . $title . ' body copy.</p></main>'
        . '<footer id="site-footer' . $suffix . '" class="site-footer"><div id="footer-vars' . $suffix . '" class="footer-vars"><p id="footer-copy' . $suffix . '" class="footer-copy">© 2026 Example. All rights reserved.</p></div></footer>'
        . '</div></div>';
};
$routes = array('/' => array('index.html', 'Home', 'root-home', false), '/about/' => array('about/index.html', 'About', 'root-about', false), '/events/' => array('events/index.html', 'Events', 'root-events', true), '/shop/' => array('shop/index.html', 'Shop', 'root-shop', true));
$files = array('site.css' => '.data-blocks-engine-capture-mobile-document{display:none}@media(max-width:767px){.data-blocks-engine-capture-desktop-document{display:none}.data-blocks-engine-capture-mobile-document{display:block}}');
foreach ($routes as [$path, $title, $root, $dual]) {
    // Like a site builder's per-page theme rule: each route groups its own
    // page root with the shared footer's container under the page frame. A
    // device-document capture names both documents' frames.
    $frameSelector = $dual ? ':is(#frame,#frame--data-blocks-engine-capture-mobile)' : '#frame';
    $css = $frameSelector . ' :where(.' . $root . '),' . $frameSelector . ' :where(.footer-vars){--theme-bg:#fef9e0;--theme-paragraph-font:400 14px/1.4 serif;--theme-paragraph-color:#333}'
        . $frameSelector . ' :where(.footer-vars){background:var(--theme-bg);padding:40px}'
        . $frameSelector . ' :where(.footer-copy){font:var(--theme-paragraph-font);color:var(--theme-paragraph-color)}';
    $body = '';
    if ($dual) {
        foreach ($suffixes as $device => $suffix) $body .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-document-scope="">' . $frame($suffix, $root, $title) . '</div>';
    } else {
        $body = $frame('', $root, $title);
    }
    $files[$path] = '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title><link rel="stylesheet" href="/site.css"><style>' . $css . '</style></head><body>' . $body . '</body></html>';
}

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);

$footerPart = null;
foreach ($plan['template_parts'] as $part) if (str_contains($part['canonical_block_markup'], 'footer-vars')) $footerPart = $part;
$assert(is_array($footerPart) && 'shared_shell' === ($footerPart['placement']['kind'] ?? null), 'the footer is one shared template part');
$assert(!str_contains($footerPart['canonical_block_markup'], 'id="frame'), 'the footer part is hoisted out of the source frame');

$variableRules = array();
$routeContentInChrome = false;
foreach ($plan['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '') || 'editor' === ($asset['stylesheet_target'] ?? '') || !str_contains($asset['target_path'], 'shared-chrome-')) continue;
    if (preg_match('/root-(?:home|about|events|shop)/', $asset['content'])) $routeContentInChrome = true;
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use (&$variableRules): void {
        if (!str_contains($body, '--theme-bg') || !str_contains($body, '--theme-paragraph-font')) return;
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            // Re-anchored to the hoisted part, gated to one route.
            // Routes declaring the same values may share one rule, one arm each.
            if (str_contains($selector, ':has(> #site-footer)') && str_ends_with(trim($selector), ':where(.footer-vars)') && preg_match('/^:where\(:root:(?:is|not)\(\.be-r-[a-f0-9]+\)\) /', $selector)) {
                $variableRules[trim($selector)] = true;
            }
        }
    });
}
$assert(4 === count($variableRules), 'each route\'s theme variables reach the shared footer part, plain and device-document routes alike: ' . json_encode(array_keys($variableRules)));
$assert(!$routeContentInChrome, 'route page roots stay in route stylesheets');

echo "footer theme variables follow the shared footer part across device documents\n";
