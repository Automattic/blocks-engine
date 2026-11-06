<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<main><svg viewBox="0 0 10 10"><path d="M0 0h10v10z"></path></svg></main>'
)->toArray();
$assets = array_values(array_filter(
    $result['assets'] ?? array(),
    static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? null)
));
if (1 !== count($assets)) {
    fwrite(STDERR, "FAIL: inline SVG materializes as one asset.\n");
    exit(1);
}
foreach (array('content', 'visual_payload') as $payload) {
    if (!str_starts_with((string) ($assets[0][$payload] ?? ''), '<svg xmlns="http://www.w3.org/2000/svg"')) {
        fwrite(STDERR, 'FAIL: materialized SVG ' . $payload . " declares its namespace.\n");
        exit(1);
    }
}
fwrite(STDOUT, "SVG materialization namespace contract passed.\n");
