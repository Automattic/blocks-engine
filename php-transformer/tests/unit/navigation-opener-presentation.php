<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = require dirname(__DIR__) . '/fixtures/navigation-opener-presentation.php';
// The header of a page captured as desktop and phone documents may render
// from its entry template part, so the emitted markup is the page plus parts.
$compile = static function (string $html): array {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toWordPressSitePlanView()['wordpress_site_plan'];
    foreach ($plan['template_parts'] ?? array() as $part) $plan['pages'][] = array('canonical_block_markup' => $part['canonical_block_markup'] ?? '');
    return $plan;
};
$assert = static function(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$plan = $compile($source);
$page = '';
foreach ($plan['pages'] as $document) $page .= $document['canonical_block_markup'] ?? '';
$assert(str_contains($page, 'blocksEngineNavigationOpener'), 'source-only single passive SVG reaches native navigation metadata');
$assert(str_contains($page, 'M2 3h16v2H2zM2 9h16v2H2zM2 15h16v2H2z'), 'original source artwork is retained rather than a generic menu variant');
$wrappedSource = str_replace($icon, '<div class="icon-wrapper"><div>' . $icon . '</div></div>', $source);
$wrappedSource = str_replace('</style>', '.icon-wrapper{--icon-size:30px;width:var(--icon-size);height:var(--icon-size)}.icon-wrapper svg{width:100%;height:100%}</style>', $wrappedSource);
$wrapped = $compile($wrappedSource);
$wrappedMarkup = '';
foreach ($wrapped['pages'] as $document) $wrappedMarkup .= $document['canonical_block_markup'] ?? '';
$assert(str_contains($wrappedMarkup, 'blocksEngineNavigationOpener'), 'nested wrapper SVG reaches native navigation metadata');
$assert(str_contains($wrappedMarkup, 'M2 3h16v2H2zM2 9h16v2H2zM2 15h16v2H2z'), 'wrapped source artwork is retained');
$wrappedCss = implode("\n", array_column($wrapped['assets'], 'content'));
$assert(1 === preg_match('/responsive-container-open\{[^}]*width:30px!important;height:30px!important/', $wrappedCss), 'wrapper custom-property dimensions size the native button');
$assert(1 === preg_match('/responsive-container-open>svg\{[^}]*width:100%!important;height:100%!important/', $wrappedCss), 'wrapped SVG fills the transferred dimensions');
foreach (array(
    'mixed label' => str_replace('data-blocks-engine-capture-dialog-trigger="phone-menu">', 'data-blocks-engine-capture-dialog-trigger="phone-menu">Menu', $source),
    'multiple icons' => str_replace('data-blocks-engine-capture-dialog-trigger="phone-menu">', 'data-blocks-engine-capture-dialog-trigger="phone-menu"><svg width="12" height="12"><circle r="4" /></svg>', $source),
    'document-dependent svg' => str_replace('data-blocks-engine-capture-dialog-trigger="phone-menu">' . $icon, 'data-blocks-engine-capture-dialog-trigger="phone-menu"><svg width="20" height="20"><defs><linearGradient id="paint" /></defs><rect fill="url(#paint)" width="20" height="20" /></svg>', $source),
) as $name => $negative) {
    $result = $compile($negative);
    $markup = '';
    foreach ($result['pages'] as $document) $markup .= $document['canonical_block_markup'] ?? '';
    $assert(!str_contains($markup, 'blocksEngineNavigationOpener'), $name . ' does not justify replacing Core button content');
    $assert(str_contains($markup, 'wp:navigation'), $name . ' retains native menu behavior');
}
// Core renders a text opener ("Menu"/"Close") or an icon. A text-labelled
// source opener keeps the text; an icon opener keeps Core's icon.
$opener = static function (string $content) use ($compile): string {
    $markup = '';
    foreach ($compile('<!doctype html><html><head><style>header{display:flex;gap:20px}.opener{display:none}nav{display:flex;gap:28px}@media(max-width:760px){.opener{display:block}header nav{display:none;flex-direction:column}}</style></head><body>'
        . '<header><a href="/">Brand</a><button class="opener" type="button" aria-expanded="false" aria-controls="nav" data-blocks-engine-capture-dialog-trigger="nav">' . $content . '</button>'
        . '<nav id="nav" class="data-blocks-engine-capture-dialog data-blocks-engine-capture-dropdown" hidden data-blocks-engine-capture-dialog-panel="nav"><a href="#a">A</a><a href="#b">B</a></nav></header>'
        . '<main><h1>Home</h1><h2 id="a">A</h2><h2 id="b">B</h2></main></body></html>') as $section) if (is_array($section)) array_walk_recursive($section, static function (mixed $value) use (&$markup): void { if (is_string($value)) $markup .= $value . "\n"; });
    $found = preg_match('~<!-- wp:navigation \{[^\n]*"overlayMenu":"mobile"[^\n]*?/-->~', $markup, $navigation);
    return $found ? $navigation[0] : '';
};
$text = $opener('Menu');
$assert(str_contains($text, '"hasIcon":false') && !str_contains($text, 'blocksEngineNavigationOpener'), 'a text-only opener renders Core\'s text opener: ' . $text);
$icon = $opener('<svg width="20" height="20" viewBox="0 0 20 20"><path d="M2 3h16v2H2z"/></svg>');
$assert('' !== $icon && !str_contains($icon, '"hasIcon":false'), 'an icon opener keeps Core\'s icon: ' . $icon);
echo "Navigation source opener contract passed\n";
