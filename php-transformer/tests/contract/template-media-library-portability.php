<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
$page = static fn(string $title): string => '<!doctype html><html><body><header><img src="media/logo.png" alt="Logo"></header><main><h1>' . $title . '</h1><img src="media/logo.png" alt="Content"></main><footer><img src="media/logo.png" alt="Logo"></footer></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $page('Home'), 'about.html' => $page('About'),
    'media/logo.png' => array('content' => $png, 'mime_type' => 'image/png'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$asset = current(array_filter($plan['assets'], static fn(array $asset): bool => 'media_library' === $asset['destination']));
$assert(is_array($asset) && count($plan['template_parts']) >= 2, 'The reduced shared header/footer case declares a Media Library image and shared parts.');
$token = WordPressSitePlan::TOKEN_PREFIX . $asset['token'] . '}}';
$resolved = (new WordPressSitePlanResolver())->resolve($plan, array(
    'theme_uri' => 'https://build.example.test/wp-content/themes/site',
    'attachment_resolutions' => array($asset['token'] => array('id' => 41, 'url' => 'https://build.example.test/wp-content/uploads/logo.png')),
));
$writes = array_column($resolved['writes'], null, 'target_path');
$checked = 0;
foreach ($writes as $path => $write) {
    if (!str_starts_with($path, 'parts/') && !str_starts_with($path, 'templates/')) continue;
    $markup = $write['payload']['data'];
    $assert(!preg_match('~https?://~', $markup), 'Theme block files must not bake a scheme/host: ' . $path);
    if (!str_contains($markup, $token)) continue;
    ++$checked;
    $assert(str_contains($markup, '"id":41') && str_contains($markup, 'wp-image-41'), 'Portable theme images retain their Media Library binding.');
}
$assert($checked >= 2, 'Header and footer keep portable tokens.');
// Exercise template writes directly too, including the no-attachment fallback.
$canonical = '<!-- wp:image --><figure class="wp-block-image"><img src="' . $token . '" alt="Logo"/></figure><!-- /wp:image -->';
$template = WordPressSitePlanResolver::resolveWritePayload($canonical, $plan['reference_tokens'], $resolved['resolution']['theme_uri'], 'templates/custom.html', $resolved['resolution']['attachment_resolutions']);
$assert(str_contains($template, $token) && str_contains($template, '"id":41') && !str_contains($template, 'https://'), 'Template writes also retain tokens and attachment bindings.');
$assert($canonical === WordPressSitePlanResolver::resolveWritePayload($canonical, $plan['reference_tokens'], $resolved['resolution']['theme_uri'], 'templates/custom.html'), 'Unresolved templates remain byte-identical.');
$assert(str_contains($resolved['pages'][0]['resolved_block_markup'], 'https://build.example.test/wp-content/uploads/logo.png') && str_contains($resolved['pages'][0]['resolved_block_markup'], 'wp-image-41'), 'Post content still resolves to the attachment URL.');

// Run the generated runtime against a different host, as after a site move.
function remove_action(...$args): void {}
function add_action(...$args): void {}
function add_filter(...$args): void {}
function get_theme_file_uri(string $path): string { return 'https://moved.example.test/wp-content/themes/site/' . $path; }
function wp_get_attachment_url(int $id): string|false { return 41 === $id ? 'https://moved.example.test/wp-content/uploads/logo.png' : false; }
eval(substr($writes['functions.php']['payload']['data'], 5));
$rendered = $blocks_engine_resolve_template_assets($template);
$assert(str_contains($rendered, 'https://moved.example.test/wp-content/uploads/logo.png') && !str_contains($rendered, $token), 'Generated runtime resolves the portable token to the current attachment URL.');
WordPressSitePlan::assertValid($resolved);

// A Media Library image that only a synced pattern holds is not a template asset (#6523).
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
$canvas = '<div class="canvas"><img src="map.svg" alt="Map"><p>Region</p></div>';
$tabsPlan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><body><main><img src="logo.svg" alt="Logo"><div class="tabs"><div role="tablist"><button role="tab" aria-controls="p1" aria-selected="true">One</button><button role="tab" aria-controls="p2" aria-selected="false">Two</button></div><div id="p1" role="tabpanel">' . $canvas . '<p>First panel</p></div><div id="p2" role="tabpanel" hidden>' . $canvas . '<p>Second panel</p></div></div></main></body></html>',
    'logo.svg' => array('content' => $svg, 'mime_type' => 'image/svg+xml'),
    'map.svg' => array('content' => $svg, 'mime_type' => 'image/svg+xml'),
)))->toArray()['source_reports']['wordpress_site_plan'];
$tabTokens = array_column(array_filter($tabsPlan['assets'], static fn(array $asset): bool => 'media_library' === $asset['destination']), 'token', 'source_path');
$assert(isset($tabTokens['logo.svg'], $tabTokens['map.svg']), 'Both images become Media Library assets.');
$logoToken = WordPressSitePlan::TOKEN_PREFIX . $tabTokens['logo.svg'] . '}}';
$mapToken = WordPressSitePlan::TOKEN_PREFIX . $tabTokens['map.svg'] . '}}';
$tabsResolved = (new WordPressSitePlanResolver())->resolve($tabsPlan, array(
    'theme_uri' => 'https://build.example.test/wp-content/themes/site',
    'attachment_resolutions' => array(
        $tabTokens['logo.svg'] => array('id' => 61, 'url' => 'https://build.example.test/wp-content/uploads/logo.svg'),
        $tabTokens['map.svg'] => array('id' => 62, 'url' => 'https://build.example.test/wp-content/uploads/map.svg'),
    ),
));
$mapPatterns = array_values(array_filter($tabsResolved['synced_patterns'] ?? array(), static fn(array $pattern): bool => str_contains($pattern['canonical_block_markup'], $mapToken)));
$assert(1 === count($mapPatterns), 'The repeated tab canvas is projected as a synced pattern holding the map token.');
$functions = array_column($tabsResolved['writes'], null, 'target_path')['functions.php']['payload']['data'];
$assert(1 === preg_match('~\$blocks_engine_template_asset_tokens = (array \(.*?\));\n~s', $functions, $tokenTable) && 1 === preg_match('~\$blocks_engine_template_attachment_ids = (array \(.*?\));\n~s', $functions, $idTable), 'functions.php declares the template token and attachment-ID tables.');
$templateTokens = eval('return ' . $tokenTable[1] . ';');
$attachmentIds = eval('return ' . $idTable[1] . ';');
preg_match_all('~\{\{wordpress-site-plan:asset:[^}]+\}\}~', $idTable[1], $idTokens);
$assert(array() === array_diff($idTokens[0], array_keys($templateTokens)), 'functions.php publishes attachment IDs only for tokens its template asset table lists.');
$assert(!isset($attachmentIds[$mapToken]), 'The synced-pattern-only map token gets no template attachment ID.');
$assert(61 === ($attachmentIds[$logoToken] ?? null), 'The template logo token keeps its attachment ID.');
$assert(str_contains($mapPatterns[0]['resolved_block_markup'], 'https://build.example.test/wp-content/uploads/map.svg') && str_contains($mapPatterns[0]['resolved_block_markup'], 'wp-image-62'), 'The synced pattern still resolves the map image to its attachment.');
WordPressSitePlan::assertValid($tabsResolved);
echo "template-media-library-portability contract passed\n";
