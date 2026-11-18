<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$compile = static function (string $script = ''): string {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="paint">Hello</p></main>' . $script . '</body></html>',
        'other.html' => '<!doctype html><html><head><link rel="stylesheet" href="other.css"></head><body><p class="other">Other</p></body></html>',
        'site.css' => '*,:before,:after,::backdrop{--unused-reset:12345px;--used-reset:0px;--reset-compound:0 0 #0000}*,.paint{--mixed-component-api:12px}:root{--unused-inventory:12345px;--shared-paint:teal;--fallback-paint:purple;--indirect-paint:var(--fallback-paint);--font-api:italic 700 18px/1.6 Example,serif;--wp--preset--spacing--40:19px;--be--source--preset--spacing--40:23px}.paint{--component-api:42px;color:var(--shared-paint);margin-left:var(--used-reset)}',
        'other.css' => '.other{background-color:var(--indirect-paint)}',
    )))->toArray();
    return implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
};
$css = $compile('<script>document.documentElement.style.setProperty("transition", "none")</script>');
foreach (array('--unused-inventory', '--unused-reset', '--reset-compound', '--font-api') as $name) {
    if (str_contains($css, $name)) throw new RuntimeException('Unused framework property inventory must not ship: ' . $name);
}
foreach (array('--shared-paint', '--fallback-paint', '--indirect-paint') as $name) {
    if (!str_contains($css, $name . ':')) throw new RuntimeException('Cross-route variable dependencies must survive: ' . $name);
}
foreach (array('--used-reset:0px', '--mixed-component-api:12px', '--component-api:42px', '--wp--preset--spacing--40:19px', '--be--source--preset--spacing--40:23px') as $declaration) {
    if (!str_contains($css, $declaration)) throw new RuntimeException('Authored component and preset APIs must survive: ' . $declaration);
}
$runtime = $compile('<script>document.documentElement.style.getPropertyValue("--" + location.hash.slice(1))</script>');
if (!str_contains($runtime, '--unused-inventory')) throw new RuntimeException('Dynamic property readers must retain inventory');
echo "Unused custom properties are pruned with whole-site evidence\n";
