<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array('website/index.html' => '<html><head><style>
        .menu{display:flex;flex-direction:column}.jump{display:none!important}
        @media(max-width:767px){.menu{display:none}.jump{display:block!important}}
        @media(min-width:900px){.menu{flex-direction:row}}
        </style></head><body><header><div><ul class="menu"><li><a href="/">Home</a></li><li><a href="/about/">About</a></li></ul><select class="jump"><option value="#">Navigate to...</option><option value="/">Home</option><option value="/about/">About</option></select></div></header><main><p>Hello</p></main></body></html>'),
))->toArray();
$site = $result['source_reports']['compiled_site'];
$css = implode("\n", array_column($site['assets'] ?? array(), 'content'));
if (!preg_match('/nav\.wp-block-navigation\.blocks-engine-navigation-list-box-[^{}]+ \.wp-block-navigation__container[^{}]*\{flex-direction:row\}/', $css)) {
    fwrite(STDERR, "FAIL: responsive source list direction must address the native inner list above Core defaults\n");
    exit(1);
}
echo "responsive navigation list direction passed\n";
