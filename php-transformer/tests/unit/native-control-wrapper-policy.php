<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityPolicy;
use Automattic\BlocksEngine\PhpTransformer\Contract\EditabilityReport;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A native control adds its own buttons wrapper. Retaining every source group
// above it must not make otherwise importable column-flex content fail policy.
$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/parity/html-column-flex-growing-item-definite-height.json'), true, 512, JSON_THROW_ON_ERROR);
$result = (new HtmlTransformer())->transform($fixture['input']['content'])->toArray();
$report = (new EditabilityReport())->fromBlocks($result['blocks']);
$policy = (new EditabilityPolicy())->evaluate($report);
if ('failed' === $policy['status']) {
    throw new RuntimeException('Native control wrapper expansion must not abort import: ' . json_encode($policy['failures']));
}
if (!str_contains($result['serialized_blocks'], '<!-- wp:buttons') || !str_contains($result['serialized_blocks'], 'href="/about">About</a>')) {
    throw new RuntimeException('Wrapper normalization must retain the native editable control.');
}
echo "Native control wrapper policy passed\n";
