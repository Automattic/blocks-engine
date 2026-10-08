<?php
declare(strict_types=1);

/** Distinct landmark/list boxes and responsive item/anchor rules keep their owners. */
require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$source = '<style>'
    . 'body{margin:0;font:14px/22px Arial}nav{display:block}'
    . '.masthead{height:60px}.band{min-height:40px}'
    . '.landmark{display:block;text-align:right}.menu-links{display:inline-block;list-style:none;margin:0;padding:0}'
    . '.menu-links>li{display:inline-block;line-height:20px}'
    . '.menu-links>li>a{display:block;padding:10px 15px 11px;color:#123456;text-decoration:none}'
    . '.band .menu-links>li>a{font-size:88%}'
    . '@media(max-width:700px){.band{min-height:0}.landmark{height:0;overflow:hidden}.band .menu-links>li>a{font-size:100%;padding:10px 20px}.menu-links>li{display:block}}'
    . '@media(min-width:1200px){.menu-links>li{line-height:24px}.band .menu-links>li>a{font-size:100%}}'
    . '@media(min-width:701px) and (max-width:1199px){.band .menu-links>li>a{font-size:90%}.band .menu-links>li>a{font-size:88%}}'
    . '.menu-links>li>a{font-size:76%}'
    . '.band-tail{display:none}.following{height:30px}'
    . '</style><header class="masthead">Neutral masthead</header>'
    . '<div class="band"><nav class="landmark" aria-label="Primary"><ul class="menu-links">'
    . '<li><a href="/alpha">Alpha</a></li><li><a href="/beta">Beta</a></li><li><a href="/gamma">Gamma</a></li>'
    . '</ul></nav><hr class="band-tail"></div><section class="following">Following content</section>';
if (in_array('--conditional-layout', $argv, true)) {
    // Both boxes are block at the compiler's reference viewport; only the
    // compact list is inline. Ownership cannot be decided from one viewport.
    $source = str_replace('.menu-links{display:inline-block;', '.menu-links{display:block;', $source);
    $source = str_replace('@media(max-width:700px){', '@media(max-width:700px){.menu-links{display:inline-block}', $source);
}
if (in_array('--default-landmark', $argv, true)) {
    $source = str_replace(array('nav{display:block}', '.landmark{display:block;'), array('', '.landmark{'), $source);
}
if (in_array('--cross-family-cascade', $argv, true) || in_array('--cross-family-order', $argv, true)) {
    $source = preg_replace('/font-size:[^;}]+;?/', '', $source) ?? $source;
    $competitors = in_array('--cross-family-order', $argv, true)
        ? 'nav.landmark a{font-size:19px}.menu-links>li>a{font-size:20px}'
        : '.menu-links>li>a{font-size:100%}.landmark .menu-links a{font-size:20px}';
    $source = str_replace('</style>', $competitors . '</style>', $source);
}
$result = (new HtmlTransformer())->transform($source)->toArray();
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray()['source_reports']['compiled_site'];
$result['serialized_blocks'] = $compiled['pages'][0]['block_markup'];
$result['blocks'] = (new Runtime())->parseBlocks($result['serialized_blocks']);
$result['assets'] = $compiled['assets'];
if (in_array('--fixture', $argv, true)) {
    echo json_encode(array('source' => $source, 'result' => $result), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

$passes = 0;
$failures = 0;
$assert = static function (bool $ok, string $message) use (&$passes, &$failures): void {
    if ($ok) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};
$all = array();
$walk = static function (array $blocks) use (&$walk, &$all): void {
    foreach ($blocks as $block) { $all[] = $block; $walk($block['innerBlocks'] ?? array()); }
};
$walk($result['blocks']);
$nav = array_values(array_filter($all, static fn(array $b): bool => 'core/navigation' === $b['blockName']))[0] ?? array();
$links = array_values(array_filter($all, static fn(array $b): bool => 'core/navigation-link' === $b['blockName']));
$assert(3 === count($links), 'all links remain native and editable');
$assert(1 === preg_match('/<nav class="[^"]*\blandmark\b[^\"]*"/', $result['serialized_blocks']), 'the source landmark retains a distinct native carrier, including coalesced native shells');
$assert(in_array('menu-links', explode(' ', $nav['attrs']['className'] ?? ''), true) && !in_array('landmark', explode(' ', $nav['attrs']['className'] ?? ''), true), 'the native navigation owns the list without borrowing landmark classes');
foreach ($links as $link) {
    $assert(1 === preg_match('/blocks-engine-source-li-[a-z0-9-]+/', $link['attrs']['className'] ?? ''), 'native item retains its source li identity');
}
$css = implode("\n", array_map(static fn(array $a): string => 'css' === ($a['kind'] ?? '') ? ($a['content'] ?? '') : '', $result['assets']));
$assert(!preg_match('/\.wp-block-navigation[^{}]*__content\{[^{}]*font-size:88%/', $css), 'authored percentage typography is not duplicated as an unconditional recovery rule');
$assert(0 === ($result['metrics']['fallback_count'] ?? -1), 'the ownership repair requires no opaque fallback');
if ($failures) { fwrite(STDERR, "navigation source correspondence: {$passes} passed, {$failures} FAILED\n"); exit(1); }
echo "navigation source correspondence: {$passes} passed\n";
