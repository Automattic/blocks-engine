<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Exercise the public transform: only ordinary following flow containers need
// the focusable carrier, not Navigation's brand slot or equivalent landmarks.
$cases = array(
    'flow group' => array('<div><a href="/">Category</a><div><h1>Product</h1></div></div>', true),
    'flow heading' => array('<div><a href="/">Category</a><h1>Product</h1></div>', true),
    'navigation brand' => array('<nav><a class="nav-logo" href="/">Brand</a><ul><li><a href="/about">About</a></li></ul></nav>', false),
    'footer tagline' => array('<footer><div><a href="/">Brand</a><p>Tagline</p></div></footer>', false),
    'header landmark' => array('<a href="#content">Skip</a><header><p>Brand</p></header><main id="content">Body</main>', false),
    'banner landmark' => array('<a href="#content">Skip</a><div role="banner"><p>Brand</p></div><main id="content">Body</main>', false),
);
foreach ($cases as $name => [$html, $expected]) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $actual = str_contains($result['serialized_blocks'], 'blocks-engine-inline-anchor-carrier');
    if ($expected !== $actual) {
        throw new RuntimeException($name . ': incorrect focusable carrier ownership');
    }
}
echo "Synthetic anchor carrier boundaries passed: " . count($cases) . " cases\n";
