<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A framework component declares properties for parts the site never has
// (a modal header and footer here). Once the plan's sheets, markup and
// scripts are final, a component property none of them reads computes
// nothing, yet each shipped in the theme's author sheet.
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><div class="modal"><p class="lead">Hello</p></div></main>'
        . '<script>document.querySelector(".modal").style.getPropertyValue("--fw-modal-zoom");</script></body></html>',
    'site.css' => '.modal{--fw-modal-header-padding:1rem 1rem;--fw-modal-footer-gap:0.5rem;--fw-modal-padding:2rem;--fw-modal-zoom:1;padding:var(--fw-modal-padding);border:1px solid red}'
        . '.lead{margin:0}',
)))->toArray();
$css = '';
foreach ($result['source_reports']['wordpress_site_plan']['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? null) && is_string($asset['content'] ?? null)) $css .= $asset['content'] . "\n";
}
if (!str_contains($css, 'padding:var(--fw-modal-padding)')) throw new RuntimeException("Expected the modal rule in theme CSS:\n" . $css);
foreach (array('--fw-modal-header-padding', '--fw-modal-footer-gap') as $name) {
    if (str_contains($css, $name)) throw new RuntimeException('Unread component property must not ship: ' . $name . "\n" . $css);
}
foreach (array('--fw-modal-padding:2rem', '--fw-modal-zoom:1') as $declaration) {
    if (!str_contains($css, $declaration)) throw new RuntimeException('Read component property must survive: ' . $declaration . "\n" . $css);
}
echo "Unread component custom properties are pruned from final theme sheets\n";
