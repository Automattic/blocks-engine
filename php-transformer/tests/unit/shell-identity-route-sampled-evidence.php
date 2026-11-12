<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ChromeIdentity;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$cases = array(
    'responsive alias' => array('header', static fn(int $i, string $url): string => '<p class="brand' . (1 === $i ? ' data-blocks-engine-capture-responsive-class-123456abcdef' : '') . '">Acme</p>'),
    'self share links' => array('footer', static fn(int $i, string $url): string => '<p><a href="https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url) . '">Share</a></p><p><a href="https://twitter.com/intent/tweet?url=' . rawurlencode($url) . '">Tweet</a></p><p>' . $url . '</p>'),
    'sampled glyph paint' => array('footer', static fn(int $i, string $url): string => '<div class="social-links"><a href="https://www.facebook.com/acme" aria-label="Facebook"><svg' . (1 === $i ? ' data-blocks-engine-capture-glyph-color="#9aa470"' : '') . ' viewBox="0 0 24 24"><path d="M0 0h10v10z"/></svg></a></div>'),
    // Controls: these suspected producer differences do not reproduce in a
    // reduced artifact. Keep presets and asset tokens significant to identity.
    'equal font CSS' => array('header', static fn(int $i, string $url): string => '<p class="brand">Acme</p>'),
    'equal image source' => array('header', static fn(int $i, string $url): string => '<img src="/assets/logo.svg" alt="Acme">'),
);
foreach ($cases as $label => [$area, $shell]) {
    $files = array();
    foreach (array('index.html', 'about/index.html', 'work/index.html') as $i => $path) {
        $url = 'https://example.com/' . (0 === $i ? '' : dirname($path) . '/');
        $files[] = array('path' => 'website/' . $path, 'content' => '<html><head><title>Route ' . $i . '</title><link rel="stylesheet" href="/assets/site.css"></head><body><' . $area . ' class="site-' . $area . '">' . $shell($i, $url) . '</' . $area . '><main><h1>Route ' . $i . '</h1><p>Distinct page ' . $i . '</p></main></body></html>');
    }
    $files[] = array('path' => 'website/assets/site.css', 'content' => '.brand{font-family:Forum,cursive}');
    $files[] = array('path' => 'website/assets/logo.svg', 'content' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10z"/></svg>');
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'provenance' => array('source_url' => 'https://example.com/'), 'files' => $files))->toWordPressSitePlanView()['wordpress_site_plan'];
    $parts = array_values(array_filter($plan['template_parts'], static fn(array $part): bool => $area === $part['area']));
    $assert(1 === count($parts), $label . ': exactly one shared ' . $area);
    foreach ($plan['pages'] as $page) $assert(!str_contains($page['canonical_block_markup'], 'site-' . $area), $label . ': no inline shell on ' . $page['source_path']);
    if ('responsive alias' === $label) $assert(str_contains($parts[0]['canonical_block_markup'], 'data-blocks-engine-capture-responsive-class-123456abcdef'), 'Alias evidence survives in the shared part.');
    if ('sampled glyph paint' === $label) {
        $assert(str_contains($parts[0]['canonical_block_markup'], '#9aa470'), 'Captured paint survives in the shared part.');
        $authored = '<!-- wp:html --><svg style="fill:#123456"><path d="M0 0h10v10z"/></svg><!-- /wp:html -->';
        $assert(\Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ChromeIdentity::markup($authored) !== \Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ChromeIdentity::markup(str_replace('#123456', '#abcdef', $authored)), 'Authored paint still distinguishes shells.');
    }
    echo $label . ": shared\n";
}
echo "Shell identity route sampled evidence passed\n";
