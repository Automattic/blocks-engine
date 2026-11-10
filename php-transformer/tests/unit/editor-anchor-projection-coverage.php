<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    }
};
// Guard placement uses selector boundaries, including pseudo-element hosts.
$guardResult = (new HtmlTransformer())->transform('<style>#ordinary::before{content:"marker"}#ordinary:hover{outline-width:7px}#ordinary::first-letter{font-size:19px}</style><section id="ordinary"><p>Body</p></section>')->toArray();
$guardCss = implode("\n", array_column(array_filter($guardResult['assets'], static fn(array $asset): bool => 'editor-static-state' === ($asset['source'] ?? '')), 'content'));
$assert(str_contains($guardCss, ':where(:not(#ordinary))::before{'), 'A pseudo-element repair guards its host before the pseudo-element.');
$assert(str_contains($guardCss, ':where(:not(#ordinary:hover)):hover{'), 'A stateful repair guards the complete original selector before the state suffix.');
$assert(str_contains($guardCss, '::first-letter{font-size:19px}'), 'An unsupported guard retains the previous repair.');
$bytes = 0;
foreach (require dirname(__DIR__) . '/fixtures/editor-anchor-projection-pages.php' as $html) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $editor = '';
    $projected = '';
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        if ('editor-static-state' === ($asset['source'] ?? '')) {
            $editor .= $asset['content'] ?? '';
        } else {
            $projected .= $asset['content'] ?? '';
        }
    }
    $bytes += strlen($editor);
    foreach (array('@font-face', '@keyframes', '@charset', '@import', 'font-family:Shared', 'font-family:Nested', 'body{margin:0}', 'h1{font-size:2em}', 'body{display:grid}') as $authorRule) {
        $assert(!str_contains($editor, $authorRule), 'Editor repairs never copy unrelated author CSS: ' . $authorRule);
    }
    $assert(str_contains($projected, '@font-face') && str_contains($projected, '@keyframes'), 'Shared author CSS retains font and animation definitions.');
    $assert((bool) preg_match('/@supports\\(display:grid\\)\\{@media\\(min-width:2px\\)\\{[^{}]*blocks-engine-editor-anchor-ordinary[^{}]*\\{padding-right:23px\\}\\}\\}/', $editor), 'Repair rules retain their nested conditions without unrelated siblings.');
    $assert(str_contains($projected, ':is(#panel,.blocks-engine-editor-anchor-panel)'), 'The main projection covers the duplicated id.');
    $assert(str_contains($projected, ':is([id="panel"],.blocks-engine-editor-anchor-panel)'), 'The main projection covers attribute ids too.');
    foreach (array('rgb(12,34,56)', 'padding-left:17px', 'border-top-width:3px') as $body) {
        $assert(!str_contains($editor, $body), 'Do not replay a fully covered selector: ' . $body);
    }
    $assert(str_contains($editor, 'blocks-engine-editor-anchor-ordinary'), 'An uncovered id is still restated.');
    $assert(str_contains($editor, 'rgb(65,43,21)'), 'The uncovered rule retains its declarations.');
    $assert(str_contains($editor, 'margin-left:19px'), 'A selector mixing covered and uncovered ids is still restated.');
    $assert(!preg_match('/[^{}]*blocks-engine-editor-anchor-panel[^{}]*\{letter-spacing:2px/', $editor), 'Covered selector-list arms are omitted independently.');
    $assert(str_contains($editor, 'letter-spacing:2px'), 'The uncovered selector-list arm remains.');
    $assert((bool) preg_match('/blocks-engine-editor-anchor-ordinary[^{}]*\{[^}]*animation:none!important;opacity:1!important/', $editor), 'Remaining anchor animation copies are settled.');
}
// Exercise the same contract through a two-route import with a shared file.
$files = array('shared.css' => '@font-face{font-family:Shared;src:url(shared.woff2)}html{font-family:Shared}body{margin:0}#content{padding:13px}');
foreach (array('index.html', 'about.html') as $route) {
    $files[$route] = '<link rel="stylesheet" href="shared.css"><main id="content"><p>' . $route . '</p></main><nav><ul><li><a href="index.html">Home</a></li><li><a href="about.html">About</a></li></ul></nav>';
}
$compiled = (new \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray();
$plan = $compiled['source_reports']['wordpress_site_plan'] ?? array();
$assert(2 === count($plan['pages'] ?? array()), 'The shared-stylesheet fixture imports both routes.');
$editorAssets = array_filter($plan['assets'] ?? array(), static fn (array $asset): bool => 'editor-static-state' === ($asset['source'] ?? ''));
$assert(array() !== $editorAssets, 'The site import retains editor anchor repairs.');
foreach ($editorAssets as $asset) {
    $css = $asset['content'] ?? '';
    $assert(str_contains($css, 'blocks-engine-editor-anchor-content') && str_contains($css, 'padding:13px'), 'Each repair sheet retains the uncovered id rule.');
    $assert(!str_contains($css, '@font-face') && !str_contains($css, 'body{margin:0}') && !str_contains($css, 'font-family:Shared'), 'Page repair sheets do not carry the shared author stylesheet.');
}
// A visually hidden skip link shared by desktop and mobile capture documents:
// its #id rule is projected as :is(#skip,.anchor), and the editor wrapper id is
// block-<clientId>, so every copy (including the unrenamed one) needs the class.
$skipHtml = '<style>#skip{position:absolute;width:0;height:0;opacity:0}</style>';
foreach (array('desktop', 'mobile') as $device) {
    $skipHtml .= '<div class="data-blocks-engine-capture-' . $device . '-document" data-blocks-engine-capture-device-document="' . $device . '" data-blocks-engine-capture-document-scope=""><button id="skip">Skip to Main Content</button><main><p>Body ' . $device . '</p></main></div>';
}
$skipMarkup = (string) ((new HtmlTransformer())->transform($skipHtml)->toArray()['serialized_blocks'] ?? '');
$assert(2 === preg_match_all('/<button[^>]*class="[^"]*blocks-engine-editor-anchor-skip|class="[^"]*blocks-engine-editor-anchor-skip[^"]*"[^>]*>\s*(?:<[^>]+>\s*)*Skip to Main Content/', $skipMarkup), 'Every variant copy of an id-styled skip link carries its editor anchor class.');
fwrite(STDOUT, 'Multi-page fixture editor-static-state bytes: ' . $bytes . PHP_EOL);
exit($failures ? 1 : 0);
