<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Site-title generates a new anchor. Promoting a responsive source anchor
// loses both its breakpoint identity and its authored label.
$files = array();
foreach (array('index', 'about') as $route) {
    $files[$route . '.html'] = '<html><head><title>Company website</title><style>
        .brand{font-size:20px}@media(min-width:900px){.brand{display:none}}
        </style></head><body><header><a class="brand" href="index.html">Short Brand</a>
        <nav><ul><li><a href="index.html">Home</a></li><li><a href="about.html">About</a></li></ul></nav>
        </header><main><h1>' . $route . '</h1></main></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']);
if (count($headers) !== 1) throw new RuntimeException('Expected one shared header');
$markup = array_values($headers)[0]['canonical_block_markup'];
if (str_contains($markup, 'wp:site-title') || !str_contains($markup, 'Short Brand') || !str_contains($markup, 'brand')) {
    throw new RuntimeException('Responsive wordmark must retain its source anchor identity and label');
}
echo "shared-header-responsive-wordmark: ok\n";
