<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$result = (new HtmlTransformer())->transform(
    '<blockquote style="display:flex;flex-direction:column;gap:30px"><p>A quiet observation.</p><p>A second thought.</p></blockquote>',
    array()
)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};
$assert(1 === preg_match('/<!-- wp:quote (\{.*?\}) -->/', $markup, $quote), 'The flex-column blockquote becomes core/quote.');
$attrs = json_decode($quote[1] ?? '{}', true) ?: array();
$assert(! preg_match('/style="[^"]*\bgap\s*:/i', $markup), 'Saved markup never inlines blockGap.');
$assert('30px' === ($attrs['style']['spacing']['blockGap'] ?? null) || str_contains($css, 'gap:30px'), 'The source gap survives in layout attributes or generated CSS.');
$validity = (new Runtime())->validateBlockSerialization($markup);
$assert(array() === ($validity['findings'] ?? array()), 'The quote passes block serialization validation.');
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n" . $markup . "\n" . $css . "\n");
    exit(1);
}
echo "Quote block gap save validity passed\n";
