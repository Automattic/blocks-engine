<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$image = static fn(string $path): array => array('path' => $path, 'kind' => 'image', 'mime_type' => 'image/png', 'content_base64' => base64_encode("\x89PNG\r\n\x1a\n" . $path));
$bootstrap = static function (string $main, array $files = array()) use ($assert): array {
    $html = '<!doctype html><html lang="en"><head><title>Shop</title></head><body><header><p>Example Shop</p></header><main>' . $main . '</main><footer><p>Example Shop</p></footer></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array_merge(array(array('path' => 'index.html', 'content' => $html)), $files)))->toArray();
    $assert('failed' !== ($result['status'] ?? 'failed'), 'The page compiles.');
    $plan = $result['source_reports']['wordpress_site_plan'];
    WordPressSitePlan::assertValid($plan);
    $writes = array_column($plan['writes'], null, 'target_path');
    return array($plan, (string) ($writes['functions.php']['payload']['data'] ?? ''));
};

// Shared chrome without images, page content with theme images: a consumer
// that publishes a theme template from the page's canonical markup (a store's
// product archive) keeps the page's asset tokens, so the bootstrap must carry
// the runtime resolver for them.
$cards = '';
foreach (array('Mug', 'Bowl') as $index => $name) {
    $cards .= '<li><h2>' . $name . '</h2><span class="price">' . (8 + $index) . '.50</span><img src="media/' . strtolower($name) . '.png" alt="' . $name . '" width="400" height="400"></li>';
}
[$plan, $functions] = $bootstrap('<h1>Shop</h1><ul>' . $cards . '</ul>', array($image('media/mug.png'), $image('media/bowl.png')));
$targets = array_column($plan['reference_tokens'], 'target_path', 'source_path');
$tokens = array_column($plan['reference_tokens'], 'token', 'source_path');
$assert(isset($tokens['media/mug.png']) && str_contains(implode('', array_column($plan['pages'], 'canonical_block_markup')), WordPressSitePlan::TOKEN_PREFIX . $tokens['media/mug.png'] . '}}'), 'The page keeps the photo as an asset token.');
$assert(str_contains($functions, '$blocks_engine_template_asset_tokens'), 'Page asset tokens publish the template-asset resolver.');
$assert(str_contains($functions, var_export(WordPressSitePlan::TOKEN_PREFIX . $tokens['media/mug.png'] . '}}', true) . ' => ' . var_export($targets['media/mug.png'], true)), 'The resolver maps the page photo token to its theme file.');

// A plan whose documents carry no asset token keeps a resolver-free bootstrap.
[, $functions] = $bootstrap('<h1>About</h1><p>Plain text.</p>');
$assert(!str_contains($functions, '$blocks_engine_template_asset_tokens'), 'A plan without asset tokens emits no template-asset resolver.');

echo "page template asset resolver: ok\n";
