<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$transform = static fn(string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$navCount = static fn(string $markup): int => (int) preg_match_all('/<!-- wp:navigation /', $markup);
$modes = static function (string $markup): array {
    preg_match_all('/<!-- wp:navigation \{.*?"overlayMenu":"(\w+)"/', $markup, $matches);
    return $matches[1];
};
$assets = static fn(array $result): string => implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));

$swap = '.phone{display:block}.wide{display:none}@media(min-width:1024px){.phone{display:none}.wide{display:block}}';
$toggle = '<a role="button" aria-haspopup="menu" aria-label="Open site menu" href="#"><svg viewBox="0 0 24 24" width="40" height="40"><path d="M3 6h18v2H3z"/></svg></a>';
$brand = '<a href="/"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="Brand" width="100" height="30"></a>';
$list = '<nav role="navigation"><ul><li><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul></nav>';
$page = static fn(string $css, string $body): string => '<!doctype html><html><head><title>t</title><style>' . $css . '</style></head><body><header>' . $body . '</header><main><p>after</p></main></body></html>';

// The reduced page: the phone row holds the toggle, the desktop row the links.
$fixture = $transform(file_get_contents(dirname(__DIR__) . '/fixtures/navigation-toggle-row-swap.html'));
$markup = $fixture['serialized_blocks'];
$assert(array('always', 'never') === $modes($markup), 'The phone row gets an overlay and the desktop row keeps its links inline.');
$assert(!str_contains($markup, 'aria-label="Open site menu"'), 'The source toggle would open nothing, so it must not stay beside the overlay.');
$assert(6 === substr_count($markup, '<!-- wp:navigation-link '), 'Both navigations carry every destination.');
$assert(1 === substr_count($markup, 'blocksEngineNavigationOpener'), 'Only the phone row draws the source icon.');
$assert(!str_contains($markup, 'wp:html'), 'No Custom HTML.');
$phoneRow = strpos($markup, 'phone-row');
$desktopRow = strpos($markup, 'desktop-row');
$overlay = strpos($markup, '"overlayMenu":"always"');
$inline = strpos($markup, '"overlayMenu":"never"');
$assert($phoneRow < $overlay && $overlay < $desktopRow && $desktopRow < $inline, 'Each navigation stays in its own source row.');
$assert(!str_contains($assets($fixture), 'flex-direction:row!important'), 'Core overlay opens as itself, not as a header dropdown bar.');

$assert(str_contains($markup, '"customOverlayBackgroundColor":"#161616"') || str_contains($markup, 'customOverlayBackgroundColor'), 'The overlay keeps the panel background.');
$css = $assets($fixture);
$assert(str_contains($css, 'background:#161616!important') || str_contains($css, 'background:rgb(22'), 'The open overlay is painted with the panel background.');
$assert(str_contains($css, 'font-size:22px!important') && str_contains($css, 'text-transform:none!important'), 'Open links use the panel type, not the row type.');
$assert(1 === preg_match('/responsive-container-close\{color:#f7f7f7!important\}/i', $css), 'The close button stays visible on the panel background.');
$assert(!str_contains($markup, 'data-blocks-engine-triggers') && !str_contains($markup, 'Close</button>'), 'The captured copy is gone.');

$boundToggle = str_replace('<a role="button"', '<a id="opener" role="button"', $toggle);
$dialogPage = static fn(string $panel, string $phone = '', string $wide = '', string $wideExtra = '', ?string $css = null): string => $page($css ?? $swap, '<div class="phone">' . ('' === $phone ? $brand : $phone) . $boundToggle . '</div><div class="wide">' . ('' === $wide ? $brand : $wide) . $list . $wideExtra . '</div><dialog id="panel" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="opener">' . $panel . '</dialog>');
$sameLinks = '<ul><li><a href="/index.html">Home</a></li><li><a href="/about">About</a></li></ul>';
$assert(array('always', 'never') === $modes($transform($dialogPage($sameLinks))['serialized_blocks']), 'Links match with or without a trailing slash or index file.');

$assert(array('always', 'never') === $modes($transform($dialogPage('<a href="#" aria-label="Close panel"></a>' . $sameLinks))['serialized_blocks']), 'A placeholder link is not a destination of its own.');

// Other pages in the capture may hold the panel: the rows alone are enough.
$noPanel = $transform($page($swap . '.phone,.wide{background-color:#161616}nav a{color:#f7f7f7}', '<div class="phone">' . $brand . $toggle . '</div><div class="wide">' . $brand . $list . '</div>'));
$assert(array('always', 'never') === $modes($noPanel['serialized_blocks']), 'Swapped rows work without a captured panel.');
$assert(!str_contains($noPanel['serialized_blocks'], 'aria-label="Open site menu"'), 'The source toggle would open nothing without a panel too.');
$assert(1 === preg_match('/is-menu-open:not\(\.disable-default-overlay\)\{background:#161616!important\}/i', $assets($noPanel)), 'Without a panel the overlay takes the paint around the toggle.');

$translucent = $transform($page($swap . 'header{background-color:#161616}.phone,.wide{background-color:rgba(22,22,22,.25)}', '<div class="phone">' . $brand . $toggle . '</div><div class="wide">' . $brand . $list . '</div>'));
$assert(1 === preg_match('/is-menu-open:not\(\.disable-default-overlay\)\{background:#161616!important\}/i', $assets($translucent)), 'A translucent row is skipped for the first solid background.');

// A panel with more in it, or other destinations, is the author's own content.
$extraPanels = array(
    'a link of its own' => '<ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li><li><a href="/only-here/">Only here</a></li></ul>',
    'text of its own' => '<p>Call us today</p>' . $sameLinks,
    'a form' => '<form><input name="q"></form>' . $sameLinks,
);
foreach ($extraPanels as $label => $panel) {
    $kept = $transform($dialogPage($panel))['serialized_blocks'];
    $assert(!in_array('always', $modes($kept), true), 'A panel with ' . $label . ' stays the captured dialog.');
}

// Not a swap of two rows: each of these keeps the existing single navigation.
$secondList = '<nav role="navigation"><ul><li><a href="/help/">Help</a></li><li><a href="/terms/">Terms</a></li></ul></nav>';
$shapes = array(
    'links shown at every width' => $dialogPage($sameLinks, '', '', '', '.phone{display:block}@media(min-width:1024px){.phone{display:none}}'),
    'a toggle shown at every width' => $dialogPage($sameLinks, '', '', '', '.wide{display:none}@media(min-width:1024px){.wide{display:block}}'),
    'two possible menus' => $dialogPage($sameLinks, '', '', $secondList),
    'wrappers around only the toggle and the links' => $page($swap, $brand . '<div class="phone">' . $boundToggle . '</div><div class="wide">' . $list . '</div><dialog id="panel" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="opener">' . $sameLinks . '</dialog>'),
);
foreach ($shapes as $label => $html) {
    $assert(!in_array('always', $modes($transform($html)['serialized_blocks']), true), 'No mirror for ' . $label . '.');
}

// A captured document's wrapper hiding the whole page is not the toggle's row hiding it.
$documented = $transform($page($swap . '.data-blocks-engine-capture-desktop-document{display:block}@media(max-width:402px){.data-blocks-engine-capture-desktop-document{display:none}}', '<div class="data-blocks-engine-capture-desktop-document"><div class="phone">' . $brand . $boundToggle . '</div><div class="wide">' . $brand . $list . '</div><dialog id="panel" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="opener">' . $sameLinks . '</dialog></div>'))['serialized_blocks'];
$assert(array('always', 'never') === $modes($documented), 'The swap is still found inside a captured document.');

echo "Navigation toggle row swap passed.\n";
