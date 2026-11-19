<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Captured body box rules use :root body, and need not contain typography.
// Both frontend and editor copies must defer matching paint to Global Styles.
$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<!doctype html><html><head><style>body{color:#15281f;background-color:#faf8f5}:root body{color:#15281f;background-color:#faf8f5;margin:0;padding:24px}.card{color:#15281f;background-color:#faf8f5}@media(max-width:600px){:root body{color:#abcdef}}</style></head><body><p class="card">Body copy</p></body></html>'),
))->sourceReports['wordpress_site_plan'];
$theme = array();
$css = '';
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
    if (str_ends_with($write['target_path'], '.css')) $css .= $write['payload']['data'];
}
foreach (array('text', 'background') as $key) {
    if (!isset($theme['styles']['color'][$key])) throw new RuntimeException('Body paint needs a Global Styles owner');
}
$layout = false;
$component = false;
$conditional = false;
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body, array $ancestors) use (&$layout, &$component, &$conditional): void {
    foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array($selector) as $arm) {
        if (preg_match('/^(?::root\s+)?body(?:\.editor-styles-wrapper)?$/', trim($arm))) {
            foreach (CssRuleAnalyzer::declarationList($body) as $declaration) {
                if (in_array($declaration['name'], array('color', 'background-color'), true)) {
                    if (array() === $ancestors) throw new RuntimeException('Root body paint must yield to Global Styles: ' . $body);
                    $conditional = true;
                }
                if ('padding' === $declaration['name'] && '24px' === $declaration['value']) $layout = true;
            }
        }
        if ('.card' === trim($arm) && str_contains($body, 'color:')) $component = true;
    }
});
if (!$layout || !$component || !$conditional) throw new RuntimeException('Body layout, component paint and conditional paint must remain source-owned');
echo "Root body live colors passed\n";
