<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A utility shared with route copy must not strand the selected header
// control's instance-owned wrapper visibility in its originating stylesheet.
$document = static fn(string $title): string => '<!doctype html><html><head><title>' . $title . '</title>'
    . '<style>@layer utilities{.row{display:flex;gap:16px}.small{font-size:12px}@media(min-width:768px){.desktop-hidden{display:none}}}</style></head><body>'
    . '<header><nav><a href="index.html">Home</a><a href="about.html">About</a></nav><div class="row"><a href="cart.html">Cart</a><button class="desktop-hidden small">Menu</button></div></header>'
    . '<main><h1>' . $title . '</h1><p class="small">Content ' . $title . '</p></main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('files' => array(
    'index.html' => $document('Home'),
    'about.html' => $document('About'),
    'cart.html' => $document('Cart'),
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$found = false;
foreach ($plan['assets'] as $asset) {
    $css = (string) ($asset['content'] ?? '');
    if (!str_contains($css, ':only-child){display:none!important}')) continue;
    if (!str_ends_with((string) $asset['source_path'], '.shared-chrome')) {
        throw new RuntimeException('The shared control wrapper visibility must follow the header, despite its route-kept font-size utility.');
    }
    if (!str_contains($css, '@media(min-width:768px)')) throw new RuntimeException('The authored visibility breakpoint must survive.');
    $found = true;
}
if (!$found) throw new RuntimeException('Expected generated single-control wrapper visibility.');
echo "Shared chrome control wrapper visibility passed\n";
