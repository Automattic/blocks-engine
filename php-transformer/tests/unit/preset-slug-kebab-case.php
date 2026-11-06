<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Mirror _wp_to_kebab_case: acronym/camel boundaries, then letter/digit
// boundaries, then separators and lowercase (WordPress strings.php).
$kebab = static function (string $value): string {
    $value = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $value);
    $value = (string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $value);
    $value = (string) preg_replace('/([a-zA-Z])([0-9])/', '$1-$2', $value);
    $value = (string) preg_replace('/([0-9])([a-zA-Z])/', '$1-$2', $value);
    return strtolower((string) preg_replace('/[\s_]+/', '-', $value));
};
$failures = array();
foreach (array('YAFPtjs8JGg_0' => 'yafptjs-8-jgg-0', 'Inter 2' => 'inter-2', 'Roboto' => 'roboto') as $name => $expected) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => '<html><head><style>body{font-family:Arial}h1{font-family:Georgia}.sample{font-family:"' . $name . '"}</style></head><body><h1>Heading</h1><p>Body copy</p><p class="sample">Sample family</p></body></html>'),
    ))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'];
    $theme = array();
    foreach ($plan['writes'] as $write) if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    $families = $theme['settings']['typography']['fontFamilies'];
    $slug = null;
    foreach ($families as $family) {
        if ($family['slug'] !== $kebab($family['slug'])) $failures[] = $name . ': preset is not a kebab-case fixed point: ' . $family['slug'];
        if ($name === $family['name']) $slug = $family['slug'];
    }
    if ($expected !== $slug) $failures[] = $name . ': expected ' . $expected . ', got ' . ($slug ?? '(missing)');
    $markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
    if (null === $slug || !str_contains($markup, 'has-' . $kebab($slug) . '-font-family')) $failures[] = $name . ': serialized class does not match the WordPress preset rule';
    if (null === $slug || !str_contains($markup, '"fontFamily":"' . $slug . '"')) $failures[] = $name . ': serialized attribute does not reference its preset';
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit(array() === $failures ? 0 : 1);
