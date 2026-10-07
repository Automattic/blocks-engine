<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<main><section><h2>2025</h2><div class="entries">'
    . '<article><p>Conference A</p><h3><a href="/talk-a/">Talk A</a></h3><time>May 2025</time><svg aria-hidden="true"><path d="M0 0h1v1z"/></svg></article>'
    . '<article><p>Conference B</p><h3><a href="/talk-b/">Talk B</a></h3><time>April 2025</time><svg aria-hidden="true"><path d="M0 0h1v1z"/></svg></article>'
    . '<article><p>Conference C</p><h3><a href="/talk-c/">Talk C</a></h3><time>March 2025</time><svg aria-hidden="true"><path d="M0 0h1v1z"/></svg></article>'
    . '</div></section></main>';

$result = ( new HtmlTransformer() )->transform($html)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert = static function (bool $condition, string $message) use ($markup): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n{$markup}\n");
        exit(1);
    }
};

$assert(! str_contains($markup, 'core/navigation'), 'repeated article cards are not lowered to navigation');
foreach (array('Conference A', 'Conference B', 'Conference C', 'May 2025', 'April 2025', 'March 2025', 'Talk A', 'Talk B', 'Talk C') as $content) {
    $assert(str_contains($markup, $content), "card content survives: {$content}");
}
$assert(3 === substr_count($markup, 'href="/talk-'), 'all three card destinations remain in the output');
$assert(3 === substr_count($markup, '<!-- wp:image '), 'all three card icons remain represented as native image blocks');

echo "Repeated heading card recognition contract passed\n";
