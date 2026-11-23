<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/descendant-state-color.html');
$hover = '.contact a:hover { color: rgb(201, 169, 106); }';
$distinct = str_replace('rgb(250, 247, 242)', 'rgb(120, 210, 170)', $html);
foreach (array('control' => str_replace($hover, '', $html), 'descendant' => $html, 'distinct-control' => str_replace($hover, '', $distinct), 'distinct-descendant' => $distinct, 'own' => str_replace('.contact a:hover', '.contact:hover', $html)) as $case => $source) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray()['source_reports']['wordpress_site_plan'];
    $markup = $plan['pages'][0]['canonical_block_markup'];
    $assert(1 === preg_match('/<!-- wp:group (\{.*?\}) -->/', $markup, $match), 'The section remains a native group.');
    $attributes = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    if ('own' === $case) {
        // A preset class would mask the section's own hover; its `.contact`
        // rule renders the colour with that state, so no literal masks it either.
        $assert(!isset($attributes['textColor']) && !isset($attributes['style']['color']['text']), 'A state on the section itself still refuses binding and leaves the colour to its class.');
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
    if (str_starts_with($case, 'distinct-')) {
        $slug = $palette['#78d2aa'] ?? null;
        $assert(is_string($slug) && $slug === ($attributes['textColor'] ?? null), $case . ': distinct section text still binds to its exact preset.');
        $assert(!isset($attributes['style']['color']['text']), $case . ': bound text has no literal override.');
    } else {
    // This near-white shade is not an independent palette entry. A
    // descendant hover must neither block it nor change its paint: the
    // block names the shade's derived token, whose value restates it from
    // its representative preset, and the `.contact` rule keeps the literal.
    $shade = array_search($attributes['textColor'] ?? null, $palette, true);
    $assert(!isset($palette['#faf7f2']) && is_string($shade) && str_starts_with($shade, 'rgb(from var(--wp--preset--color--'), $case . ': near-white text binds to its derived near-shade token, not a nearby preset.');
    $assert(!isset($attributes['style']['color']['text']) && 1 === preg_match('/\.contact\b[^{,]*\{[^}]*color:\s*(?:rgb\(250, ?247, ?242\)|#faf7f2)/i', $css), $case . ': section keeps its exact local text color through its author class.');
    }
    $assert(0 === $plan['theme']['design_system']['metrics']['refusal_reasons']['state_variant'], $case . ': no unrelated state refusal.');
    if (str_ends_with($case, 'descendant')) $assert(str_contains($css, '.contact a:hover') && str_contains($css, '201, 169, 106'), 'The authored link hover color remains in the stylesheet.');
}
echo "Descendant state color contract passed.\n";
