<?php
declare(strict_types=1);
require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<html><head><style>p{max-width:540px}.wrap{width:min(1220px,100% - 68px);margin-inline:auto;display:grid;grid-template-columns:.75fr 1.25fr;gap:clamp(60px,12vw,160px)}@media(max-width:700px){.wrap{width:calc(100% - 32px);grid-template-columns:1fr;gap:48px}}</style></head><body><div id="root"><main><nav>Navigation</nav><div class="wrap"><div>Image</div><div><p>Body prose in the wider right column.</p></div></div><footer>Footer</footer></main></div></body></html>';
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)));
$templates = array_column($compiled->toWordPressSitePlanView()['wordpress_site_plan']['templates'], 'canonical_block_markup', 'slug');
if (!str_contains($templates['page'] ?? '', '667.5px') || !str_contains($templates['page'], '110px') || str_contains($templates['front-page'] ?? '', '667.5px')) {
    fwrite(STDERR, "FAIL: declarative grid prose must inform only the native fallback\n" . json_encode($templates));
    exit(1);
}
echo "declarative-prose-column: passed\n";
