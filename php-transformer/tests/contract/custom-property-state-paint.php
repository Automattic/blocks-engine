<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fixtures = array();
foreach (array(false, true) as $chain) {
    $css = '.btn{--bg:var(--btn-bg);--btn-bg:#cacaca;--btn-bg-hover:'
        . ($chain ? 'var(--hover-default)' : '#bdbdbd') . ';background-color:var(--bg);padding:8px 16px}'
        . ($chain ? ':root{--hover-default:var(--hover-flat);--hover-flat:var(--tint);--tint:#bdbdbd}' : '')
        . '.btn:not(:disabled):hover{--bg:var(--btn-bg-hover)}'
        . ($chain ? '.btn:not(:disabled):focus{--bg:var(--btn-bg-hover)}.btn:not(:disabled):active{--bg:var(--btn-bg-hover)}' : '');
    $source = '<!doctype html><html><head><style>' . $css . '</style></head><body><a class="btn" href="/x">Go</a></body></html>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    $projected = implode("\n", array_column(array_filter($plan['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
    foreach ($chain ? array('hover', 'focus', 'active') : array('hover') as $state) {
        if (!preg_match('/wp-block-button__link[^{}]*:not\(:disabled\):' . $state . '\{--bg:var\(--btn-bg-hover\)\}/', $projected)) {
            throw new RuntimeException('State-only custom property rule must reach the lowered link: ' . $state);
        }
    }
    foreach ($chain ? array('--btn-bg-hover', '--hover-default', '--hover-flat', '--tint') : array('--btn-bg-hover') as $property) {
        if (!str_contains($projected, $property . ':')) throw new RuntimeException('Missing live custom property: ' . $property);
    }
    $fixtures[] = array('source' => $source, 'native' => '<!doctype html><style>' . $projected . '</style>' . $plan['pages'][0]['canonical_block_markup'], 'chain' => $chain);
}
if ($path = getenv('CUSTOM_PROPERTY_STATE_ARTIFACT')) file_put_contents($path, json_encode($fixtures, JSON_THROW_ON_ERROR));
echo "Custom-property state paint contracts passed.\n";
