<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\CustomBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\ResponsiveLayoutBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\SvgArtworkBlockGenerator;

$source = '<figure><a href="/photo"><picture><source media="(min-width:800px)" srcset="wide.jpg 1200w"><img src="photo.jpg" alt="Photo"></picture></a><figcaption>Photo</figcaption></figure>';
$result = (new HtmlTransformer())->transform($source)->toArray();
$definitions = $result['source_reports']['generated_blocks'];
$media = array_values(array_filter($definitions, static fn(array $definition): bool => 'custom/responsive-media' === $definition['block_json']['name']));
if (1 !== count($media) || 'raw_html' !== ($media[0]['block_json']['blocksEngine']['editingSurface'] ?? null)) {
    throw new RuntimeException('Linked responsive figures must generate a companion declaring its raw HTML editing surface.');
}
$report = $result['source_reports']['editability_report'];
$signals = array_filter($report['signals'], static fn(array $signal): bool => 'raw_html_block' === $signal['kind'] && 'custom/responsive-media' === $signal['block_name']);
if ($report['metrics']['raw_html_block_count'] < 1 || array() === $signals) {
    throw new RuntimeException('Generated raw HTML companions must count and carry named raw_html_block signals.');
}
$plain = (new HtmlTransformer())->transform('<p>Editable copy.</p>')->toArray();
if (0 !== $plain['source_reports']['editability_report']['metrics']['raw_html_block_count']) {
    throw new RuntimeException('Plain paragraphs must not count as raw HTML.');
}

// Every audited raw-markup editor opts in, even when its name has no known suffix.
$metadata = array(
    (new CustomBlockGenerator())->blockJson('example/arbitrary', 'Custom'),
    (new ResponsiveLayoutBlockGenerator())->blockJson('example'),
    (new SvgArtworkBlockGenerator())->blockJson('example'),
);
foreach ($metadata as $json) {
    if ('raw_html' !== ($json['blocksEngine']['editingSurface'] ?? null)) throw new RuntimeException('Raw markup editors share the declaration.');
    $nested = array(array('blockName' => 'core/group', 'innerBlocks' => array(array('blockName' => $json['name']))));
    $declared = (new EditabilityReport())->fromBlocks($nested, generatedBlocks: array(array('block_json' => $json)));
    $undeclared = (new EditabilityReport())->fromBlocks($nested);
    if (1 !== $declared['metrics']['raw_html_block_count'] || 0 !== $undeclared['metrics']['raw_html_block_count']) throw new RuntimeException('Nested companion counting uses declarations, not name guesses.');
}

echo "Raw HTML companion editability tests passed.\n";
