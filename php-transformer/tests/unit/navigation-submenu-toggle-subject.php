<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
// The toggle paints its own caret, as a Bootstrap `.dropdown-toggle` does.
$css = '.nav{display:flex;list-style:none;padding-left:0} .nav-link{display:block;padding:8px 16px} .dropdown-toggle::after{content:"";display:inline-block;border-top:4px solid} .dropdown-menu{display:none;position:absolute} #menu > ul > li{display:inline-block;padding:0 10px;height:71px} #menu > ul > li a, #menu > ul > li button{display:block;height:71px;line-height:71px;padding:0}';
$attributes = static function (string $markup, string $block): array {
    preg_match('/<!-- wp:' . $block . ' (\{.*?\}) (?:\/)?-->/s', $markup, $match);
    return json_decode($match[1] ?? '', true) ?: array();
};

// The Bootstrap-only input is not recognized as a submenu. Supply the existing
// capture dropdown contract, without changing its list/item/button subjects.
// `#menu` is the navigation landmark itself, or a wrapper whose own list is
// the navigation (the list renders as core's NAV root plus its inner UL).
foreach (array('nav' => '', 'list' => '') as $host => $_) {
    foreach (array('button' => '', 'inline button' => ' style="color:red"') as $variant => $extra) {
        $row = "$host $variant";
        $items = '<li id="m1" class="nav-item dropdown"><button type="button" data-bs-toggle="dropdown" id="club-toggle" class="nav-link dropdown-toggle"' . $extra . ' aria-haspopup="menu" aria-controls="club-panel" aria-expanded="false" data-blocks-engine-capture-dialog-trigger="club-panel">Club</button><div id="club-panel" class="dropdown-menu data-blocks-engine-capture-dialog data-blocks-engine-capture-dropdown" role="dialog" aria-modal="true" hidden data-blocks-engine-capture-dialog-panel="club-panel"><a class="dropdown-item" href="/a">A</a><a class="dropdown-item" href="/b">B</a></div></li><li class="nav-item"><a class="nav-link" href="/c">Contact</a></li>';
        $html = 'nav' === $host
            ? '<header><nav id="menu"><ul class="nav">' . $items . '</ul></nav></header>'
            : '<header><div id="menu"><ul class="nav">' . $items . '</ul><form><input type="search" name="s"></form></div></header>';
        $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<style>' . $css . '</style>' . $html)))->toArray();
        $markup = $result['serialized_blocks'];
        $attrs = $attributes($markup, 'navigation-submenu');
        $assert('Club' === ($attrs['label'] ?? null), "$row: lowers to a submenu");
        $classes = preg_split('/\s+/', $attrs['className'] ?? '') ?: array();
        $assert(!in_array('nav-link', $classes, true) && !in_array('dropdown-toggle', $classes, true), "$row: toggle classes do not style the LI");
        $assert(in_array('nav-item', $classes, true) && in_array('dropdown', $classes, true) && 'm1' === ($attrs['anchor'] ?? ''), "$row: item retains its own classes and ID");
        $subject = $attrs['metadata']['blocksEngineNavigationAnchor'] ?? array();
        $assert('nav-link dropdown-toggle' === ($subject['className'] ?? '') && 'club-toggle' === ($subject['id'] ?? ''), "$row: existing anchor metadata owns toggle classes and ID");
        $assert(('inline button' === $variant ? 'color:red' : '') === ($subject['style'] ?? null), "$row: inline paint belongs to the toggle");
        $navigation = $attributes($markup, 'navigation');
        $assert(false === ($navigation['showSubmenuIcon'] ?? true), "$row: the toggle's own caret is the only submenu indicator");
        $emittedCss = implode("\n", array_column($result['assets'], 'content'));
        preg_match('/blocks-engine-navigation-anchor-[a-f0-9]{12}-\d+/', $attrs['className'], $marker);
        $assert(isset($marker[0]), "$row: toggle has an item-addressed author selector subject");
        $assert(1 === preg_match('/[^{}]*' . preg_quote($marker[0], '/') . '[^{}]*:where\(\.wp-block-navigation-item__content\)[^{}]*\{[^{}]*padding:0[;}]/', $emittedCss), "$row: authored li button rule resets toggle padding");

        // Core's rendered shape: the NAV root and its inner container both carry
        // the block classes; items are the container's children.
        preg_match('/<!-- wp:group (\{"anchor":"menu".*?\}) -->/s', $markup, $groupMatch);
        $group = json_decode($groupMatch[1] ?? '', true) ?: array();
        $navClasses = 'wp-block-navigation ' . htmlspecialchars($navigation['className'] ?? '');
        $list = '<ul class="wp-block-navigation__container ' . $navClasses . '"><li id="rendered-item" class="wp-block-navigation-item has-child ' . htmlspecialchars($attrs['className']) . '"></li></ul>';
        $rendered = 'nav' === $host
            ? '<nav id="' . htmlspecialchars($navigation['anchor'] ?? '') . '" class="' . $navClasses . '">' . $list . '</nav>'
            : '<div id="' . htmlspecialchars($group['anchor'] ?? '') . '" class="wp-block-group ' . htmlspecialchars($group['className'] ?? '') . '"><nav class="' . $navClasses . '">' . $list . '</nav></div>';
        $dom = new DOMDocument();
        $dom->loadHTML('<html><body>' . $rendered . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $item = $dom->getElementById('rendered-item');
        $itemBox = array();
        foreach ($result['assets'] as $asset) {
            if ('css' !== ($asset['kind'] ?? '')) continue;
            (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $selector, string $body, array $conditions) use (&$itemBox, $item): void {
                if (!str_contains($body, 'padding:0 10px')) return;
                foreach (array_map('trim', explode(',', $selector)) as $candidate) {
                    $parsed = CssSelectorMatcher::parse($candidate);
                    if (($parsed['supported'] ?? false) && (bool) (CssSelectorMatcher::matches($item, $parsed)['matches'] ?? false)) $itemBox[] = $body;
                }
            });
        }
        $assert(array() !== $itemBox, "$row: the authored `#menu > ul > li` rule reaches core's rendered item");
        $assert(array() !== array_filter($itemBox, static fn (string $body): bool => str_contains($body, 'height:71px') && str_contains($body, 'display:inline-block')), "$row: item keeps its authored height and display");

        $link = array();
        preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $markup, $linkMatches);
        foreach ($linkMatches[1] as $json) {
            $candidate = json_decode($json, true) ?: array();
            if ('Contact' === ($candidate['label'] ?? '')) $link = $candidate;
        }
        $assert('/c' === ($link['url'] ?? '') && 'nav-link' === ($link['metadata']['blocksEngineNavigationAnchor']['className'] ?? '') && !in_array('nav-link', preg_split('/\s+/', $link['className'] ?? '') ?: array(), true), "$row: plain link still separates item and anchor");
    }
}

// A submenu whose toggle paints nothing of its own keeps core's indicator.
$plain = str_replace('.dropdown-toggle::after{content:"";display:inline-block;border-top:4px solid} ', '', $css);
$html = '<header><nav id="menu"><ul class="nav"><li class="nav-item dropdown"><button type="button" class="nav-link dropdown-toggle" aria-haspopup="menu" aria-controls="p" aria-expanded="false" data-blocks-engine-capture-dialog-trigger="p">Club</button><div id="p" class="dropdown-menu data-blocks-engine-capture-dialog data-blocks-engine-capture-dropdown" role="dialog" aria-modal="true" hidden data-blocks-engine-capture-dialog-panel="p"><a href="/a">A</a><a href="/b">B</a></div></li><li class="nav-item"><a class="nav-link" href="/c">Contact</a></li></ul></nav></header>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<style>' . $plain . '</style>' . $html)))->toArray();
$assert(str_contains($result['serialized_blocks'], '<!-- wp:navigation-submenu'), 'plain toggle: lowers to a submenu');
$assert(!array_key_exists('showSubmenuIcon', $attributes($result['serialized_blocks'], 'navigation')), 'plain toggle: core keeps its submenu indicator');
echo "Navigation submenu toggle subject passed\n";
