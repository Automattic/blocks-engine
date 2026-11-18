<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A projected button-link rule yields values the theme button element
// already renders. When a framework base rule (`.btn{font-size:1rem}`) is
// projected onto the same links, it outranks the element style, so the
// author override (`a.btn{font-size:.875rem}`) must keep its declaration.
$boot = '.btn{--bs-btn-font-size:1rem;--bs-btn-font-weight:400;display:inline-block;padding:.375rem .75rem;font-size:var(--bs-btn-font-size);font-weight:var(--bs-btn-font-weight);line-height:1.5;border:1px solid transparent;border-radius:.375rem}.mx-auto{margin-right:auto!important;margin-left:auto!important}.d-flex{display:flex!important}';
$site = 'body{font-family:Poppins,sans-serif;color:#2d2e2e}a.btn, button.btn, .btn-primary{display:inline-block;font-size:.875rem;padding:1.25rem 2rem;font-weight:500;border:1px solid #000;width:fit-content;border-radius:3rem}.btn-primary{background-color:transparent;color:#2a493b;border-color:#2a493b}.btn-primary:hover{background-color:#2a493b;color:#fff}';
$link = static fn(string $href): string => '<div class="d-flex"><a class="btn btn-primary mx-auto" href="/' . $href . '/">See ' . $href . '</a></div>';
$page = static fn(string $title, string $a, string $b): string => '<!doctype html><html><head><link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/site.css"></head><body><main><h2>' . $title . '</h2><p>Copy.</p>' . $link($a) . '<p>More.</p>' . $link($b) . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Calendar', 'events', 'archive'),
    'blog/index.html' => $page('Blog', 'posts', 'authors'),
    'bootstrap.css' => $boot,
    'site.css' => $site,
)))->toWordPressSitePlanView()['wordpress_site_plan'];
$css = implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($plan['assets'], static fn($asset): bool => is_array($asset) && 'css' === ($asset['kind'] ?? null))));
preg_match_all('/[^{}]*\.wp-block-button__link[^{}]*\{([^}]*padding:1\.25rem 2rem[^}]*)\}/', $css, $rules);
if (array() === $rules[1]) throw new RuntimeException('Expected the author button rule projected onto the button links.');
foreach ($rules[1] as $body) {
    foreach (array('font-size', 'font-weight') as $property) {
        if (!str_contains($body, $property . ':')) throw new RuntimeException("The author button rule keeps {$property} the projected .btn rule would otherwise win: {$body}");
    }
}
echo "Button element yield keeps competing declarations passed\n";
