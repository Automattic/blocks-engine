<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<form><div class="field"><label for="email">Email</label><input id="email" name="email"></div><button>Send</button></form>'
    . '<blockquote><p>Review</p><footer><div aria-hidden="true" class="rule"></div><span>Publication</span><span>Reviewer</span></footer></blockquote>',
    array('static_css' => '.field{display:flex;gap:12px}.rule{height:1px;width:20px;background:black}')
)->toArray();
$form = current(array_filter($result['fallbacks'], static fn (array $fallback): bool => 'html_form_fallback' === ($fallback['diagnostic_code'] ?? null)));
$styles = $form['presentation_graph']['controls'][0]['control']['styles'] ?? array();
if (isset($styles['flex']) || isset($styles['width'])) {
    throw new RuntimeException('A label and its input are not a shared controls row: do not inject provider sizing defaults.');
}
$markup = $result['serialized_blocks'];
if (!str_contains($markup, '<cite') || str_contains($markup, '>Publication</p>')) {
    throw new RuntimeException('A decorative rule must not turn a phrasing citation into structural paragraphs.');
}
echo "Imported form presentation boundaries passed\n";
