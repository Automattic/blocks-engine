<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$flatten = static function (array $blocks) use (&$flatten): array {
    $result = array();
    foreach ($blocks as $block) { $result[] = $block; array_push($result, ...$flatten($block['innerBlocks'])); }
    return $result;
};
$runtime = new Runtime();
$cases = array(
    'issue-3993' => array('core/cover', '<div class="hero"><div class="inner"><h1>Hero heading</h1><p>Hero text paragraph.</p></div></div><div><p>After.</p></div>'),
    'cover-with-inner-image' => array('core/cover', '<div class="hero"><div class="inner"><h1>Hero heading</h1><p><img src="/media/inner.png" alt="Inner"></p></div></div>'),
    'image' => array('core/image', '<p><img src="/media/bg.jpg" alt="Image"></p>'),
    'media-text' => array('core/media-text', '<div style="display:flex"><img src="/media/bg.jpg" alt="Media"><div><h2>Heading</h2><p>Some text.</p></div></div>'),
);
$saveShapes = array();
foreach ($cases as $label => [$name, $body]) {
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Cover bg</title><style>.hero{background-image:url("/media/bg.jpg");height:90vh}</style></head><body>' . $body . '</body></html>';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $html,
        'media/bg.jpg' => array('content' => $png, 'mime_type' => 'image/jpeg'),
        'media/inner.png' => array('content' => $png, 'mime_type' => 'image/png'),
    )))->toArray()['source_reports']['wordpress_site_plan'];
    $asset = array_values(array_filter($plan['assets'], static fn(array $asset): bool => str_ends_with($asset['source_path'], 'media/bg.jpg')))[0];
    $resolved = (new WordPressSitePlanResolver())->resolve($plan, array('theme_uri' => 'https://example.test/theme', 'attachment_resolutions' => array($asset['token'] => array('id' => 41, 'url' => 'https://example.test/uploads/bg.jpg'))));
    $blocks = $flatten($runtime->parseBlocks($resolved['pages'][0]['resolved_block_markup']));
    $owners = array_values(array_filter($blocks, static fn(array $block): bool => $name === $block['blockName']));
    $assert(1 === count($owners), $label . ': expected one compiled ' . $name);
    $block = $owners[0];
    $assert(41 === ($block['attrs']['core/media-text' === $name ? 'mediaId' : 'id'] ?? null), $label . ': attachment ID is bound');
    $document = new DOMDocument();
    @$document->loadHTML($block['innerHTML']);
    $image = $document->getElementsByTagName('img')->item(0);
    $expected = 'core/cover' === $name ? 'wp-block-cover__image-background wp-image-41' : ('core/media-text' === $name ? 'wp-image-41 size-full' : 'wp-image-41');
    $assert($image instanceof DOMElement && $expected === $image->getAttribute('class'), $label . ': exact core image class contract');
    if ('cover-with-inner-image' === $label) {
        $inner = array_values(array_filter($blocks, static fn(array $block): bool => 'core/image' === $block['blockName']));
        $assert(1 === count($inner) && !str_contains($inner[0]['innerHTML'], 'wp-image-41'), 'Cover binding leaves inner-block images alone.');
    }
    if ('core/cover' === $name) $saveShapes[$label] = $block;
}
// Compare against snapshots produced by the existing Gutenberg save-shape harness.
$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/contract/site-plan-attachment-save-shapes.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($saveShapes as $label => $block) {
    $assert($fixture[$label]['attributes'] === $block['attrs'], $label . ': save snapshot attributes match the resolved attributes');
    $assert($fixture[$label]['inner_html'] === $block['innerHTML'], $label . ': resolved markup matches core save');
}
echo "site-plan-attachment-save-shapes contract passed\n";
