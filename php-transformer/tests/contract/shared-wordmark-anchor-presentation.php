<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Exercise promotion through the public whole-site compiler: a class is not
// itself a reason to reject a wordmark, but inner-link presentation must survive.
foreach (array('' => true, '.brand{padding:12px}' => false, '@media(min-width:900px){.brand{display:none}}' => false) as $extra => $promoted) {
    $files = array();
    foreach (array('index', 'about', 'visit') as $route) {
        $files[$route . '.html'] = '<html><head><style>.brand{font-size:24px;font-family:Georgia;margin:0}' . $extra . '</style></head><body><header><div class="logo"><a class="brand" href="index.html">Garden Studio</a></div><nav><ul><li><a href="index.html">Home</a></li><li><a href="about.html">About</a></li><li><a href="visit.html">Visit</a></li></ul></nav></header><main><h1>' . $route . '</h1><p>Page content</p></main></body></html>';
    }
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $headers = array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === $part['area']);
    $markup = implode("\n", array_column($headers, 'canonical_block_markup'));
    if (!$headers || $promoted !== str_contains($markup, 'wp:site-title ')) {
        throw new RuntimeException('Wordmark promotion must distinguish transferable typography from inner-anchor presentation: ' . $extra);
    }
    if (!$promoted && !str_contains($markup, 'class="brand"')) {
        throw new RuntimeException('Unpromoted wordmark must preserve its authored anchor class.');
    }
}
echo "shared-wordmark-anchor-presentation: ok\n";
