<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Group save supports semantic tags as well as div. Empty and branching
// terminals also have native representations; neither needs shell transport.
foreach (array(
    '<main id="content"><div id="frame"><div class="container"><div id="first"><p>One</p></div><div id="second"><p>Two</p></div></div></div></main>',
    '<main id="content"><div id="frame"><div class="container"></div></div></main>',
) as $source) {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $markup = $result['serialized_blocks'];
    if (str_contains($markup, '/layout-shell') || ! str_contains($markup, '<!-- wp:group')) {
        throw new RuntimeException('Representable group chains must remain core-editable: ' . $markup);
    }
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $xpath = new DOMXPath($dom);
    if (1 !== $xpath->query('//main[@id="content"]/div[@id="frame"]/div[contains(@class,"container")]')->length) {
        throw new RuntimeException('Native groups must preserve the source wrapper hierarchy.');
    }
}

echo "Native group wrapper chain contract passed.\n";
