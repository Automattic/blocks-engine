<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

// The real export includes pre-projected HTML AND raw component evidence.
$artifact = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/captured-menu-existing-navigation-artifact.json'), true, 512, JSON_THROW_ON_ERROR);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, array_values($pages));
$results = array(
    'compile' => $compiler->compile($artifact),
    'staged' => $compiler->compose($shared, array_values($receipts)),
);
$failures = array();
foreach ($results as $case => $result) {
    $plan = $result->toWordPressSitePlanView()['wordpress_site_plan'];
    $headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => 'header' === ($part['area'] ?? null)));
    if (1 !== count($headers)) $failures[] = $case . ': expected one header template part';
    $markup = (string) ($headers[0]['canonical_block_markup'] ?? '');
    $runtime = new Runtime();
    $blocks = $runtime->parseBlocks($markup);
    $navigation = array();
    $walk = static function (array $blocks) use (&$walk, &$navigation, &$failures, $case): void {
        foreach ($blocks as $block) {
            if ('core/navigation' === ($block['blockName'] ?? '')) $navigation[] = $block;
            if ('core/button' === ($block['blockName'] ?? '')) $failures[] = $case . ': source toggle became core/button';
            if (preg_match('/(?:display|visibility)[^!]*!important/i', (string) json_encode($block['attrs'] ?? array()))) $failures[] = $case . ': forced pose in block attributes';
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($blocks);
    // Core dynamically renders overlayMenu=mobile: hidden until opened on
    // phones, inline at desktop; no authored display override is needed.
    if (1 !== count($navigation) || 'mobile' !== ($navigation[0]['attrs']['overlayMenu'] ?? null)) $failures[] = $case . ': expected one responsive core/navigation';
    if (!str_contains($navigation[0]['attrs']['className'] ?? '', 'blocks-engine-native-responsive-navigation')) $failures[] = $case . ': expected native responsive navigation class';
    $items = $navigation[0]['innerBlocks'] ?? array();
    // Site plans materialize navigation children as a referenced menu entity.
    foreach ($plan['menus'] ?? array() as $menu) {
        if ('{{wordpress-site-plan:navigation:' . $menu['token'] . '}}' === ($navigation[0]['attrs']['ref'] ?? null)) {
            $items = $runtime->parseBlocks($menu['block_markup']);
            $walk($items);
        }
    }
    if (5 !== count($items)) $failures[] = $case . ': expected five native navigation links';
    foreach (array('The Stories', 'Approach', 'Collections', 'About', 'Get in touch ↗') as $index => $label) {
        if ('core/navigation-link' !== ($items[$index]['blockName'] ?? '') || $label !== ($items[$index]['attrs']['label'] ?? '')) $failures[] = $case . ': missing native link ' . $label;
    }
    foreach (array('data-blocks-engine-capture-dialog', 'data-blocks-engine-capture-dropdown', 'dialog-trigger') as $marker) {
        if (str_contains($markup, $marker)) $failures[] = $case . ': captured wiring survived: ' . $marker;
    }
    if (!$blocks || $runtime->serializeBlocks($blocks) !== $markup) $failures[] = $case . ': block markup does not round-trip';
}
foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
exit($failures ? 1 : 0);
