<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$compile = static function (string $insets = '', bool $anchored = true, bool $navigation = true, bool $preceding = false): array {
    $page = static function (string $title) use ($insets, $anchored, $navigation, $preceding): string {
        $document = '<div id="backdrop" class="bg"></div><div class="body"><header class="site-header"><p>Studio Name</p><nav><a href="index.html">Home</a><a href="about.html">About</a><a href="services.html">Services</a></nav></header><main><h1>' . $title . '</h1><p>Route content.</p></main><footer class="site-footer"><p>Studio footer</p></footer></div>';
        if (!$navigation) $document = preg_replace('/<nav>.*?<\/nav>/', '', $document);
        if ($preceding) $document = '<div class="skip-links"><a href="#content">Skip</a></div><nav class="mobile-menu"><a href="index.html">Menu</a></nav>' . $document;
        if (!$anchored) $document = str_replace(' id="backdrop"', '', $document);
        return '<html><head><style>body{position:relative;margin:0}.bg{position:absolute;width:100%;height:100%;background:#fff;' . $insets . '}.site-header{padding:20px;background:#eee}.site-footer{background:#f3f3f3;padding:40px}</style></head><body><div class="data-blocks-engine-capture-desktop-document">' . $document . '</div><div class="data-blocks-engine-capture-mobile-document">' . ($navigation ? str_replace('Studio Name', 'Studio mobile', $document) : $document) . '</div></body></html>';
    };
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page('Home'), 'about.html' => $page('About'), 'services.html' => $page('Services'))))->toArray()['source_reports']['wordpress_site_plan'];
};

$plan = $compile();
$context = static fn(array $plan): string => implode("\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), array_filter($plan['assets'], static fn(array $asset): bool => str_contains($asset['path'] ?? '', 'shared-chrome-context'))));
$css = $context($plan);
$header = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? '')))[0] ?? array();
if ('shared_shell' !== ($header['placement']['kind'] ?? null)) throw new RuntimeException('Fixture must hoist the shared header: ' . json_encode($plan['template_parts']));
$ids = $header['ancestor_context']['static_start_ids'] ?? array();
$ids = array_values(array_filter($ids, static fn(string $id): bool => str_starts_with($id, 'backdrop')));
if (2 !== count($ids)) throw new RuntimeException('Both capture documents must retain preceding backdrop hooks: ' . json_encode($header['ancestor_context'] ?? null));
foreach ($ids as $id) {
    if (!str_contains($css, '#' . $id . '{top:0}')) throw new RuntimeException('Backdrop must start at the containing block origin, not after the header: ' . $css);
}
foreach (array('top:12px', 'inset:0', 'bottom:0', 'inset-block-start:5px') as $explicit) {
    if (preg_match('/\{top:0\}/', $context($compile($explicit)))) throw new RuntimeException('Explicit inset must be left alone: ' . $explicit);
}
if (!preg_match('/\{top:0\}/', $context($compile('top:auto;left:auto')))) throw new RuntimeException('Explicit auto is still a static position.');

if (!str_contains($context($compile('', false)), '.wp-site-blocks .bg{top:0}')) throw new RuntimeException('Class-only source backdrops must not require an authored id.');

$siblingsPlan = $compile('', true, true, true);
$inlinePlan = $compile('', false, false);
foreach (array($siblingsPlan, $inlinePlan) as $case) {
    $caseCss = $context($case);
    if (!str_contains($caseCss, '#backdrop{top:0}') && !str_contains($caseCss, '.bg{top:0}')) throw new RuntimeException('Every preceding backdrop needs a rule: ' . $caseCss);
    if (preg_match('/\.(?:skip-links|mobile-menu)\{top:0\}/', $caseCss)) throw new RuntimeException('Static siblings must not receive positioning rules.');
}
$inlineHeader = array_values(array_filter($inlinePlan['template_parts'], static fn(array $part): bool => 'header' === ($part['area'] ?? '')))[0] ?? array();
if ('inline_shared_shell' !== ($inlineHeader['placement']['kind'] ?? null)) throw new RuntimeException('No-nav reduction must exercise inline_shared_shell.');
if (!preg_match('/\{top:0\}/', $context($compile('position:fixed')))) throw new RuntimeException('Fixed auto-inset boxes also retain their static start.');

// Also consumed by the browser geometry regression.
if (in_array('--json', $argv, true)) echo json_encode(array('shared' => $plan, 'siblings' => $siblingsPlan, 'inline' => $inlinePlan), JSON_THROW_ON_ERROR);
else echo "detached auto-inset backdrop contract passed\n";
