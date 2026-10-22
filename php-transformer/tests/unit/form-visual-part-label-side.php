<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$icon = '<svg viewBox="0 0 10 10"><path d="M0 0L10 10"/></svg>';
$document = new DOMDocument();
$document->loadHTML('<form>
    <button> ' . $icon . '<span>Send</span></button>
    <button> <span>Send</span>' . $icon . '</button>
    <button><span>' . $icon . '</span> Send <span>' . $icon . '</span></button>
    <button> ' . $icon . ' </button>
</form>', LIBXML_NOERROR | LIBXML_NOWARNING);
$builder = new FormPresentationGraphBuilder(
    sanitizeInlineSvgMarkup: static fn (DOMElement $svg): string => $svg->ownerDocument->saveXML($svg)
);
$graph = $builder->build($document->getElementsByTagName('form')->item(0), array());
$expected = array('before', 'after', 'before', 'after', 'before');
$actual = array_map(static fn (array $part): mixed => $part['label_side'] ?? null, $graph['visual_parts']);
if ($expected !== $actual) {
    fwrite(STDERR, 'FAIL: visual parts record their side of the first label text: ' . json_encode($actual) . PHP_EOL);
    exit(1);
}
FormPresentationGraphBuilder::assertValid($graph);
foreach (array(null, 'leading', 1) as $invalid) {
    $bad = $graph;
    if (null === $invalid) {
        unset($bad['visual_parts'][0]['label_side']);
    } else {
        $bad['visual_parts'][0]['label_side'] = $invalid;
    }
    try {
        FormPresentationGraphBuilder::assertValid($bad);
    } catch (InvalidArgumentException) {
        continue;
    }
    fwrite(STDERR, 'FAIL: validator accepted a missing or invalid label_side' . PHP_EOL);
    exit(1);
}
echo "form visual part label side passed\n";
