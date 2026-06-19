<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = ( new HtmlTransformer() )->transform('<main><h1>Hello</h1></main>')->toArray();

if ( TransformerResult::SCHEMA !== $result['schema'] ) {
    fwrite(STDERR, "Unexpected transformer result schema.\n");
    exit(1);
}

foreach ( array( 'blocks', 'serialized_blocks', 'documents', 'assets', 'diagnostics', 'fallbacks', 'provenance', 'coverage' ) as $key ) {
    if ( ! array_key_exists($key, $result) ) {
        fwrite(STDERR, "Missing result key: {$key}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Contract scaffold passed.\n");
