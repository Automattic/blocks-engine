<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A url() in an inline <style> is relative to the page that held it, not to the
// generated inline/ stylesheet it is published as. An upload packaged under a
// wrapper folder must still resolve the image to its imported file
// (Automattic/blocks-engine#4747), with no placeholder and no missing-media warning.
// The painted element is not a cover, so the url stays in the inline stylesheet.
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$page = '<html><head><style>.hero{min-height:480px;background:linear-gradient(180deg,rgba(20,20,22,.15) 0%,rgba(20,20,22,.78) 100%),url("images/coffee-bar.jpg") center/cover no-repeat}</style></head>'
    . '<body><main><h1>Coffee</h1><p>Fresh beans.</p><footer class="hero"><p>Visit us.</p></footer></main></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        array('path' => 'website/index.html', 'content' => $page),
        array('path' => 'website/images/coffee-bar.jpg', 'content' => base64_decode('R0lGODlhAQABAAAAACw=')),
    ),
))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$assert(!str_contains(json_encode($result), 'missing_local_media'), 'The packaged image raises no missing-media diagnostic.');
$image = array_values(array_filter($plan['assets'], static fn(array $asset): bool => str_ends_with($asset['target_path'], 'coffee-bar.jpg')))[0];
$css = array_values(array_filter($plan['writes'], static fn(array $write): bool => str_contains($write['target_path'], 'assets/inline/')))[0]['payload'];
$assert(str_contains(json_encode($css), $image['token']), 'The inline stylesheet points at the packaged image: ' . json_encode($css));
echo "ok\n";
