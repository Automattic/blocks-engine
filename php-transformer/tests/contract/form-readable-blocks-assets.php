<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
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

echo "form readable blocks assets contract passed\n";
