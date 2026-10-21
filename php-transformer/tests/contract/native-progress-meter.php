<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/parity/html-native-progress-meter.json'), true, 512, JSON_THROW_ON_ERROR);
$check = static function (string $html) use ($assert): array {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $assert(array() === $result['fallbacks'], 'Native indicators have no unsupported content loss.');
    $assert(!str_contains($result['serialized_blocks'], '<!-- wp:html'), 'Native indicators never use core/html.');
    $assert('pass' === $result['source_reports']['wp_block_validity']['status'], 'Indicator blocks serialize as valid editor-loadable blocks.');
    return $result;
};
$result = $check($fixture['input']['content']);
$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$xpath = new DOMXPath($document);
$assert(1 === $xpath->query('//label[@for="p1"]')->length, 'The external upload label still targets the indicator.');
$assert(1 === $xpath->query('//progress[@id="p1" and @value="40" and @max="100" and text()="40%"]')->length, 'The upload bar retains its native 40/100 fill and fallback text.');
$assert(1 === $xpath->query('//progress[@class="buffer" and @min="0" and @max="100" and @value="0" and @role="progressbar" and @aria-hidden="true" and text()="% buffered"]')->length, 'The buffer bar retains zero fill, range, role, ARIA and fallback text.');
$assert(str_contains(implode("\n", array_column($result['assets'], 'content')), 'progress{width:200px;height:6px}'), 'Matched authored bar dimensions survive in the rendered stylesheet.');

$meter = $check('<main><meter id="capacity" min="0" max="100" value="40" low="20" high="80" optimum="50" aria-label="Capacity" aria-valuetext="40 percent" style="width:200px;height:6px">40% &amp; remaining</meter><progress max="10">Waiting</progress></main>');
$document = new DOMDocument();
@$document->loadHTML($meter['serialized_blocks']);
$xpath = new DOMXPath($document);
$assert(1 === $xpath->query('//meter[@value="40" and @min="0" and @max="100" and @low="20" and @high="80" and @optimum="50" and @aria-label="Capacity" and @aria-valuetext="40 percent" and @style="width:200px;height:6px" and text()="40% & remaining"]')->length, 'The sibling meter retains native thresholds, ARIA, inline size and escaped fallback text.');
$assert(1 === $xpath->query('//progress[not(@value) and @max="10" and text()="Waiting"]')->length, 'An absent value stays absent so indeterminate progress stays indeterminate.');

echo "Native progress/meter contract passed.\n";
