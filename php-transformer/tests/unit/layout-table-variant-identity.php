<?php
declare(strict_types=1);

require getenv('BLOCKS_ENGINE_TEST_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

// A captured desktop/mobile document pair whose bar is a layout table lowered
// to columns. The mobile copy's cell id is renamed for uniqueness, so author
// `#id` rules must still reach it, and the lowered row must keep table-row
// display under Core's global flex layout rule.
$css = '#menu-cell ul li{display:inline-block}@media (max-width:1024px){#menu-cell ul li{display:none !important}}@media (max-width:767px){#bar td{display:block}}';
$bar = '<div id="bar"><table><tr><td id="menu-cell"><ul><li><a href="/one">One</a></li><li><a href="/two">Two</a></li></ul></td><td class="side"><h2>Wide title</h2></td></tr></table></div>';
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body>'
    . '<div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope="">' . $bar . '</div>'
    . '<div class="data-blocks-engine-capture-mobile-document" data-blocks-engine-capture-document-scope="">' . $bar . '</div>'
    . '</body></html>';
$result = (new ArtifactCompiler())->compile(array('files' => array('index.html' => $html)))->toArray();
$blocks = $result['serialized_blocks'];
$stylesheet = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'editor' !== ($asset['stylesheet_target'] ?? 'both')), 'content'));

$failures = array();
if ( ! str_contains($stylesheet, ':is(#menu-cell,.blocks-engine-editor-anchor-menu-cell)') ) {
    $failures[] = 'The cell id selector must be projected to reach the renamed variant copy.';
}
if ( ! preg_match('/<div\b(?=[^>]*\bid="menu-cell--data-blocks-engine-capture-mobile")(?=[^>]*\bclass="[^"]*\bblocks-engine-editor-anchor-menu-cell\b)[^>]*>/', $blocks) ) {
    $failures[] = 'A renamed layout-table cell column must carry the editor-anchor class that the projected id selector matches.';
}

// Core's global styles give every flex-layout container `body .is-layout-flex{display:flex}`.
$coreFlexSpecificity = CssSelectorMatcher::specificity(CssSelectorMatcher::parse('body .is-layout-flex'));
$rowRules = 0;
if ( preg_match_all('/([^{}]+)\{([^{}]*display:table-row[^{}]*)\}/', $stylesheet, $matches, PREG_SET_ORDER) ) {
    foreach ( $matches as $match ) {
        foreach ( explode(',', $match[1]) as $selector ) {
            if ( ! str_contains($selector, 'blocks-engine-layout-table-row') ) {
                continue;
            }
            ++$rowRules;
            if ( CssSelectorMatcher::specificity(CssSelectorMatcher::parse(trim($selector))) <= $coreFlexSpecificity ) {
                $failures[] = 'The lowered row display repair must outrank Core\'s global flex layout rule: ' . trim($selector);
            }
        }
    }
}
if ( 0 === $rowRules ) {
    $failures[] = 'The lowered layout table must ship its table-row display repair.';
}

if ( str_contains($blocks, '<!-- wp:html') ) {
    $failures[] = 'The layout table must stay native blocks.';
}

if ( $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Layout table variant identity passed\n");
