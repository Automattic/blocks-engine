<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body><main><h1>Title</h1><p>Text</p></main></body></html>',
        'style.css' => ':root{--ink:#212529;--paper:#fff}body{margin:0;color:var(--ink);background-color:var(--paper);font-family:Arial,sans-serif}.special{color:#123456}@media(max-width:600px){body{color:#abcdef}}',
    ),
))->sourceReports['wordpress_site_plan'];
$theme = array();
$css = '';
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true);
    if (str_ends_with($write['target_path'], '.css')) $css .= $write['payload']['data'];
}
if (!isset($theme['styles']['color']['background'])) throw new RuntimeException('Body background must have a Global Styles owner');
$backgrounds = 0;
$conditionalColors = 0;
(new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body, array $ancestors) use (&$backgrounds, &$conditionalColors): void {
    if ('body' !== trim($selector)) return;
    foreach (CssRuleAnalyzer::declarationList($body) as $declaration) {
        if ('background-color' === $declaration['name'] && array() === $ancestors) ++$backgrounds;
        if ('color' === $declaration['name'] && array() !== $ancestors) ++$conditionalColors;
    }
});
if (0 !== $backgrounds) throw new RuntimeException('Authored body background must yield to matching Global Styles');
if (0 === $conditionalColors) throw new RuntimeException('Conditional body colors must remain source-owned');
// Exercise the same public compiler without a conditional text colour: both
// body longhands must yield, including the editor's document-context copy.
$plainPlan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body><p class="card">Text</p><p class="shared">Shared paint</p></body></html>',
        'style.css' => 'body,.shared{color:#212529;background-color:#fff;margin:0}.card{color:#212529;background-color:#fff}body:hover{color:#abcdef}',
    ),
))->sourceReports['wordpress_site_plan'];
$plainCss = '';
$plainTheme = array();
foreach ($plainPlan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $plainTheme = json_decode($write['payload']['data'], true);
    if (str_ends_with($write['target_path'], '.css')) $plainCss .= $write['payload']['data'];
}
foreach (array('text', 'background') as $key) {
    if (!isset($plainTheme['styles']['color'][$key])) throw new RuntimeException('Both body colours need emitted Global Styles defaults');
}
$cards = 0;
$states = 0;
$shared = 0;
(new CssStylesheetTransformer())->visitStyleRules($plainCss, static function (string $selector, string $body) use (&$cards, &$states, &$shared): void {
    if ('body' === trim($selector) || str_contains($selector, 'body.editor-styles-wrapper')) {
        foreach (CssRuleAnalyzer::declarationList($body) as $declaration) {
            if (in_array($declaration['name'], array('color', 'background-color'), true)) throw new RuntimeException('Frontend and editor body paint must yield together');
        }
    }
    // A repeated declaration block ships once, so one rule can carry several
    // of these selectors in its list.
    $arms = array_map('trim', explode(',', $selector));
    if (in_array('.shared', $arms, true) && str_contains($body, 'color:') && str_contains($body, 'background-color:')) ++$shared;
    if (in_array('.card', $arms, true) && str_contains($body, 'color:')) ++$cards;
    if (in_array('body:hover', $arms, true) && str_contains($body, '#abcdef')) ++$states;
});
if (0 === $cards || 0 === $states || 0 === $shared) throw new RuntimeException('Component, shared-selector and state colours must remain source-owned');
fwrite(STDOUT, "Body live colors passed\n");
