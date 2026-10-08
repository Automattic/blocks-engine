<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    ++$count;
    if (!$condition) throw new RuntimeException($message);
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$result = $transform((string) file_get_contents(__DIR__ . '/../fixtures/associated-label.html'));
$html = $result['serialized_blocks'];
$document = new DOMDocument();
$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
$assert(1 === $xpath->query('//input[@id="after"]/following-sibling::*[1][self::label][@for="after"]')->length, 'input + label association and adjacency survive compilation');
$assert(1 === $xpath->query('//input[@id="before"]/preceding-sibling::*[1][self::label][@for="before"]')->length, 'label + input adjacency survives compilation');
$assert(1 === $xpath->query('//label[@id="before-caption"]/strong')->length && 1 === $xpath->query('//label[@id="before-caption"]//em')->length, 'inline rich formatting stays in the actual label');
$assert('caption' === $document->getElementById('after-caption')->getAttribute('class'), 'source label classes survive without synthetic paragraph classes');
$assert('--caption-role: filter' === $document->getElementById('after-caption')->getAttribute('style'), 'source inline declarations survive');
$assert(array() === $result['fallbacks'], 'associated controls have no fallback');
$definitions = $result['source_reports']['generated_blocks'];
$labels = array_values(array_filter($definitions, static fn (array $definition): bool => 'authored-label' === $definition['name']));
$assert(1 === count($labels), 'one registered definition owns both labels');
$payload = (new CompanionPluginPayload())->fromBlockTypes(array(), array(), array(), $definitions);
$assert(count($definitions) === count($payload['blocks']), 'existing companion payload accepts all generated definitions');
$assert(array('wp-blocks', 'wp-block-editor', 'wp-element') === $labels[0]['script_dependencies']['index.js'], 'label widget dependencies reach consumer');

foreach (array(
    '<label for="outside" id="external">Outside <strong>fragment</strong></label>',
    '<label for="outside"></label>',
) as $source) {
    $external = $transform($source);
    $assert('custom/authored-label' === $external['blocks'][0]['blockName'], 'explicit for is retained when the target is outside the fragment, including empty labels');
    $assert(str_contains($external['serialized_blocks'], '<label for="outside"'), 'external target association is not invented or discarded');
}
$plain = $transform('<label>Caption <strong>only</strong> &amp; more</label>');
$assert('core/paragraph' === $plain['blocks'][0]['blockName'], 'unassociated prose retains its paragraph role');
$assert('Caption <strong>only</strong> &amp; more' === $plain['blocks'][0]['attrs']['content'], 'plain caption keeps editable rich content');
$assert(array() === $plain['source_reports']['generated_blocks'], 'plain caption does not invent a field entity or companion');
$unstyled = $transform('<input id="unstyled" type="checkbox"><label for="unstyled">Choice</label>');
$assert('custom/authored-input' === $unstyled['blocks'][0]['blockName'] && 'custom/authored-label' === $unstyled['blocks'][1]['blockName'], 'unstyled explicit association still retains its native target');
$assert(!isset($unstyled['blocks'][0]['attrs']['label']), 'external label is not duplicated as a wrapping input label');
$numeric = $transform('<input id="901" type="checkbox"><label for="901">Numeric identity</label>');
$assert(str_contains($numeric['serialized_blocks'], '<label for="901">') && str_contains($numeric['serialized_blocks'], 'id="901"'), 'numeric authored association is retained verbatim');
$rich = $transform('<label for="external"><span class="caption-run" style="font-size:18px">Rich <strong>text</strong></span></label>');
$assert(str_contains($rich['blocks'][0]['attrs']['content'], 'role="none"') && str_contains($rich['blocks'][0]['attrs']['content'], '<strong>text</strong>'), 'inline attribute carrier retains a neutral role and rich formatting');
$artifact = (new ArtifactCompiler())->compile(array(
    'block_namespace' => 'acme',
    'files' => array('index.html' => '<main><input id="choice" type="checkbox"><label for="choice">Choice</label></main>'),
))->toArray();
$companions = $artifact['source_reports']['companion_plugin_payload']['blocks'] ?? array();
$labelPayload = array_values(array_filter($companions, static fn (array $definition): bool => 'acme/authored-label' === ($definition['block_json']['name'] ?? '')));
$assert(1 === count($labelPayload) && isset($labelPayload[0]['assets']['index.js']), 'full artifact compile hands the owned label widget to the existing consumer payload');
$assert(str_contains($artifact['serialized_blocks'], 'wp:acme/authored-label'), 'consumer-owned namespace reaches artifact markup');
$wrapped = $transform('<label class="wrapping">Email<input type="email" style="width:200px"></label>');
$assert(!str_contains($wrapped['serialized_blocks'], 'authored-label'), 'wrapping control stays in the existing authored-input contract');
$assert('core/paragraph' === $wrapped['blocks'][0]['blockName'] && 'Email' === $wrapped['blocks'][0]['attrs']['content'], 'wrapping label retains the established readable-control lowering');

echo "Associated label regression: $count passed\n";
