<?php
declare(strict_types=1);

// A url() inside a custom property resolves against the stylesheet that
// substitutes the var(), so it cannot be written relative to its own stylesheet.
require getenv('BLOCKS_ENGINE_TEST_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message, mixed $context = null): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n" . (null === $context ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"));
        exit(1);
    }
};
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<html><head><link rel="stylesheet" href="/vendor/theme/main.css"></head><body><main>'
        . '<div class="quote" style="--icon: url(\'/icons/quote.svg\'); background-color: #fff"></div>'
        . '<div class="hero" style="background-image: url(\'/icons/quote.svg\')"><p>Hero</p></div>'
        . '</main></body></html>',
    'vendor/theme/main.css' => '.quote{width:60px;height:60px;mask-image:var(--icon);-webkit-mask-image:var(--icon)}.hero{min-height:80px}',
    'icons/quote.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10z"/></svg>',
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($plan), 'The fixture compiles to a site plan.');
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/wp-content/themes/site'));
$css = implode("\n", array_map(static fn(array $write): string => (string) ($write['payload']['data'] ?? ''), array_filter($resolved['writes'], static fn(array $write): bool => str_ends_with((string) $write['target_path'], '.css') && 'utf8' === ($write['payload']['encoding'] ?? null))));
$assert(1 === preg_match('/--icon\s*:\s*url\((["\']?)([^"\')]+)\1\)/', $css, $icon), 'The custom property carrying the icon reaches a theme stylesheet.', $css);
$assert(str_starts_with($icon[2], '/wp-content/themes/site/') && str_ends_with($icon[2], 'quote.svg'), 'The custom-property URL is theme root-relative, so it resolves from whichever stylesheet consumes the var().', $icon[2]);
$assert(1 === preg_match('/background-image\s*:\s*url\((["\']?)([^"\')]+)\1\)/', $css, $background) && !str_starts_with($background[2], '/') && !str_contains($background[2], '://'), 'An ordinary url() stays stylesheet-relative, keeping the theme portable.', $background[2] ?? $css);
fwrite(STDOUT, "Custom property asset URL passed\n");
