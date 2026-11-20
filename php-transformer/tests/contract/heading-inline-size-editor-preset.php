<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$plan = (new ArtifactCompiler())->compile(require __DIR__ . '/../fixtures/design-system/heading-inline-size.php')->toArray()['source_reports']['wordpress_site_plan'];
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
if (!preg_match('/<!-- wp:heading (\{.*?\}) -->/', $markup, $match)) throw new RuntimeException('The heading remains native.');
$attributes = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
$size = $attributes['style']['typography']['fontSize'] ?? '';
// Core useFontSize resolves a named fontSize attribute, or passes the custom
// style value through to React's inline style. The internal var:preset syntax
// is not CSS and is discarded there, exposing the author's font shorthand.
if (!preg_match('/^var\(--wp--preset--font-size--([^)]+)\)$/', $size, $preset)) throw new RuntimeException('The editor needs a CSS variable for the custom inline size: ' . $size);
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$sizes = array_column($theme['settings']['typography']['fontSizes'], 'size', 'slug');
if ('50px' !== ($sizes[$preset[1]] ?? null)) throw new RuntimeException('The preset retains exactly 50px.');
if (!str_contains($markup, 'font-size:' . $size)) throw new RuntimeException('Frontend and editor use the same inline size.');
if (isset($attributes['fontSize'])) throw new RuntimeException('An important size class must not mask the authored hover size.');
echo "Heading inline size editor preset passed.\n";
