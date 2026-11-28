<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeBootstrap;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

/**
 * A form provider rebuilds a form from its readable blocks, so media inside
 * the form must reference published assets exactly as the page does. An icon
 * left at its page-relative path (`../media/clip.png`) resolves against each
 * route and 404s on the imported site.
 */

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$contact = '<!doctype html><html><head><title>Contact</title></head><body><main><h1>Contact</h1>'
    . '<form action="https://forms.example/submit" method="post"><label for="email">Email</label><input id="email" name="email" type="email" required>'
    . '<p class="attach"><img src="../media/clip.png" alt="" width="24" height="24">Attach files</p>'
    . '<button type="submit">Send</button></form></main></body></html>';

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><title>Home</title></head><body><main><h1>Home</h1><a href="contact/">Contact</a></main></body></html>',
    'contact/index.html' => $contact,
    'media/clip.png' => $png,
)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$assert(array() !== $plan, 'The site composes a plan: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? array()));

$readable = '';
foreach ($plan['quality']['fallbacks'] ?? array() as $fallback) {
    if ('html_form_fallback' === ($fallback['diagnostic_code'] ?? null)) $readable .= json_encode($fallback['readable_blocks'] ?? null, JSON_UNESCAPED_SLASHES);
}
$assert(str_contains($readable, 'Attach files'), 'The contact form fallback carries readable blocks with its icon row: ' . $readable);
$assert(! str_contains($readable, '../media/clip.png'), 'Readable blocks hold no page-relative media path: ' . $readable);
$assert(1 === preg_match('~\{\{wordpress-site-plan:asset:([a-z0-9-]+)\}\}~', $readable, $token), 'Readable blocks reference the icon through a declared token: ' . $readable);
$asset = array_values(array_filter($plan['assets'] ?? array(), static fn (array $asset): bool => ($asset['token'] ?? null) === $token[1]));
$assert(1 === count($asset) && 'media/clip.png' === $asset[0]['source_path'], 'The token names the icon asset: ' . json_encode($asset));
$resolved = WordPressSitePlanResolver::resolveMarkup($readable, WordPressSitePlanResolver::references($plan['reference_tokens'], 'https://example.test/theme'));
$assert((bool) preg_match('~src=\\\\?"https://example\.test/theme/[^"\\\\]*clip[^"\\\\]*\.png~', $resolved) && ! str_contains($resolved, WordPressSitePlan::TOKEN_PREFIX), 'The icon resolves to its published theme asset: ' . $resolved);

// An inline SVG icon in a form is kept as an image asset only in the form's
// readable blocks; no page, part or template references it. A consumer can
// publish a template from the page with the provider's form in it, so the
// theme's runtime map must still resolve that token.
$iconPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><title>Contact</title></head><body><main><h1>Contact</h1>'
        . '<form action="https://forms.example/submit" method="post"><label for="name">Name</label><input id="name" name="name">'
        . '<svg viewBox="0 0 16 16"><path d="M1 1l8 8 8-8"/></svg><button type="submit">Send</button></form></main></body></html>',
)))->toArray()['source_reports']['wordpress_site_plan'] ?? array();
$iconReadable = '';
foreach ($iconPlan['quality']['fallbacks'] ?? array() as $fallback) {
    if ('html_form_fallback' === ($fallback['diagnostic_code'] ?? null)) $iconReadable .= json_encode($fallback['readable_blocks'] ?? null, JSON_UNESCAPED_SLASHES);
}
$assert(1 === preg_match('~\{\{wordpress-site-plan:asset:([a-z0-9-]+)\}\}~', $iconReadable, $iconToken), 'The form icon is a declared asset in the readable blocks: ' . $iconReadable);
$documents = implode("\n", array_map(static fn (array $document): string => (string) ($document['canonical_block_markup'] ?? ''), array_merge($iconPlan['templates'], $iconPlan['template_parts'], $iconPlan['pages'])));
$assert(! str_contains($documents, $iconToken[0]), 'No plan document references the form icon, so only the readable blocks can cover it.');
$iconTarget = array_values(array_filter($iconPlan['reference_tokens'], static fn (array $token): bool => $iconToken[1] === ($token['token'] ?? null)))[0]['target_path'] ?? null;
$functions = array_values(array_filter($iconPlan['writes'], static fn (array $write): bool => 'functions.php' === $write['target_path']))[0]['payload']['data'] ?? '';
$assert(is_string($iconTarget) && str_contains($functions, var_export($iconToken[0], true) . ' => ' . var_export($iconTarget, true)), 'The theme runtime map resolves the form icon to its theme file: ' . $functions);
$assert(isset(ThemeBootstrap::templateAssetTokens($iconPlan['reference_tokens'], array_merge($iconPlan['templates'], $iconPlan['template_parts'], $iconPlan['pages']), $iconPlan['quality']['fallbacks'])[$iconToken[0]]), 'Consumers derive the same coverage from the plan.');
(new WordPressSitePlanResolver())->resolve($iconPlan, array('theme_uri' => 'https://example.test/theme'));

echo "form readable blocks assets contract passed\n";
