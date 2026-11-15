<?php
declare(strict_types=1);

/** Hidden validation copy must not become permanently visible provider help (#5864). */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<style>.feedback{display:none}</style><form><div><input name="phone" type="tel" placeholder="Phone">'
    . '<div class="feedback">A phone number is required.</div></div>'
    . '<div><input name="portfolio" placeholder="Portfolio"><p>Link to your work.</p></div></form>'
)->toArray();
$fallback = current(array_filter($result['fallbacks'], static fn (array $finding): bool => 'html_form_fallback' === ($finding['diagnostic_code'] ?? null)));
$controls = array_column($fallback['controls'] ?? array(), null, 'name');
if (!isset($controls['phone'], $controls['portfolio'])
    || isset($controls['phone']['description'])
    || 'Link to your work.' !== ($controls['portfolio']['description'] ?? null)) {
    fwrite(STDERR, "Hidden validation must not become help; visible descriptions must survive.\n");
    exit(1);
}
echo "ok\n";
