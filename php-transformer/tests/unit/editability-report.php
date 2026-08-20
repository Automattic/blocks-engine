<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;

$blocks = array(array(
    'blockName' => 'core/group',
    'attrs' => array('className' => 'blocks-engine-source-div-a be-inline-geometry-b'),
    'innerBlocks' => array(
        array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => ''),
        array('blockName' => 'core/paragraph', 'attrs' => array('content' => 'Editable <strong>copy</strong>'), 'innerBlocks' => array(), 'innerHTML' => '<p>Editable <strong>copy</strong></p>'),
        array('blockName' => 'core/table', 'attrs' => array('body' => array(array('cells' => array(array('content' => '<div><img src="image.jpg"></div>'))))), 'innerBlocks' => array(), 'innerHTML' => '<figure></figure>'),
    ),
    'innerHTML' => '<div></div>',
));

$report = (new EditabilityReport())->fromBlocks($blocks, 'website/index.html', str_repeat('x', 120));
$metrics = $report['metrics'];
if (EditabilityReport::SCHEMA !== $report['schema'] || 'report_only' !== $report['enforcement']) throw new RuntimeException('Editability report must be versioned and observational.');
if (4 !== $metrics['block_count'] || 2 !== $metrics['wrapper_block_count'] || 1 !== $metrics['empty_wrapper_count'] || 2 !== $metrics['max_nesting_depth']) throw new RuntimeException('Editability report must measure block-tree complexity deterministically.');
if (1 !== $metrics['html_bearing_table_cell_count'] || 1 !== $metrics['source_marker_class_count'] || 1 !== $metrics['generated_geometry_class_count'] || 120 !== $metrics['serialized_bytes']) throw new RuntimeException('Editability report must expose opaque HTML and generated-class signals.');
if (1 !== $metrics['html_bearing_attribute_count']) throw new RuntimeException('Supported RichText inline markup must not be classified as opaque structural HTML.');
if (2 !== count($report['signals']) || 'empty_wrapper' !== $report['signals'][0]['kind'] || 'html_bearing_table_cell' !== $report['signals'][1]['kind']) throw new RuntimeException('Editability signals must retain deterministic source and block attribution.');

$aggregate = (new EditabilityReport())->fromDocuments(array('b.html' => array('blocks' => $blocks, 'serialized_blocks' => 'bb'), 'a.html' => array('blocks' => array(), 'serialized_blocks' => 'a')));
if (2 !== $aggregate['metrics']['document_count'] || 3 !== $aggregate['metrics']['serialized_bytes'] || 'a.html' !== $aggregate['documents'][0]['source_path']) throw new RuntimeException('Artifact editability reports must aggregate documents in stable source order.');

$emptyGroups = array_fill(0, 101, array('blockName' => 'core/group', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => ''));
$bounded = (new EditabilityReport())->fromDocuments(array('large.html' => array('blocks' => $emptyGroups)));
if (101 !== $bounded['signal_totals']['observed'] || 100 !== $bounded['signal_totals']['reported'] || 1 !== $bounded['signal_totals']['omitted'] || !$bounded['signal_totals']['truncated'] || 100 !== count($bounded['signals'])) throw new RuntimeException('Editability reports must bound evidence without losing aggregate signal totals.');

$structuralRichText = '<div><h3>Card title</h3><p>Card copy</p></div>';
$richTextReport = (new EditabilityReport())->fromBlocks(array(array(
    'blockName' => 'core/list-item',
    'attrs' => array('content' => $structuralRichText),
    'innerBlocks' => array(),
    'innerHTML' => '<li>' . $structuralRichText . '</li>',
)));
if (1 !== $richTextReport['metrics']['structural_rich_text_attribute_count'] || strlen($structuralRichText) !== $richTextReport['metrics']['structural_rich_text_attribute_bytes']) throw new RuntimeException('Editability reports must quantify structural HTML stored in RichText attributes.');
if ('structural_rich_text_attribute' !== ($richTextReport['signals'][0]['kind'] ?? null) || 'core/list-item' !== ($richTextReport['signals'][0]['block_name'] ?? null)) throw new RuntimeException('Structural RichText evidence must retain block attribution.');

fwrite(STDOUT, "editability report contract passed\n");
