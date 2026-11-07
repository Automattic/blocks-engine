<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$cases = array(
    'plain'         => '<svg viewBox="0 0 10 10"><path d="M0 0h10v10z"></path></svg>',
    // Wix vector shapes nest a namespaced <svg> inside an unnamespaced root.
    'nested-xmlns'  => '<svg height="100%" width="100%"><g><svg viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10z"></path></svg></g></svg>',
);
foreach ($cases as $name => $svg) {
    $result = (new HtmlTransformer())->transform('<main>' . $svg . '</main>')->toArray();
    $assets = array_values(array_filter(
        $result['assets'] ?? array(),
        static fn(array $asset): bool => 'inline-svg' === ($asset['source'] ?? null)
    ));
    if (1 !== count($assets)) {
        fwrite(STDERR, "FAIL: {$name} inline SVG materializes as one asset.\n");
        exit(1);
    }
    foreach (array('content', 'visual_payload') as $payload) {
        if (!preg_match('/^<svg\b[^>]*\sxmlns="http:\/\/www\.w3\.org\/2000\/svg"/', (string) ($assets[0][$payload] ?? ''))) {
            fwrite(STDERR, "FAIL: {$name} materialized SVG {$payload} declares its namespace on the root.\n");
            exit(1);
        }
    }
}
fwrite(STDOUT, "SVG materialization namespace contract passed.\n");
