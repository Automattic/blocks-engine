<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$document = static fn (string $hero): string => '<!doctype html><html><head><link rel="stylesheet" href="assets/chrome-desktop.css" media="(min-width:601px)"><link rel="stylesheet" href="assets/chrome-phone.css" media="(max-width:600px)"></head><body><header class="site-header"><div data-mesh-id="shared-header-carrier"></div></header><main id="' . $hero . '"><h1>Hero</h1></main></body></html>';
$artifact = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $document('hero-home'),
        'about.html' => $document('hero-about'),
        'assets/chrome-desktop.css' => array(
            'path' => 'assets/chrome-desktop.css',
            'kind' => 'css',
            'media' => '(min-width:601px)',
            'content' => '[data-mesh-id="shared-header-carrier"]{min-height:102px}',
        ),
        'assets/chrome-phone.css' => array(
            'path' => 'assets/chrome-phone.css',
            'kind' => 'css',
            'media' => '(max-width:600px)',
            'content' => '[data-mesh-id="shared-header-carrier"]{min-height:56px}',
        ),
    ),
))->toArray();

$plan = $artifact['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'the artifact produces a WordPress site plan');
$parts = array_values(array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => in_array($part['placement']['kind'] ?? '', array('shared_shell', 'inline_shared_shell'), true)));
$assert(array() !== $parts, 'the identical header is extracted as a shared template part');
$partMarkup = implode('', array_column($parts, 'canonical_block_markup'));
$assert(str_contains($partMarkup, 'blocks-engine-empty-visual-group'), 'the shared header keeps an editable empty visual carrier');

$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $plan['assets'] ?? array()));
$assert((bool) preg_match('/blocks-engine-attribute-(?!state-)[a-f0-9-]+/', $partMarkup), 'the empty data-addressed carrier receives a stable generic marker');
$markers = array();
if (preg_match_all('/(blocks-engine-attribute-(?!state-)[a-f0-9-]+)/', $partMarkup, $matches)) {
    $markers = array_values(array_unique($matches[1]));
}
$marker = $markers[0] ?? '';
$assert('' !== $marker && (bool) preg_match('/(?:where\()?\.' . preg_quote($marker, '/') . '[^{}]*\{min-height:102px\}/', $css), 'desktop carrier geometry projects onto the emitted marker');
$desktopAssets = array_values(array_filter($plan['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null) && '(min-width:601px)' === ($asset['media'] ?? null) && array(array('kind' => 'global')) === ($asset['scopes'] ?? array())));
$assert(1 === count($desktopAssets) && str_contains((string) ($desktopAssets[0]['content'] ?? ''), 'min-height:102px'), 'desktop carrier geometry remains globally scoped for tablet and desktop widths');
$phoneAssets = array_values(array_filter($plan['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null) && '(max-width:600px)' === ($asset['media'] ?? null) && array(array('kind' => 'global')) === ($asset['scopes'] ?? array())));
$assert(1 === count($phoneAssets) && array_filter($markers, static fn (string $candidate): bool => str_contains((string) ($phoneAssets[0]['content'] ?? ''), '.' . $candidate)) && str_contains((string) ($phoneAssets[0]['content'] ?? ''), 'min-height:56px'), 'phone carrier geometry keeps its media scope on the emitted marker');
$viewportHeights = array();
foreach (array(390, 768, 1440) as $viewport) {
    $asset = $viewport <= 600 ? $phoneAssets[0] : $desktopAssets[0];
    $viewportHeights[$viewport] = str_contains((string) ($asset['content'] ?? ''), 'min-height:' . ($viewport <= 600 ? '56px' : '102px'))
        ? ($viewport <= 600 ? 56 : 102)
        : 0;
}
$assert(array(390 => 56, 768 => 102, 1440 => 102) === $viewportHeights, 'header geometry contract covers phone, tablet, and desktop reference widths');
$assert(! str_contains($css, '[data-mesh-id="shared-header-carrier"]'), 'the emitted stylesheet does not retain a dead source-only selector');
$frontPage = array_values(array_filter($plan['templates'] ?? array(), static fn (array $candidate): bool => 'front-page' === ($candidate['slug'] ?? null)));
$template = (string) ($frontPage[0]['canonical_block_markup'] ?? '');
$assert(strpos($template, '"slug":"header"') < strpos($template, 'wp:post-content'), 'the emitted template places the shared header before the hero content');

echo "Shared empty data carrier geometry contract passed\n";
