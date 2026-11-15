<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorListCompactor;

$input = ':is(.same,.same) > img,:is(.same,.same) > img{color:red}';
$output = CssSelectorListCompactor::stylesheet($input);
if (':is(.same) > img{color:red}' !== $output) {
    throw new RuntimeException('Coalesced identities must not repeat top-level or logical selector alternatives: ' . $output);
}
$input = '.x[data-value="a,b"],.x[data-value="a,b"]{color:blue}';
if ('.x[data-value="a,b"]{color:blue}' !== CssSelectorListCompactor::stylesheet($input)) {
    throw new RuntimeException('Commas inside attribute values are not selector boundaries');
}
echo "Coalesced selector deduplication passed\n";
