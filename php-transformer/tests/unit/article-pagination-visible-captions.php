<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$result = (new HtmlTransformer())->transform(
    '<nav class="pagination"><div><span>Newer Post</span><a href="/new" rel="prev">New article</a></div>'
    . '<div><span>Older Post</span><a href="/old" rel="next">Old article</a></div></nav>'
)->toArray();
$markup = $result['serialized_blocks'];
foreach ( array( 'Newer Post', 'Older Post', 'New article', 'Old article' ) as $caption ) {
    if ( 1 !== substr_count($markup, $caption) ) {
        throw new RuntimeException('Pagination lost or duplicated ' . $caption . ': ' . $markup);
    }
}
if ( str_contains($markup, '<!-- wp:navigation-link ') ) {
    throw new RuntimeException('Article pagination was flattened into a menu.');
}
echo "PASS: article pagination visible captions\n";
