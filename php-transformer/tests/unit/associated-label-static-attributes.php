<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredLabelBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) throw new RuntimeException($message);
};
$minimal = (new HtmlTransformer())->transform('<style>label[data-role=caption]{color:red;font-size:20px}</style><input id="choice" type="checkbox"><label for="choice" data-role="caption">Choice</label>')->toArray();
$minimalDom = new DOMDocument();
$minimalDom->loadHTML($minimal['serialized_blocks'], LIBXML_NOERROR | LIBXML_NOWARNING);
$assert('caption' === $minimalDom->getElementsByTagName('label')->item(0)->getAttribute('data-role'), 'reviewer reproduction preserves the actual data-role subject');
$assert('choice' === $minimalDom->getElementsByTagName('label')->item(0)->getAttribute('for'), 'reviewer reproduction preserves the native association');
$source = (string) file_get_contents(__DIR__ . '/../fixtures/associated-label-static-attributes.html');
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'block_namespace' => 'custom', 'files' => array('index.html' => $source)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$page = $plan['pages'][0];
$original = new DOMDocument();
$original->loadHTML($source, LIBXML_NOERROR | LIBXML_NOWARNING);
$candidate = new DOMDocument();
$candidate->loadHTML($page['canonical_block_markup'], LIBXML_NOERROR | LIBXML_NOWARNING);
$label = $candidate->getElementById('choice-caption');
$sourceLabel = $original->getElementById('choice-caption');
foreach (SourceDom::htmlAttributes($sourceLabel) as $name => $value) {
    $assert($label->hasAttribute($name) && $value === $label->getAttribute($name), 'authored label host retains the actual ' . $name . ' subject');
}
$assert('input' === $label->previousElementSibling?->tagName && 'choice' === $label->previousElementSibling?->getAttribute('id'), 'attribute preservation introduces no adjacency wrapper');
$assert('label' === $candidate->getElementById('other')->previousElementSibling?->tagName, 'opposite source label order remains intact');
$assert('caption-context' === $page['document_metadata']['body_attributes']['class'] && 'choices' === $page['document_metadata']['body_attributes']['data-document'], 'body context remains owned by the canonical page metadata');
$css = implode("\n", array_column(array_filter($plan['assets'], static fn (array $asset): bool => 'css' === $asset['kind'] && 'editor' !== ($asset['stylesheet_target'] ?? 'both')), 'content'));
$assert(str_contains($css, 'label[data-role=caption]') && str_contains($css, 'body.caption-context'), 'source attribute and body subjects remain stylesheet predicates');
$assert(str_contains($css, '@media') && str_contains($css, '[data-state=expanded]'), 'conditional width and live data-state predicates remain authored CSS');
$assert(array() === $result['fallbacks'], 'static source attributes do not require a fallback');
$assert(!str_contains($page['canonical_block_markup'], 'blocks-engine-attribute-'), 'actual label attributes need no synthetic selector compensation');

$unsafe = array('onclick' => 'alert(1)', 'onpointerdown' => 'alert(2)', 'data-action' => 'run()', 'data-on' => '', 'data-event' => 'run()', 'jsaction' => 'click:run', 'data-wp-on--click' => 'actions.run', 'srcdoc' => '<script>run()</script>', 'href' => 'javascript:run()', 'is' => 'active-label', 'for' => 'wrong', 'id' => 'wrong', 'class' => 'wrong', 'style' => 'display:none');
$attrs = array('htmlFor' => 'choice', 'id' => 'safe-label', 'content' => 'Choice', 'sourceAttributes' => $unsafe + array('data-role' => 'caption', 'title' => 'A "quoted" & title', 'aria-label' => 'Choice', 'hidden' => ''));
$surface = (new AuthoredLabelBlockGenerator())->markup($attrs);
$filtered = new DOMDocument();
$filtered->loadHTML($surface, LIBXML_NOERROR | LIBXML_NOWARNING);
$host = $filtered->getElementsByTagName('label')->item(0);
foreach ($unsafe as $name => $value) {
    $assert(in_array($name, array('for', 'id'), true) ? $value !== $host->getAttribute($name) : !$host->hasAttribute($name), 'PHP save filter rejects unowned/executable attribute ' . $name);
}
$assert('caption' === $host->getAttribute('data-role') && 'A "quoted" & title' === $host->getAttribute('title') && $host->hasAttribute('hidden'), 'passive strings and presence attributes survive escaping');
$captured = (new HtmlTransformer())->transform('<input id="choice" type="checkbox"><label for="choice" data-role="caption" onclick="bad()" data-action="bad()" data-wp-on--click="bad()">Choice</label>')->toArray();
$assert(str_contains($captured['serialized_blocks'], 'data-role="caption"') && !str_contains($captured['serialized_blocks'], 'onclick=') && !str_contains($captured['serialized_blocks'], 'data-action=') && !str_contains($captured['serialized_blocks'], 'data-wp-on--click='), 'source capture admits only the passive host attributes');

echo "Associated label static attributes: $assertions passed\n";
