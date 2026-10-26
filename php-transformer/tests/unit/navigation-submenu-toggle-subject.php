<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$css = '.nav{display:flex;list-style:none;padding-left:0} .nav-link{display:block;padding:8px 16px} .dropdown-menu{display:none;position:absolute} #menu > ul > li{display:inline-block;padding:0 10px;height:71px} #menu > ul > li a, #menu > ul > li button{display:block;height:71px;line-height:71px;padding:0}';

// The Bootstrap-only input is not recognized as a submenu. Supply the existing
// capture dropdown contract, without changing its list/item/button subjects.
foreach (array('button' => '', 'inline button' => ' style="color:red"') as $row => $extra) {
    $html = '<header><nav id="menu"><ul class="nav"><li id="m1" class="nav-item dropdown"><button type="button" data-bs-toggle="dropdown" id="club-toggle" class="nav-link dropdown-toggle"' . $extra . ' aria-haspopup="menu" aria-controls="club-panel" aria-expanded="false" data-blocks-engine-capture-dialog-trigger="club-panel">Club</button><div id="club-panel" class="dropdown-menu data-blocks-engine-capture-dialog data-blocks-engine-capture-dropdown" role="dialog" aria-modal="true" hidden data-blocks-engine-capture-dialog-panel="club-panel"><a class="dropdown-item" href="/a">A</a><a class="dropdown-item" href="/b">B</a></div></li><li class="nav-item"><a class="nav-link" href="/c">Contact</a></li></ul></nav></header>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<style>' . $css . '</style>' . $html)))->toArray();
    $markup = $result['serialized_blocks'];
    preg_match('/<!-- wp:navigation-submenu (\{.*?\}) -->/s', $markup, $match);
    $attrs = json_decode($match[1] ?? '', true) ?: array();
    $assert('Club' === ($attrs['label'] ?? null), "$row: lowers to a submenu");
    $classes = preg_split('/\s+/', $attrs['className'] ?? '') ?: array();
    $assert(!in_array('nav-link', $classes, true) && !in_array('dropdown-toggle', $classes, true), "$row: toggle classes do not style the LI");
    $assert(in_array('nav-item', $classes, true) && in_array('dropdown', $classes, true) && 'm1' === ($attrs['anchor'] ?? ''), "$row: item retains its own classes and ID");
    $subject = $attrs['metadata']['blocksEngineNavigationAnchor'] ?? array();
    $assert('nav-link dropdown-toggle' === ($subject['className'] ?? '') && 'club-toggle' === ($subject['id'] ?? ''), "$row: existing anchor metadata owns toggle classes and ID");
    $assert(('inline button' === $row ? 'color:red' : '') === ($subject['style'] ?? null), "$row: inline paint belongs to the toggle");
    $emittedCss = implode("\n", array_column($result['assets'], 'content'));
    preg_match('/blocks-engine-navigation-anchor-[a-f0-9]{12}-\d+/', $attrs['className'], $marker);
    $assert(isset($marker[0]), "$row: toggle has an item-addressed author selector subject");
    $assert(1 === preg_match('/[^{}]*\.wp-block-navigation-item[^{}]*\{[^{}]*padding:0 10px[;}]/', $emittedCss), "$row: item padding survives");
    $assert(1 === preg_match('/[^{}]*' . preg_quote($marker[0], '/') . '[^{}]*:where\(\.wp-block-navigation-item__content\)[^{}]*\{[^{}]*padding:0[;}]/', $emittedCss), "$row: authored li button rule resets toggle padding");
    preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/s', $markup, $linkMatches);
    $link = array();
    foreach ($linkMatches[1] as $json) {
        $candidate = json_decode($json, true) ?: array();
        if ('Contact' === ($candidate['label'] ?? '')) $link = $candidate;
    }
    $assert('/c' === ($link['url'] ?? '') && 'nav-link' === ($link['metadata']['blocksEngineNavigationAnchor']['className'] ?? '') && !in_array('nav-link', preg_split('/\s+/', $link['className'] ?? '') ?: array(), true), "$row: plain link still separates item and anchor");
}
echo "Navigation submenu toggle subject passed\n";
