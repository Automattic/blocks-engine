<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/descendant-state-color.html');
$hover = '.contact a:hover { color: rgb(201, 169, 106); }';
foreach (array('control' => str_replace($hover, '', $html), 'descendant' => $html, 'own' => str_replace('.contact a:hover', '.contact:hover', $html)) as $case => $source) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray()['source_reports']['wordpress_site_plan'];
    $markup = $plan['pages'][0]['canonical_block_markup'];
    $assert(1 === preg_match('/<!-- wp:group (\{.*?\}) -->/', $markup, $match), 'The section remains a native group.');
    $attributes = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    if ('own' === $case) {
        $assert(!isset($attributes['textColor']) && isset($attributes['style']['color']['text']), 'A state on the section itself still refuses binding.');
        continue;
    }
    $palette = array();
    $css = '';
    foreach ($plan['writes'] as $write) {
        if ('theme.json' === $write['target_path']) {
            $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
            $palette = array_column($theme['settings']['color']['palette'], 'slug', 'color');
        }
        if (str_ends_with($write['target_path'], '.css')) $css .= $write['payload']['data'];
    }
    $slug = $palette['#faf7f2'] ?? null;
    $assert(is_string($slug) && $slug === ($attributes['textColor'] ?? null), $case . ': section text binds to its exact palette preset.');
    $assert(!isset($attributes['style']['color']['text']), $case . ': section no longer retains literal text color.');
    $assert(0 === $plan['theme']['design_system']['metrics']['refusal_reasons']['state_variant'], $case . ': no unrelated state refusal.');
    if ('descendant' === $case) $assert(str_contains($css, '.contact a:hover') && str_contains($css, '201, 169, 106'), 'The authored link hover color remains in the stylesheet.');
}
echo "Descendant state color contract passed.\n";
