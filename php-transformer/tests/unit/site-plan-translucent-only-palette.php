<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body><main><section class="surface"><h1>Title</h1><p>Readable copy</p><div class="transparent">Transparent child</div></section><section class="opaque"><p>Opaque sibling</p></section></main></body></html>',
        'style.css' => 'body{color:#fff;background:#fff}.surface{background-color:rgba(47,23,15,.9);padding:40px}.transparent{background:rgba(17,34,51,0)}.opaque{background:#38516b;padding:40px}',
    ),
))->sourceReports['wordpress_site_plan'];
$theme = array();
$markup = implode('\n', array_column($plan['pages'], 'canonical_block_markup'));
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true);
    if (str_ends_with($write['target_path'], '.html')) $markup .= $write['payload']['data'];
}
$palette = array_column($theme['settings']['color']['palette'] ?? array(), 'color', 'slug');
$base = array_search('#2f170f', $palette, true);
if (false === $base) throw new RuntimeException('A colour painted only translucently needs an exact palette base');
if (count(array_filter($palette, static fn(string $color): bool => '#2f170f' === $color)) !== 1) {
    throw new RuntimeException('The translucent surface must share one editable opaque base');
}
$tint = $base . '-alpha-90';
if (($palette[$tint] ?? null) !== 'color-mix(in srgb, var(--wp--preset--color--' . $base . ') 90%, transparent)') {
    throw new RuntimeException('The tint must retain its alpha and follow its editable base');
}
if (!str_contains($markup, '"backgroundColor":"' . $tint . '"')) throw new RuntimeException('Surface must bind through the emitted tint preset');
if (str_contains($markup, '"background":"rgba(47')) throw new RuntimeException('Surface must not keep a literal background');
if (str_contains($markup, '"backgroundColor":"' . $base . '"')) {
    throw new RuntimeException('The opaque base must never replace translucent surface paint');
}
$opaque = array_search('#38516b', $palette, true);
if (false === $opaque || !str_contains($markup, '"backgroundColor":"' . $opaque . '"')) {
    throw new RuntimeException('Opaque sibling paint must retain its own exact preset');
}
if (in_array('#112233', $palette, true)) throw new RuntimeException('Fully transparent paint must not consume a palette slot');
fwrite(STDOUT, "Translucent-only palette passed\n");
