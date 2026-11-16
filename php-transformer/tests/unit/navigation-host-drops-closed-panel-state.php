<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A source drawer closes by fading, offsetting and disabling the menu itself.
// Native navigation puts Core's opener (and its overlay) inside that host, so
// the host must not keep the drawer's closed state at collapsed widths.
$html = <<<'HTML'
<style>body{margin:0}.site-header{position:sticky;top:0}.nav-wrap{display:flex;align-items:center;justify-content:space-between;height:80px}
.nav{display:flex;gap:2rem}.nav-toggle{display:none;width:44px;height:44px;border:0;background:none}
@media(max-width:800px){.nav-toggle{display:flex}.nav{position:absolute;top:80px;left:0;right:0;flex-direction:column;opacity:0;visibility:hidden;clip-path:inset(0 0 100% 0);transform:translateY(-8px);pointer-events:none}.nav.open{opacity:1;visibility:visible;clip-path:none;transform:none;pointer-events:auto}}</style>
<header class="site-header"><div class="nav-wrap"><a class="brand" href="/">Brand</a><nav class="nav" id="menu" aria-label="Principal"><a href="/catalogo/">Catálogo</a><a href="#nosotros">Quiénes somos</a><a href="#contacto">Contacto</a></nav><button class="nav-toggle" aria-expanded="false" aria-controls="menu" aria-label="Abrir menú"><span></span><span></span><span></span></button></div></header>
<script>document.querySelector('.nav-toggle').addEventListener('click',()=>document.getElementById('menu').classList.toggle('open'))</script>
HTML;
$result = (new HtmlTransformer())->transform($html, array())->toArray();
$css = '';
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '')) {
        $css .= ($asset['content'] ?? '') . "\n";
    }
}
$failures = array();
if (!preg_match('/blocks-engine-native-navigation-toggle-[a-f0-9]+/', (string) ($result['serialized_blocks'] ?? ''), $marker)) {
    $failures[] = 'The drawer toggle must be replaced by native mobile navigation.';
}
$host = '.wp-block-navigation.blocks-engine-list-navigation.blocks-engine-native-responsive-navigation.' . ($marker[0] ?? 'missing');
preg_match_all('/' . preg_quote($host, '/') . '\{([^{}]*)\}/', $css, $hostRules);
$hostBody = implode(';', $hostRules[1]);
foreach (array('inset:auto', 'opacity:1', 'visibility:visible', 'pointer-events:auto', 'clip-path:none') as $declaration) {
    if (!str_contains($hostBody, $declaration . '!important')) {
        $failures[] = "The native host must reset the closed drawer state with $declaration.";
    }
}
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
}
if (array() !== $failures) {
    exit(1);
}
echo "navigation host drops closed panel state passed\n";
