<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$routes = array('index' => 'Home', 'about' => 'About', 'visit' => 'Visit');
$files = array();
foreach ($routes as $current => $title) {
    $links = '';
    foreach ($routes as $slug => $label) {
        $links .= '<li class="block-header-item"><span class="block-header-item__label"><div class="item-content-wrapper' . ($slug === $current ? ' item-content-wrapper--active' : '') . ' block-header-item__item" data-qa="navigation-item-' . $slug . '"><a class="item-content" href="' . $slug . '.html" data-v-x data-qa="navigationblock-page-' . ($slug === $current ? 'active-' : '') . $slug . '">' . $label . '</a></div></span></li>';
    }
    $files[$current . '.html'] = '<!doctype html><html><head><title>' . $title . '</title><style>'
        . '.primary{color:#123456}.item-content[data-v-x]{display:block;color:#123456}.item-content-wrapper--active .item-content[data-v-x],.item-content-wrapper:hover .item-content[data-v-x],.item-content-wrapper:focus .item-content[data-v-x]{color:#abcdef;text-underline-offset:7px;text-decoration:underline}'
        . '@media(max-width:600px){.item-content-wrapper--active .item-content[data-v-x],.item-content-wrapper:hover .item-content[data-v-x],.item-content-wrapper:focus .item-content[data-v-x]{color:#fedcba;text-decoration:underline}}'
        . '</style></head><body><header><a href="index.html">Harbor</a><nav class="primary"><ul>' . $links . '</ul></nav></header>'
        . '<main><h1>' . $title . '</h1><p>Page ' . $title . '</p></main></body></html>';
}
// Compile each entry route too: shell reconciliation must not hide a
// captured current link by selecting the first route's menu as canonical.
$menuShapes = array();
foreach (array_keys($files) as $entrypoint) {
    $routePlan = (new ArtifactCompiler())->compile(array('entrypoint' => $entrypoint, 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $menuShapes[] = $routePlan['menus'][0]['block_markup'];
}
$assert(1 === count(array_unique($menuShapes)), 'Active scoped attribute and anchor markers must not change menu identity across entry routes.');
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$assert(1 === count($plan['menus']), 'Route-only active classes must not fork wp_navigation posts.');
$headers = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(1 === count($headers), 'Route-only active classes must not fork header parts.');
$assert(str_contains($headers[0]['canonical_block_markup'], '{{wordpress-site-plan:navigation:' . $plan['menus'][0]['token'] . '}}'), 'The shared header references the shared menu.');
$assert(3 === count($plan['pages']), 'All source routes are retained.');
foreach ($plan['pages'] as $page) {
    $markup = $page['canonical_block_markup'];
    $templateSlug = $page['entrypoint'] ? 'front-page' : 'page';
    $template = array_column($plan['templates'], 'canonical_block_markup', 'slug')[$templateSlug] ?? '';
    $assert(str_contains($template . $markup, 'wp:template-part {"slug":"' . $headers[0]['slug'] . '"'), 'Every page references the shared header through its native template.');
    $assert(!str_contains($markup, '<header') && !str_contains($markup, 'wp:navigation '), 'No page embeds an inline header copy.');
}
$css = '';
array_walk_recursive($plan, static function ($value) use (&$css): void {
    if (is_string($value) && (str_contains($value, '#abcdef') || str_contains($value, '#fedcba'))) $css .= $value . "\n";
});
foreach (array('#abcdef', '#fedcba') as $color) {
    preg_match_all('/([^{}]+)\{[^{}]*color:[^;{}]*' . $color . '[^{}]*\}/i', $css, $rules, PREG_SET_ORDER);
    $runtime = array_filter($rules, static fn(array $rule): bool => str_contains($rule[1], 'wp-block-navigation') && (str_contains($rule[1], '[aria-current') || str_contains($rule[1], 'current-menu-item')));
    $assert(array() !== $runtime, 'Desktop and mobile active colour must follow the runtime current item: ' . $color);
}
$assert(!preg_match('/(?:aria-current|current-menu-item)[^{}]*blocks-engine-navigation-anchor-/', $css), 'Runtime current paint is not constrained to the captured anchor.');
$assert(str_contains($css, '.item-content-wrapper:hover') && str_contains($css, '.item-content-wrapper:focus'), 'Sibling hover and focus selectors retain their existing projection.');
$assert((bool) preg_match('/[^{}]*(?:aria-current|current-menu-item)[^{}]*\{[^{}]*text-decoration:\s*underline/', $css), 'Active underline follows the runtime current item.');
$assert(!str_contains($plan['menus'][0]['block_markup'], 'item-content-wrapper--active'), 'The menu does not freeze a source active class.');
// Render Core's link and the projected source boxes from the emitted menu
// attributes. No source active class is replayed: Core owns aria-current.
$html = '<!doctype html><style>.wp-block-navigation-item__content{color:inherit;text-decoration:none}';
foreach ($plan['assets'] as $asset) if ('css' === ($asset['kind'] ?? '')) $html .= $asset['content'];
$html .= '</style><nav class="wp-block-navigation"><ul>';
preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $plan['menus'][0]['block_markup'], $items);
foreach ($items[1] as $index => $json) {
    $attrs = json_decode($json, true);
    $anchor = $attrs['metadata']['blocksEngineNavigationAnchor'] ?? array();
    $boxes = $anchor['boxes'] ?? array();
    $assert(array('span', 'div') === array_column($boxes, 'tag'), 'Fixture projects li > span > div > a, as on the real capture.');
    $html .= '<li class="wp-block-navigation-item ' . htmlspecialchars($attrs['className'] ?? '') . '">';
    foreach ($boxes as $box) $html .= '<' . $box['tag'] . ' class="' . htmlspecialchars($box['marker'] . ' ' . $box['className']) . '">';
    $html .= '<a class="wp-block-navigation-item__content ' . htmlspecialchars($anchor['className'] ?? '') . '"' . (0 === $index ? ' aria-current="page"' : '') . ' href="' . $attrs['url'] . '">' . $attrs['label'] . '</a>';
    foreach (array_reverse($boxes) as $box) $html .= '</' . $box['tag'] . '>';
    $html .= '</li>';
}
$html .= '</ul></nav>';
$chrome = getenv('CHROME_PATH') ?: (is_executable('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome') ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : trim((string) shell_exec('command -v chromium || command -v google-chrome || true')));
if ('' !== $chrome) {
    $temp = tempnam(sys_get_temp_dir(), 'current-nav-');
    $file = $temp . '.html';
    unlink($temp);
    file_put_contents($file, $html . '<script>const links=[...document.querySelectorAll("a")];document.body.dataset.result=JSON.stringify(links.map((_,current)=>{links.forEach((a,i)=>i===current?a.setAttribute("aria-current","page"):a.removeAttribute("aria-current"));return links.map(a=>{const s=getComputedStyle(a);return [s.color,s.textDecorationLine]})}));</script>');
    try {
        foreach (array(1440 => 'rgb(171, 205, 239)', 390 => 'rgb(254, 220, 186)') as $width => $activeColor) {
            $output = shell_exec(escapeshellarg($chrome) . ' --headless --no-sandbox --disable-gpu --dump-dom --window-size=' . $width . ',900 ' . escapeshellarg('file://' . $file) . ' 2>/dev/null');
            preg_match('/data-result="([^"]+)"/', (string) $output, $result);
            $styles = json_decode(html_entity_decode($result[1] ?? '', ENT_QUOTES), true);
            $assert(3 === count($styles ?? array()), 'Browser measured each served route.');
            foreach ($styles as $current => $links) foreach ($links as $index => $style) {
                $expected = $current === $index ? array($activeColor, 'underline') : array('rgb(18, 52, 86)', 'none');
                $assert($expected === $style, 'Only the current nested link has computed colour and underline at ' . $width . ': ' . json_encode($styles));
            }
        }
    } finally { unlink($file); }
}
// Also keep a DOM selector-match check for browserless runners.
{
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    $matched = false;
    foreach ($rules as $rule) {
        if (!preg_match('/color:[^;]*#abcdef/', $rule[2]) || !preg_match('/text-decoration:\s*underline/', $rule[2])) continue;
        $selectorList = $rule[1];
        foreach (Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer::splitSelectorList($selectorList) ?? array() as $selector) {
            if (!str_contains($selector, '[aria-current')) continue;
            $parsed = Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::parse(trim($selector));
            $assert($parsed['supported'], 'The active selector can be DOM-tested: ' . $selector);
            foreach ($xpath->query('//a') as $link) {
                $matches = Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::matches($link, $parsed)['matches'] ?? false;
                $assert($matches === $link->hasAttribute('aria-current'), 'Runtime selector matches only the active nested anchor.');
            }
            $matched = true;
        }
    }
    $assert($matched, 'An active colour/underline selector was DOM-tested.');
}
// A document's locale need not be its chrome's locale: translated blog
// posts in the capture keep the original header. They must adopt that part.
$localizedFiles = array();
foreach (array('index' => array('es', 'es'), 'nosotros' => array('es', 'es'), 'en/index' => array('en', 'en'), 'en/about' => array('en', 'en'), 'pt/index' => array('pt', 'pt'), 'pt/about' => array('pt', 'pt'), 'en/story' => array('en', 'es'), 'pt/story' => array('pt', 'es')) as $path => [$language, $headerLanguage]) {
    $label = array('es' => 'Inicio', 'en' => 'Home', 'pt' => 'Início')[$headerLanguage];
    $localizedFiles[$path . '.html'] = array('path' => $path . '.html', 'metadata' => array('post_type' => str_contains($path, 'story') ? 'post' : 'page'), 'content' => '<html lang="' . $language . '"><head><title>' . $path . '</title></head><body><main class="frame"><header><nav><ul><li><a href="/">' . $label . '</a></li><li><a href="/contact">Contact</a></li></ul></nav></header><article><h1>' . $path . '</h1><p>Unique content for ' . $path . '</p></article></main></body></html>');
}
$localized = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $localizedFiles))->toWordPressSitePlanView()['wordpress_site_plan'];
$localeHeaders = array_values(array_filter($localized['template_parts'], static fn(array $part): bool => 'header' === $part['area']));
$assert(3 === count($localeHeaders), 'Three distinct locale headers yield three shared parts.');
$assert(3 === count($localized['menus']), 'Locale labels remain distinct menu identities.');
$bySlug = array_column($localeHeaders, 'canonical_block_markup', 'slug');
foreach ($localized['pages'] as $page) {
    if (!empty($page['synthetic'])) continue;
    $markup = $page['canonical_block_markup'];
    $assert(!str_contains($markup, '<header') && !str_contains($markup, 'wp:navigation '), 'Locale pages and translated posts contain no inline header.');
    $templateSlug = $page['route_template'] ?? (!empty($page['entrypoint']) ? 'front-page' : ('post' === $page['post_type'] ? 'single' : 'page'));
    $template = array_column($localized['templates'], 'canonical_block_markup', 'slug')[$templateSlug] ?? '';
    preg_match('/wp:template-part \{"slug":"([^"]+)"/', $markup . $template, $reference);
    $assert(isset($bySlug[$reference[1] ?? '']), 'Every locale route references an existing header part: ' . $page['source_path'] . ' ' . $markup);
    if (str_contains($page['source_path'], 'story')) {
        $assert('post' === $page['post_type'], 'Stories exercise post adoption, not only pages.');
        $assert('header' === $reference[1], 'Translated posts adopt the original header, not the document locale.');
    }
}
echo "navigation-current-item-shared-menu: ok\n";
