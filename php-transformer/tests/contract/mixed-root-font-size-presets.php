<?php
declare(strict_types=1);

// Font-size presets are one site-wide value, but a rem value resolves against
// the root of whichever document renders it. When documents render different
// roots, a preset spelled in rem under one of them paints a different size on
// the others (15px at a 10px root became 1.5rem, 24px on 16px-root pages).
// Spacing steps follow the same rule, and a rem value is matched to a preset
// at the root of the page that renders it, not at the site's majority root.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$result = (new ArtifactCompiler())->compile(require dirname(__DIR__) . '/fixtures/mixed-root-font-size.php')->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$theme = array();
foreach ($plan['writes'] ?? array() as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$presets = array_column($theme['settings']['typography']['fontSizes'] ?? array(), 'size', 'slug');
$spacing = array_column($theme['settings']['spacing']['spacingSizes'] ?? array(), 'size', 'slug');
$markup = array_column($plan['pages'] ?? array(), 'canonical_block_markup', 'source_path');
$css = implode('', array_column(array_filter($plan['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));

$computed = static function (string $length, float $root): ?float {
    if (1 !== preg_match('/^(\d*\.?\d+)(px|rem)$/', trim($length), $m)) return null;
    return 'rem' === $m[2] ? (float) $m[1] * $root : (float) $m[1];
};

// Each page's text rule, the root that page renders, and the size it paints.
foreach (array('home' => array('/\.blocks-engine-source-class-[a-z0-9-]+\{font-size:var\(--wp--preset--font-size--([a-z0-9-]+),15px\)/', 16.0), 'about' => array('/\.about\{font-size:var\(--wp--preset--font-size--([a-z0-9-]+),1\.5rem\)/', 10.0)) as $page => [$pattern, $root]) {
    if (1 !== preg_match($pattern, $css, $match)) $fail("$page: text rule binds a font-size preset");
    $px = $computed($presets[$match[1]] ?? '', $root);
    if (null === $px || abs($px - 15.0) > 0.01) $fail("$page: preset {$match[1]} paints 15px at a {$root}px root (" . json_encode($presets) . ')');
}

// A rem rule and a rem block size on the 16px page paint 24px there.
if (1 !== preg_match('/\.lead\{font-size:var\(--wp--preset--font-size--([a-z0-9-]+),1\.5rem\)/', $css, $match)) $fail('home: lead rule binds a font-size preset');
if (abs(($computed($presets[$match[1]] ?? '', 16.0) ?? 0.0) - 24.0) > 0.01) $fail("home: lead preset {$match[1]} paints 24px at a 16px root (" . json_encode($presets) . ')');
$home = (string) ($markup['index.html'] ?? '');
if (1 !== preg_match('/<!-- wp:paragraph \{"fontSize":"([a-z0-9-]+)"\} -->\s*<p class="has-[a-z0-9-]+-font-size">An inline paragraph/', $home, $match)) $fail('home: inline rem paragraph binds a font-size preset');
if (abs(($computed($presets[$match[1]] ?? '', 16.0) ?? 0.0) - 24.0) > 0.01) $fail("home: inline preset {$match[1]} paints 24px at a 16px root (" . json_encode($presets) . ')');

// Both pages pad their boxes 15px; the spacing step they bind paints 15px at each root.
foreach (array('index.html' => 16.0, 'about/index.html' => 10.0) as $source => $root) {
    if (1 !== preg_match('/"padding":\{"top":"var:preset\|spacing\|([a-z0-9-]+)"/', (string) ($markup[$source] ?? ''), $match)) $fail("$source: box padding binds a spacing preset");
    $px = $computed($spacing[$match[1]] ?? '', $root);
    if (null === $px || abs($px - 15.0) > 0.01) $fail("$source: spacing preset {$match[1]} paints 15px at a {$root}px root (" . json_encode($spacing) . ')');
}

fwrite(STDOUT, "Mixed root font-size presets passed\n");
