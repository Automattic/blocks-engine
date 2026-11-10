<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$links = '<ul><li><a href="/one">One</a></li><li><a href="/two">Two</a></li></ul>';
$css = <<<'CSS'
.mobile{display:none}
.panel{position:fixed;inset:0;display:flex;flex-direction:column;justify-content:center;text-align:center;visibility:hidden;opacity:0;background:#e5e3dc}
@media(max-width:799px){
.desktop{display:none}.mobile{display:flex;align-items:center;justify-content:space-between;padding:20px}
.burger{width:35px;height:35px}.bar{display:block;height:1px;background:#303636}
}
CSS;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (array(true, false) as $flagged) {
    $html = '<!doctype html><html><head><style>' . $css . '</style></head><body><header><div class="desktop"><a href="/">Brand</a><nav>' . $links . '</nav></div>'
        . '<div class="mobile"><a href="/">Brand</a><button class="burger" aria-label="Menu" aria-controls="panel"><span class="bar"></span><span class="bar"></span></button></div>'
        . '<div id="panel" class="panel" style="padding-top:111.969px"' . ($flagged ? ' data-blocks-engine-capture-fluid-ignore-padding' : '') . '><nav>' . $links . '</nav></div></header><main><h1>Page</h1></main></body></html>';
    $compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)));
    $result = $compiled->toArray();
    $styles = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '') && !str_contains($asset['path'], 'editor-static')), 'content'));
    $view = $compiled->toWordPressSitePlanView();
    $plan = stripslashes(implode("\n", array_map(static fn(array $write): string => $write['canonical_payload'] ?? $write['payload']['data'] ?? '', $view['wordpress_site_plan']['writes'])));
    if (!$flagged) {
        $assert(!str_contains($result['serialized_blocks'], 'blocks-engine-navigation-header-offset'), 'Unflagged panels must not gain header-height behavior.');
        continue;
    }
    $assert(str_contains($result['serialized_blocks'], 'blocks-engine-navigation-header-offset'), 'Flagged panel must carry the live header offset hook.');
    $assert(!str_contains($styles, '111.969px'), 'Frozen capture padding must not survive in emitted overlay CSS.');
    $assert(str_contains($styles, '.is-menu-open:not(.disable-default-overlay) .wp-block-navigation__responsive-dialog .wp-block-navigation__responsive-container-content{box-sizing:border-box!important;min-height:100dvh!important;padding-top:var(--blocks-engine-navigation-header-height,0px)!important}'), 'Only the open default overlay must center links in the viewport below the header.');
    $assert(str_contains($plan, "navigation.closest('header')") && str_contains($plan, 'header.getBoundingClientRect().height') && str_contains($plan, 'ResizeObserver(update).observe(header)'), 'Emitted theme must measure its own live header and follow responsive height changes.');
    $assert(str_contains($plan, "wp_enqueue_script( 'blocks-engine-navigation-header-offset'"), 'Theme must enqueue the measurement runtime.');
}
