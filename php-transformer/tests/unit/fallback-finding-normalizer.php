<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\FallbackFindingNormalizer;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( ! $condition ) {
        fwrite(STDERR, $message . ('' !== $detail ? "\n{$detail}" : '') . "\n");
        exit(1);
    }
};

$fallback = FallbackFindingNormalizer::normalize(array(
    'diagnostic_code' => 'html_unsupported_element',
    'tag'             => 'applet',
    'selector'        => 'main > section.card:nth-of-type(2) [data-role="demo"] > applet#legacy',
    'context'         => array(
        'parent_tag'    => 'section',
        'ancestor_tags' => array('main', 'article'),
    ),
));

$assert('unsupported_applet' === ($fallback['pattern_family'] ?? ''), 'unsupported fallback normalizes to tag-specific family');
$assert('add_generic_pattern_recognizer' === ($fallback['suggested_generic_repair_class'] ?? ''), 'unsupported fallback maps to generic recognizer repair class');
$assert('inside_section' === ($fallback['parent_reason'] ?? ''), 'parent context is projected as a reason');
$assert('within_main_article' === ($fallback['ancestor_reason'] ?? ''), 'ancestor context is projected as a reason');
$assert('1,3,3' === ($fallback['source_selector_specificity']['score'] ?? ''), 'selector specificity is preserved in the normalized payload');

$runtimeIsland = FallbackFindingNormalizer::normalize(array(
    'code'       => 'preserved_runtime_island',
    'kind'       => 'dom',
    'tag'        => 'span',
    'attributes' => array(
        'data-state' => 'active',
    ),
));

$assert('inline_semantic_html' === ($runtimeIsland['pattern_family'] ?? ''), 'semantic inline runtime islands get their own family');
$assert('preserve_runtime_island' === ($runtimeIsland['suggested_generic_repair_class'] ?? ''), 'semantic inline runtime islands preserve runtime repair guidance');

$iframeGap = FallbackFindingNormalizer::normalize(array(
    'diagnostic_code' => 'html_iframe_surface_capability_gap',
    'reason'          => 'source_runtime_only_iframe',
    'tag'             => 'vendor-iframe',
    'suggested_repair_class' => 'record_iframe_capability_gap',
));
$assert('iframe_surface' === ($iframeGap['pattern_family'] ?? ''), 'custom iframe capability gaps cluster as iframe surfaces');
$assert('record_iframe_capability_gap' === ($iframeGap['suggested_generic_repair_class'] ?? ''), 'custom iframe capability gaps keep their repair class');

echo "fallback-finding-normalizer ok\n";
