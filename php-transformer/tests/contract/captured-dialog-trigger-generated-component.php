<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A deep repeated-content component (two copies of one post) whose first copy
// has a captured dropdown trigger. The trigger owns only its bounded panel, so
// it must not turn the whole component into one generated raw-HTML block.
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$copy = static function (string $suffix, bool $withMenu, string $metaAttributes = ''): string {
    $menu = $withMenu
        ? '<div class="actions"><button type="button" id="more-' . $suffix . '" aria-haspopup="menu" aria-expanded="false" aria-label="More actions" data-blocks-engine-capture-dialog-trigger="panel-' . $suffix . '" aria-controls="panel-' . $suffix . '"><svg width="19" viewBox="0 0 19 19" aria-hidden="true"><circle cx="9" cy="9" r="2"></circle></svg></button>'
            . '<div class="capture-dialog" role="dialog" hidden id="panel-' . $suffix . '" data-blocks-engine-capture-dialog-panel="panel-' . $suffix . '"><div role="menu" aria-labelledby="more-' . $suffix . '"><button role="menuitem" type="button">Share</button></div></div></div>'
        : '';
    return '<div class="copy"><article class="post"><div class="post-header"><div class="meta"' . $metaAttributes . '><span>Author Name</span><span>June 1</span></div>' . $menu . '</div>'
        . '<h1>Post title</h1><p>First paragraph of the post body.</p><p>Second paragraph of the post body.</p></article></div>';
};
$compile = static function (string $script, string $metaAttributes = '') use ($copy): array {
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Post</title></head><body>'
        . str_repeat('<div class="wrap">', 14) . '<div class="post-widget">' . $copy('a', true, $metaAttributes) . $copy('b', false) . '</div>' . str_repeat('</div>', 14)
        . '<script>' . $script . '</script></body></html>';
    return (new ArtifactCompiler())->compile(array('block_namespace' => 'neutral', 'site_slug' => 'post', 'files' => array('index.html' => $html)))->toArray();
};

$dialogScript = 'document.querySelectorAll("[data-blocks-engine-capture-dialog-trigger]").forEach(function(t){t.addEventListener("click",function(){var p=document.getElementById(t.getAttribute("aria-controls"));p.hidden=!p.hidden;});});';
$result = $compile($dialogScript);
$markup = $result['serialized_blocks'];
$assert(! preg_match('~/(?:gallery|collection)-[0-9a-f]{64}~', $markup), 'A captured dialog trigger must not turn its host component into a generated raw-HTML block.');
$assert(! str_contains(json_encode($result), 'generated_component_non_native_items'), 'The component lowers natively.');
$assert(4 === preg_match_all('/<!-- wp:paragraph\b[^>]*-->\s*<p[^>]*>(?:First|Second) paragraph/', $markup), 'Post paragraphs in both copies are core/paragraph blocks.');
$assert(2 === preg_match_all('/<!-- wp:heading\b/', $markup), 'Post titles in both copies are core/heading blocks.');
$assert(str_contains($markup, 'data-blocks-engine-capture-dialog-trigger="panel-a"') && str_contains($markup, 'aria-controls="panel-a"'), 'The trigger keeps its dialog binding.');
$assert(str_contains($markup, 'id="panel-a"') && str_contains($markup, 'data-blocks-engine-capture-dialog-panel="panel-a"') && str_contains($markup, 'Share'), 'The captured panel and its item are kept.');

// Any other script-owned element in the component still keeps it generated.
$control = $compile($dialogScript . 'document.querySelectorAll("[data-counter]").forEach(function(c){c.addEventListener("click",function(){c.hidden=true;});});', ' data-counter');
$assert((bool) preg_match('~/(?:gallery|collection)-[0-9a-f]{64}~', $control['serialized_blocks']), 'A non-dialog runtime target still keeps the component generated.');

echo "captured dialog trigger generated component contract passed\n";
