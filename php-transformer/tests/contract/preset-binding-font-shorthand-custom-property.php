<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$stack = 'Example Light,Example,sans-serif';
$html = '<!doctype html><html><head><style>:root{--t:normal normal normal 18px/1.6em ' . $stack
    . ';--spaced:italic  700 18px / 1.6em ' . $stack . '!important'
    . ';--near:18px Example Light,serif;--unmatched:italic 700 21px / 1.4 Unknown Face,serif;--c:47,46,46;--sh:0 0 2px #2f2e2e}'
    . '.copy{font:var(--t);color:rgb(var(--c))}</style></head><body><main>'
    . str_repeat('<p class="copy">Body copy with a typography token.</p>', 6) . '</main></body></html>';
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$css = '';
$families = $palette = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) {
        $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
        $families = array_column($theme['settings']['typography']['fontFamilies'], 'fontFamily', 'slug');
        $palette = array_column($theme['settings']['color']['palette'], 'color', 'slug');
    }
    if (str_ends_with($write['target_path'], '.css')) $css .= $write['payload']['data'];
}
$slug = array_search($stack, $families, true);
$colorSlug = array_search('#2f2e2e', $palette, true);
$assert(is_string($slug), 'The rendered literal stack has an exactly matching preset.');
$assert(is_string($colorSlug), 'The rendered colour has an exactly matching preset.');
// Print the relevant emitted declarations as before/after evidence, even on failure.
preg_match_all('/--(?:t|unmatched):[^;}]+|color:var\(--wp--preset--color--[^;}]+/', $css, $definitions);
echo implode("\n", array_unique($definitions[0])) . "\n";
$assert(str_contains($css, '--unmatched:italic 700 21px / 1.4 Unknown Face,serif'), 'An unmatched shorthand stays literal.');
$assert(str_contains($css, '--near:18px Example Light,serif'), 'Sharing only a primary family is not an exact stack match.');
$assert(str_contains($css, '--spaced:italic  700 18px / 1.6em var(--wp--preset--font-family--' . $slug . ')!important'), 'Style, weight, size, line-height, spacing and priority survive family binding.');
$assert(str_contains($css, 'color:var(--wp--preset--color--' . $colorSlug . ',rgb(var(--c)))'), 'Colour variable binding retains its original fallback.');
$assert(str_contains($css, '--sh:0 0 2px var(--wp--preset--color--' . $colorSlug . ',#2f2e2e)'), 'A non-font custom property keeps its colour token binding.');
$assert(str_contains($css, '--t:normal normal normal 18px/1.6em var(--wp--preset--font-family--' . $slug . ')'), 'Only the matching shorthand family is bound in place.');
echo "Font shorthand custom property binding contract passed.\n";
