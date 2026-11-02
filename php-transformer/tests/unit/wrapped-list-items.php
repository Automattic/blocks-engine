<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fixtures = array(
    'wrapped list items' => '<section><ul class="ticker"><div><li class="ticker-item"><p>Alpha</p></li></div><div><li class="ticker-item"><p>Beta</p></li></div></ul></section>',
    'wrapped inline list items' => '<ul><div><li>Alpha</li></div><div><li>Beta</li></div></ul>',
    'direct list items control' => '<section><ul><li><p>Alpha</p></li><li><p>Beta</p></li></ul></section>',
);
foreach ($fixtures as $name => $html) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $output = (string) ($result['serialized_blocks'] ?? '');
    foreach (array('Alpha', 'Beta') as $text) {
        if (!str_contains($output, $text)) throw new RuntimeException("$name dropped $text");
    }
    foreach ($result['fallbacks'] ?? array() as $fallback) {
        if ('li' === ($fallback['tag'] ?? null) && 'unsupported_element' === ($fallback['reason'] ?? null)) throw new RuntimeException("$name reported an unsupported list item");
    }
}
fwrite(STDOUT, "wrapped list items regression passed\n");
