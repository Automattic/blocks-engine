<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Diagnostics\RenderStyleMismatchReportBuilder;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_render_style_mismatch_contract(callable $assert): void
{
    $report = ( new RenderStyleMismatchReportBuilder() )->build(
        array(
            'node_style_diagnostics' => array(
                array(
                    'node' => array('id' => 'text:1', 'name' => 'Hero title', 'type' => 'TEXT'),
                    'expected' => array(
                        'font_family' => 'Inter',
                        'font_size' => '48px',
                        'font_weight' => '700',
                        'text_color' => '#1a334d',
                    ),
                    'emitted' => array(
                        'font_family' => 'Inter',
                        'font_size' => '48px',
                        'font_weight' => '700',
                        'text_color' => '#1a334d',
                    ),
                ),
            ),
        ),
        array(
            'schema' => 'homeboy/static-artifact-render-evidence/v1',
            'elements' => array(
                array(
                    'data-figma-node-id' => 'text:1',
                    'computed_style' => array(
                        'font-family' => 'Arial, sans-serif',
                        'font-size' => '48px',
                        'font-weight' => '700',
                        'color' => 'rgb(255, 0, 0)',
                    ),
                ),
            ),
        )
    );

    $codes = array_map(static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''), $report['diagnostics'] ?? array());
    $categories = array_map(static fn (array $diagnostic): string => (string) ($diagnostic['category'] ?? ''), $report['diagnostics'] ?? array());
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : array();

    $assert('blocks-engine/figma-transformer/render-style-mismatch-report/v1' === ($report['schema'] ?? null), 'render-style-mismatch-schema');
    $assert('fail' === ($report['status'] ?? null), 'render-style-mismatch-fail-status');
    $assert(in_array('render_style_mismatch', $codes, true), 'render-style-mismatch-code');
    $assert(in_array('font', $categories, true), 'render-style-font-mismatch-category');
    $assert(in_array('color', $categories, true), 'render-style-color-mismatch-category');
    $assert(1 === ($summary['font_mismatch_count'] ?? null), 'render-style-font-mismatch-count');
    $assert(1 === ($summary['color_mismatch_count'] ?? null), 'render-style-color-mismatch-count');
    $assert(1 === ($summary['matched_node_count'] ?? null), 'render-style-matched-node-count');
    $assert(1.0 === ($summary['match_ratio'] ?? null), 'render-style-match-ratio');

    $unitlessLineHeightReport = ( new RenderStyleMismatchReportBuilder() )->build(
        array(
            'node_style_diagnostics' => array(
                array(
                    'node' => array('id' => 'text:line-height', 'name' => 'Line height text', 'type' => 'TEXT'),
                    'expected' => array('font_size' => '16px', 'line_height' => '1.5'),
                    'emitted' => array('font_size' => '16px', 'line_height' => '1.5'),
                ),
            ),
        ),
        array(
            'schema' => 'homeboy/static-artifact-render-evidence/v1',
            'elements' => array(
                array(
                    'node_id' => 'text:line-height',
                    'computed_style' => array('font-size' => '16px', 'line-height' => '24px'),
                ),
            ),
        )
    );
    $unitlessLineHeightSummary = is_array($unitlessLineHeightReport['summary'] ?? null) ? $unitlessLineHeightReport['summary'] : array();
    $assert('pass' === ($unitlessLineHeightReport['status'] ?? null), 'render-style-unitless-line-height-pass');
    $assert(0 === ($unitlessLineHeightSummary['text_metric_mismatch_count'] ?? null), 'render-style-unitless-line-height-no-text-metric-mismatch');

    $pageScopedReport = ( new RenderStyleMismatchReportBuilder() )->build(
        array(
            'node_style_diagnostics' => array(
                array(
                    'node' => array('id' => 'shared:text', 'name' => 'Shared text', 'type' => 'TEXT'),
                    'expected' => array('text_color' => '#000000'),
                    'emitted' => array('text_color' => '#000000'),
                ),
            ),
        ),
        array('entrypoints' => array(
            array(
                'page_path' => 'index.html',
                'elements' => array(
                    array('node_id' => 'shared:text', 'computed_style' => array('color' => 'rgb(0, 0, 0)')),
                ),
            ),
            array(
                'page_path' => 'about.html',
                'elements' => array(
                    array('node_id' => 'shared:text', 'computed_style' => array('color' => 'rgb(255, 0, 0)')),
                ),
            ),
        )),
        array('page_path' => 'about.html')
    );
    $pageScopedSummary = is_array($pageScopedReport['summary'] ?? null) ? $pageScopedReport['summary'] : array();
    $assert('about.html' === ($pageScopedSummary['page_path'] ?? null), 'render-style-page-scope-summary-path');
    $assert(1 === ($pageScopedSummary['render_node_count'] ?? null), 'render-style-page-scope-render-count');
    $assert(1 === ($pageScopedSummary['color_mismatch_count'] ?? null), 'render-style-page-scope-selected-page-color');
}
