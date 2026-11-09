<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$css = 'html{font-size:16px}body{font-family:Arial,sans-serif;font-size:16px}.buttons-component.style-1>a{font-size:16px;font-weight:400;display:inline-flex;text-decoration:none;line-height:1.875rem;padding:0 1.5rem;border-radius:0.5rem;background-color:#F05E3E;color:#FFFFFF;font-family:Arial,sans-serif;padding-bottom:0.9375rem;padding-top:0.9375rem}';
$files = array();
foreach (array('index.html', 'thanks.html') as $path) {
    $files[$path] = '<!doctype html><html><head><style>' . $css . '</style></head><body><div class="buttons-component style-1"><a href="/thanks">Continue</a><a href="/other">Other</a><a href="/more">More</a></div><p>' . $path . '</p></body></html>';
}
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $files))->toArray()['source_reports']['wordpress_site_plan'];
$theme = array();
$stylesheets = '';
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
foreach ($plan['writes'] as $write) {
    $path = $write['target_path'];
    $data = $write['payload']['data'];
    if ('theme.json' === $path) $theme = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    elseif (str_ends_with($path, '.css')) $stylesheets .= $data;
    elseif (str_ends_with($path, '.html')) $markup .= $data;
}
// A stale shorthand must not defeat the element style after block attributes yield.
preg_match_all('/[^{}]*wp-block-button__link[^{}]*\{([^{}]*)\}/', $stylesheets, $rules);
foreach ($rules[1] as $body) {
    if (!preg_match('/(?:^|;)\\s*padding:\\s*0 1\\.5rem\\s*(?:;|$)/', $body)) continue;
    foreach (array('top', 'bottom') as $side) {
        if (!preg_match('/padding-' . $side . ':\\s*0\\.9375rem/', $body)) throw new RuntimeException('Site CSS stripped a shorthand override: ' . $side);
    }
}
if (!str_contains($markup, 'wp:button')) throw new RuntimeException('Reduction must emit native buttons.');
$padding = $theme['styles']['elements']['button']['spacing']['padding'] ?? null;
$scale = array_column($theme['settings']['spacing']['spacingSizes'] ?? array(), 'size', 'slug');
if (!is_array($padding)) throw new RuntimeException('Shared resolved button padding must reach Global Styles.');
foreach (array('top' => '0.9375rem', 'right' => '1.5rem', 'bottom' => '0.9375rem', 'left' => '1.5rem') as $side => $expected) {
    $value = $padding[$side] ?? '';
    if (str_starts_with($value, 'var:preset|spacing|')) $value = $scale[substr($value, strlen('var:preset|spacing|'))] ?? '';
    if ($expected !== $value) throw new RuntimeException('Incorrect resolved Global Styles padding: ' . $side);
}
// Background shorthand and longhand must also travel together: this is an
// ownership rule, not padding-specific repair.
foreach (array(
    'padding:0 1.5rem;padding-top:0.9375rem;padding-bottom:0.9375rem;background:red;background-color:#F05E3E',
    'padding:0 1.5rem;padding-top:0.9375rem;padding-bottom:0.9375rem;border:1px solid red;border-radius:0.5rem',
) as $declarations) {
    $variantFiles = array();
    foreach ($files as $path => $html) {
        $variantFiles[$path] = str_replace('padding-bottom:0.9375rem;padding-top:0.9375rem', $declarations, $html);
    }
    $variant = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $variantFiles))->toArray()['source_reports']['wordpress_site_plan'];
    $cssOutput = '';
    foreach ($variant['writes'] as $write) if (str_ends_with($write['target_path'], '.css')) $cssOutput .= $write['payload']['data'];
    // The author block remains an ordered unit whenever its family cannot
    // yield completely; no component is independently subtracted.
    preg_match_all('/[^{}]*wp-block-button__link[^{}]*\\{([^{}]*)\\}/', $cssOutput, $variantRules);
    foreach ($variantRules[1] as $body) {
        if (str_contains($body, 'background:red') && !str_contains($body, 'background-color:')) throw new RuntimeException('Background reset lost its override.');
        if (str_contains($body, 'border:1px solid red') && !str_contains($body, 'border-radius:0.5rem')) throw new RuntimeException('Partial border ownership must retain the complete authored family.');
    }
}
echo "site button shorthand overrides: ok\n";
