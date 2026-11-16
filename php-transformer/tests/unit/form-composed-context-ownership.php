<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main><form><label>News by post</label><div class="controls"><input type="email" name="email"><button type="submit">Subscribe</button></div></form></main>',
)))->toArray();
$form = current(array_filter($result['fallbacks'], static fn(array $f): bool => 'html_form_fallback' === ($f['diagnostic_code'] ?? null)));
if (!$form || !str_contains($form['binding']['search_block_markup'] ?? '', 'News by post')) {
    throw new RuntimeException('A form-owned caption must share the provider replacement boundary, not survive outside it as duplicate page content.');
}
if (!str_contains(json_encode($form['readable_blocks'], JSON_THROW_ON_ERROR), 'News by post')) {
    throw new RuntimeException('The provider readable tree must retain the authored caption.');
}
echo "Form caption shares the provider replacement boundary\n";
