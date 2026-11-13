<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RestyleCoverage;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Reduced page from #5949: retained author rules and lowered control rules.
$css = 'body{margin:0;font-family:sans-serif;background:#fff;color:#1e293b}.bar{--tw-border-opacity:1;border-bottom:4px solid rgb(200 32 50/var(--tw-border-opacity,1));padding:16px}.hero{background:#073b83;color:#fff;padding:24px}.cta{background:#c82032;color:#fff;padding:8px 16px;display:inline-block}.card{--tw-border-opacity:1;border:1px solid #e2e8f0;border-top:4px solid rgb(7 59 131/var(--tw-border-opacity,1));padding:24px;margin:24px}.outline{--tw-text-opacity:1;color:rgb(7 59 131/var(--tw-text-opacity,1));border:2px solid #073b83;padding:8px 16px;display:inline-block}';
$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Tokens</title><style>' . $css . '</style></head><body><div class="bar"><p>Top bar</p></div><section class="hero"><h1>Heading</h1><a class="cta" href="#a">Primary action</a></section><div class="card"><h3>Card title</h3><p>Card text.</p><a class="outline" href="#b">Outline action</a></div></body></html>';
$compile = static function (string $html): array {
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
};
$check = static function (array $plan) use ($assert): string {
    $palette = array();
    foreach ($plan['writes'] as $write) {
        if ('theme.json' !== $write['target_path']) continue;
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $palette = array_column($theme['settings']['color']['palette'], 'slug', 'color');
    }
    $assert(isset($palette['#c82032'], $palette['#073b83']), 'The issue colours are palette entries.');
    $emitted = implode("\n", array_column($plan['assets'], 'content'));
    // Remove balanced preset calls, including nested Tailwind opacity fallbacks.
    $outside = preg_replace('/var\(--wp--preset--color--[a-z0-9-]+,(?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/i', 'PRESET', $emitted);
    preg_match_all('/border(?:-(?:top|right|bottom|left))?(?:-color)?\s*:[^;}]*/i', $outside, $declarations);
    foreach ($declarations[0] as $declaration) {
        $assert(!preg_match('/#c82032|#073b83|rgb\(\s*200\s+32\s+50|rgb\(\s*7\s+59\s+131/i', $declaration), 'No palette literal outside a preset fallback: ' . $declaration);
    }
    return $emitted;
};
$emitted = $check($compile($html));
$assert(str_contains($emitted, ',rgb(200 32 50/var(--tw-border-opacity,1)))'), 'Tailwind author border preserves its exact rendering fallback.');
$assert(str_contains($emitted, ',#073b83)'), 'Control border preserves its exact rendering fallback.');

// Exercise every physical side and multi-colour longhands through the same path.
$extra = '';
foreach (array('top', 'right', 'bottom', 'left') as $side) {
    $extra .= '.bar:hover{border-' . $side . ':3px dashed #073b83;border-' . $side . '-color:#c82032!important}';
}
$extra .= '.bar:focus{border-color:#073b83 #c82032 #073b83 #c82032}';
$check($compile(str_replace('</style>', $extra . '</style>', $html)));

$markup = '<!-- wp:group {"className":"edge"} --><div></div><!-- /wp:group -->';
foreach (array('#073b83' => false, 'var(--wp--preset--color--secondary,#073b83)' => true) as $color => $preset) {
    $coverage = RestyleCoverage::measure(array($markup), array(array('kind' => 'css', 'content' => '.edge{border:2px solid ' . $color . '}')), false);
    $assert(1 === $coverage['borders'] && (int) $preset === $coverage['borders_preset'] && (float) (int) $preset === $coverage['share'], 'Border colours contribute to restyle coverage.');
}
$coverage = RestyleCoverage::measure(array('<!-- wp:group {"style":{"border":{"top":{"color":"var:preset|color|secondary"}}}} /-->'), array(), false);
$assert(1 === $coverage['borders_preset'], 'Block-owned side border presets contribute to coverage.');

echo "Border palette binding contract passed.\n";
