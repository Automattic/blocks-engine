<?php
declare(strict_types=1);

/**
 * Source-owned root padding must survive WordPress's default root padding.
 *
 * WordPress's own theme.json sets `styles.spacing.padding` to 0px on every
 * side and prints it as `body{padding-top:0px;…}` in the global styles
 * stylesheet, after the source stylesheet and at the same specificity as an
 * authored `body` rule. A body padding the projection leaves source-owned (a
 * shorthand with a responsive override, here) must therefore be paired with
 * theme.json sides that replace core's default and print nothing.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$compile = static function (string $html): array {
    $plan = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html', 'files' => array('index.html' => $html),
    ))->toArray()['source_reports']['wordpress_site_plan'];
    $theme = null;
    foreach ($plan['writes'] as $write) {
        if ('theme.json' === ($write['target_path'] ?? '')) $theme = json_decode($write['payload']['data'], true);
    }
    if (!is_array($theme)) throw new RuntimeException('Compiled theme.json is present');
    return array($theme, implode("\n", array_column($plan['assets'], 'content')));
};
// Core merges theme data over its own defaults with array_replace_recursive()
// (WP_Theme_JSON::merge()) and prints each root padding side whose value is
// not empty (WP_Theme_JSON::compute_style_properties()).
$coreRootPadding = static function (array $theme): array {
    $core = array('styles' => array('spacing' => array('padding' => array('top' => '0px', 'right' => '0px', 'bottom' => '0px', 'left' => '0px'))));
    $padding = array_replace_recursive($core, json_decode((string) json_encode($theme), true))['styles']['spacing']['padding'];
    if (!is_array($padding)) return '' === $padding ? array() : array('padding' => $padding);
    return array_filter($padding, static fn(mixed $value): bool => !(empty($value) && !is_numeric($value)));
};

$source = (string) file_get_contents(dirname(__DIR__) . '/fixtures/source-owned-root-padding.html');
[$theme, $css] = $compile($source);
$assert(1 === preg_match('/body\s*\{\s*padding:\s*13vh 2em 9vh/', $css), 'The source body padding stays in the delivered CSS: ' . $css);
$assert(1 === preg_match('/@media\s*\(max-width:\s*640px\)\s*\{\s*body\s*\{\s*padding:\s*2\.5em 1\.25em 4em/', $css), 'The responsive body padding stays in the delivered CSS');
$assert(array() === $coreRootPadding($theme), 'WordPress prints no root padding over the source-owned body padding: ' . json_encode($theme['styles']['spacing'] ?? null));

// A representable unconditional body padding is still projected into Global
// Styles, where it is the root padding and core prints it instead.
[$projected] = $compile(str_replace(array('body { padding: 13vh 2em 9vh; }', '@media (max-width: 640px) { body { padding: 2.5em 1.25em 4em; } }'), array('body { padding: 24px; }', ''), $source));
$assert(array('padding' => $projected['styles']['spacing']['padding'] ?? null) === $coreRootPadding($projected) && is_string($projected['styles']['spacing']['padding'] ?? null), 'A projected body padding remains the root padding: ' . json_encode($projected['styles']['spacing'] ?? null));

echo "source-owned-root-padding: ok\n";
