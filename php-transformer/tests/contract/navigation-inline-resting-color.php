<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/navigation-inline-resting-color.html');
foreach (array('hover' => $html, 'control' => str_replace(' hover:text-primary', '', $html)) as $case => $source) {
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray()['source_reports']['wordpress_site_plan'];
    $css = '';
    $markup = '';
    $palette = array();
    foreach ($plan['writes'] as $write) {
        if (str_ends_with($write['target_path'], '.css')) $css .= $write['payload']['data'];
        if (str_ends_with($write['target_path'], '.html')) $markup .= $write['payload']['data'];
        if ('theme.json' === $write['target_path']) {
            $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
            $palette = array_column($theme['settings']['color']['palette'], 'slug', 'color');
        }
    }
    $slug = $palette['#f7ebde'] ?? null;
    $assert(is_string($slug), $case . ': the resting colour is the light palette colour, not the footer background.');
    if ('control' === $case) {
        $assert(1 === preg_match('/<!-- wp:navigation (\{.*?\}) \/?-->/', $markup, $match), 'The footer has a native navigation block.');
        $attrs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $assert($slug === ($attrs['textColor'] ?? null), 'Without hover the navigation retains its preset textColor.');
        continue;
    }
    // Check the emitted render surface, not just the preserved source metadata:
    // Core puts the marker on LI and the content class on the rendered anchor.
    $menus = implode('', array_column($plan['menus'], 'block_markup'));
    $assert(3 === preg_match_all('/<!-- wp:navigation-link (\{.*?\}) \/-->/', $menus, $matches), 'All three footer links remain native navigation links.');
    foreach ($matches[1] as $json) {
        $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $assert(1 === preg_match('/(?:^|\s)(blocks-engine-navigation-link-color-[a-f0-9]+)(?:\s|$)/', $attrs['className'], $marker), 'Each rendered item carries its resting-colour rule marker.');
        $selector = '.wp-block-navigation .wp-block-navigation-item.' . $marker[1] . ' .wp-block-navigation-item__content:not(:hover)';
        $assert(str_contains($css, $selector . '{color:var(--wp--preset--color--' . $slug . ',hsl(30 60% 92%))}'), 'The rendered anchor receives the resolved resting colour outside hover.');
    }
    $assert(1 === preg_match('/wp-block-navigation-item__content\):hover\{color:hsl\(var\(--primary\)\)\}/', $css), 'The authored primary hover colour still reaches the rendered anchor.');
}
echo "Navigation inline resting colour contract passed.\n";
