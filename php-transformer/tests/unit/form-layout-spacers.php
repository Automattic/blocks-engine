<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;

$fixture = file_get_contents(dirname(__DIR__) . '/fixtures/forms/placeholder-error-slots.html');
$build = static function (string $html, string $extraCss = ''): array {
    $document = new DOMDocument();
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $css = $document->getElementsByTagName('style')->item(0)->textContent . $extraCss;
    return (new FormLayoutGraphBuilder())->build($document->getElementsByTagName('form')->item(0), array(), $css);
};
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$spacers = static fn(array $graph): array => array_values(array_filter($graph['nodes'], static fn(array $node): bool => 'spacer' === $node['kind']));
$graph = $build($fixture);
$nodes = $spacers($graph);
$check(3 === count($nodes), 'Fixture must expose three spacer nodes');
$check(8 === $graph['nodes'][0]['in_flow_children'], 'Spacer count must agree with source child count');
foreach ($nodes as $index => $node) {
    $check('form' === $node['parent'] && 2 * $index + 1 === $node['order'], 'Spacers must alternate with field wrappers in source order');
    $check('whitespace_only' === $node['content'], 'NBSP is whitespace, not authored copy');
    $styles = $node['presentation']['styles'];
    $check('14px' === ($styles['line_height'] ?? null) && '12px' === ($styles['font_size'] ?? null), 'Line box and font size must survive');
    $check('2px' === ($node['layout']['margin_bottom'] ?? null), 'Bottom margin must survive');
    $check(array() !== $node['presentation']['provenance'], 'Spacer geometry must retain provenance');
}
$check(array() === $spacers($build(str_replace('&nbsp;', 'Required', $fixture))), 'Real copy must not become spacers');
$check(array() === $spacers($build($fixture, '.error{display:none}')) && 5 === $build($fixture, '.error{display:none}')['nodes'][0]['in_flow_children'], 'Hidden placeholders must not be nodes or in-flow children');
foreach (array('absolute', 'fixed') as $position) {
    $check(array() === $spacers($build($fixture, '.error{position:' . $position . '}')), 'Out-of-flow placeholders must not become spacers');
}
$plain = str_replace('<span class="error">&nbsp;</span>', '', $fixture);
$withoutHashes = static fn(array $graph): string => preg_replace('/"source_sha256":"[a-f0-9]{64}"/', '"source_sha256":"HASH"', json_encode($graph));
$check($withoutHashes($build($plain)) === $withoutHashes($build($plain, '.error{line-height:99px}')), 'Forms without placeholders must be unaffected by spacer styles');
$check(array_column(array_filter($graph['nodes'], static fn(array $n): bool => 'spacer' !== $n['kind']), 'id') === array_column($build($plain)['nodes'], 'id'), 'Existing node IDs must remain stable');
$variant = $spacers($build($fixture, '@media(max-width:600px){.error{line-height:20px;padding:3px;border:1px solid red;height:24px}}'))[0]['presentation']['variants'][0];
$check('20px' === ($variant['styles']['line_height'] ?? null) && '24px' === ($variant['styles']['height'] ?? null) && '3px' === ($variant['styles']['padding'] ?? null) && '1px solid red' === ($variant['styles']['border'] ?? null) && array() !== $variant['provenance'], 'Conditional box geometry must retain the existing presentation contract');
FormLayoutGraphBuilder::assertValid($graph);
foreach (array('copy', null) as $invalid) {
    $bad = $graph;
    $key = array_search($nodes[0]['id'], array_column($bad['nodes'], 'id'), true);
    $bad['nodes'][$key]['content'] = $invalid;
    try {
        FormLayoutGraphBuilder::assertValid($bad);
        throw new RuntimeException('Invalid spacer classification accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
echo "form layout spacers passed\n";
