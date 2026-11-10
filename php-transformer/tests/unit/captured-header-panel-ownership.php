<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CapturedDialogBlockGenerator;

// Projected Breakdance-style header: the resting snapshot describes the list,
// not its display:contents dialog wrapper. Search must not borrow this list.
$css = 'body{margin:0}.breakdance-menu-list{display:none;visibility:hidden;position:absolute;top:93px;left:0;width:390px;margin:0;padding:0;list-style:none;flex-direction:column;gap:0}.breakdance-menu-list.is-visible{visibility:visible}.breakdance-menu-list:not(.is-visible):not(.is-collapsing){height:0;overflow:hidden;pointer-events:none;visibility:hidden}.breakdance-menu-list .wp-block-navigation__container{position:static}.breakdance-menu-list a{display:block;height:55px;width:390px}.search-form__lightbox{visibility:hidden;position:fixed;inset:0}.search-form__lightbox.is-visible{visibility:visible}.search-form__field{position:absolute;left:92px;top:374px;width:258px;height:52px;box-sizing:border-box}#menuBtn{position:absolute;left:40px;top:38px;width:47px;height:43px}#searchBtn{position:absolute;left:313px;top:33px;width:52px;height:52px}';
$state = static fn(string $class): string => htmlspecialchars(json_encode(array('closed' => array('class' => $class, 'style' => 'display:none'), 'opened' => array('class' => $class . ' is-visible', 'style' => 'display:flex;visibility:visible !important;'))), ENT_QUOTES);
$links = '';
for ($i = 1; $i <= 7; ++$i) $links .= '<li><a href="/page-' . $i . '">Link ' . $i . '</a></li>';
$html = '<html><head><style>' . $css . '</style></head><body><header>'
    . '<button id="menuBtn" class="menu-toggle" aria-label="Menu" aria-controls="menuDialog" aria-haspopup="menu" aria-expanded="false">Menu</button>'
    . '<div id="menuDialog" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="menuBtn" data-blocks-engine-presentation="dropdown" data-blocks-engine-placement="in-place" data-blocks-engine-resting="authored" data-blocks-engine-resting-state="' . $state('breakdance-menu-list') . '"><ul id="authoredList" class="breakdance-menu-list" style="display:none">' . $links . '</ul></div>'
    . '<button id="searchBtn" class="search-form__button--full-screen js-search-form-expand-button" aria-label="Search" aria-controls="searchDialog" aria-haspopup="dialog" aria-expanded="false"><span></span></button>'
    . '<div id="searchDialog" data-blocks-engine-captured-dialog="true" data-blocks-engine-triggers="searchBtn" data-blocks-engine-presentation="dropdown" data-blocks-engine-placement="in-place" data-blocks-engine-resting="authored" data-blocks-engine-resting-state="' . $state('search-form__lightbox') . '"><div class="search-form__lightbox"><form role="search"><input class="search-form__field js-search-form-field" type="search" name="s"></form><button id="searchClose" aria-label="Close search" data-blocks-engine-dialog-close="true">×</button></div></div>'
    . '<button class="menu-toggle" aria-label="Other menu" aria-controls="authoredList" aria-expanded="false"><span></span></button>'
    . '</header><main><h1>Home</h1></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(array('path' => 'index.html', 'content' => $html))))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$markup = implode('', array_column($plan['pages'] ?? array(), 'canonical_block_markup')) . implode('', array_column($plan['template_parts'] ?? array(), 'canonical_block_markup'));
$failures = array();

// The unprojected section must not lose its search icon to the sibling menu.
$section = file_get_contents(__DIR__ . '/../fixtures/breakdance-header-search.html');
$transformed = (new \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer())->transform($section)->toArray();
$sectionMarkup = $transformed['serialized_blocks'];
if (1 !== substr_count($sectionMarkup, 'class="wp-block-button search-form__button search-form__button--full-screen"')) $failures[] = 'Sibling navigation must not consume the search control';

// Replay captured panels on the same source section, as a site import does.
require_once __DIR__ . '/../fixtures/captured-components.php';
$document = new DOMDocument();
@$document->loadHTML($section);
$evidence = array();
foreach (array('menu-143', 'search-form-153') as $panelId) {
    $panel = $document->getElementById($panelId);
    $panelHtml = '<div>' . $document->saveHTML($panel) . '</div>';
    $evidence[] = array('status' => 'captured', 'trigger' => array('selector' => 'menu-143' === $panelId ? '.breakdance-menu-toggle' : '.search-form__button--full-screen', 'tag' => 'button', 'label' => 'menu-143' === $panelId ? 'Open Menu' : 'Toggle search', 'dataBindings' => array()), 'dialog' => array('html' => $panelHtml, 'htmlBytes' => strlen($panelHtml), 'htmlTruncated' => false, 'presentation' => 'dropdown'));
}
$captured = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'content' => $section),
    array('path' => 'capture-receipt.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/capture-receipt/v1', 'routes' => array(array('url' => 'https://example.test/', 'path' => 'index.html'))))),
    array('path' => 'interaction-states.json', 'content' => json_encode(array('schema' => 'blocks-engine-capture/captured-interactions/v2', 'pages' => array(array('sourceUrl' => 'https://example.test/', 'components' => captured_components($evidence)))))),
)))->toArray();
$capturedMarkup = $captured['serialized_blocks'];

foreach (array('Toggle search', 'Open Menu') as $label) {
    if (1 !== substr_count($capturedMarkup, 'aria-label="' . $label . '"')) $failures[] = 'Captured section must retain exactly one ' . $label . ' trigger';
}
foreach (array('menuDialog' => 'Link 7', 'searchDialog' => 'wp:search') as $id => $content) {
    preg_match('/<!-- wp:group [^>]*"id":"' . $id . '"[^>]* -->(.*?)<!-- \/wp:group -->/s', $markup, $match);
    if (!str_contains($match[1] ?? '', $content)) $failures[] = $id . ' must own its panel content';
}
if (str_contains($markup, 'blocks-engine-native-responsive-navigation')) $failures[] = 'Captured controls must not become Core overlay openers';
$definition = (new CapturedDialogBlockGenerator())->definition('custom/captured-dialog');
// core/search is dynamic; materialize its input for this render-free runtime
// probe, retaining the source field selector just as the original probe does.
$searchProbeMarkup = preg_replace_callback('/<!-- wp:search (.*?) \/-->/s', static function (array $match): string {
    $attrs = json_decode($match[1], true);
    return '<form role="search" class="wp-block-search ' . htmlspecialchars($attrs['className'] ?? '', ENT_QUOTES) . '"><input type="search" name="s" class="wp-block-search__input search-form__field"></form>';
}, $capturedMarkup);
$sectionBrowserMarkup = \Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($searchProbeMarkup);
file_put_contents(sys_get_temp_dir() . '/breakdance-header-panel-ownership.json', json_encode(array('markup' => $sectionBrowserMarkup, 'css' => $definition['assets']['style.css'], 'script' => $definition['view_js'])));
$browserMarkup = \Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup);
// Model Core's duplicated source anchor as well as its duplicated classes.
// Keep the closed-state selector active on the inner list (no class stripping).
$browserMarkup = preg_replace('/<ul class="wp-block-navigation__container([^"]*)">/', '<ul id="authoredList" class="wp-block-navigation__container$1">', $browserMarkup);
$browserMarkup = str_replace('<div class="wp-block-group search-form__lightbox">', '<div class="wp-block-group search-form__lightbox"><form role="search"><input type="search" class="search-form__field" name="s"></form>', $browserMarkup);
file_put_contents(sys_get_temp_dir() . '/captured-header-panel-ownership.json', json_encode(array('markup' => $browserMarkup, 'css' => $css . $definition['assets']['style.css'], 'script' => $definition['view_js'])));
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
