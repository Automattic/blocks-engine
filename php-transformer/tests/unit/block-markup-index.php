<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;

foreach (array(1000, 2000, 4000) as $count) {
    $markup = str_repeat('<!-- wp:group -->', $count) . '<!-- wp:paragraph /-->' . str_repeat('<!-- /wp:group -->', $count);
    $index = new BlockMarkupIndex($markup);
    if ($count + 1 !== count($index->ranges()) || 1 !== count($index->topLevel())) throw new RuntimeException('Incomplete nested index.');
    $edges = 0;
    foreach ($index->ranges() as $range) $edges += count($index->children($range));
    if ($edges !== $count) throw new RuntimeException('Each child must be visited exactly once.');
    // Queries must not scan or tokenize markup again, including repeated walks.
    for ($walk = 0; $walk < 3; ++$walk) {
        foreach ($index->ranges() as $range) {
            foreach ($index->children($range) as $child) {
                if ($index->parent($child) !== $range) throw new RuntimeException('Parent/child mismatch.');
            }
        }
    }
    if ($index->markup !== $markup) throw new RuntimeException('Markup bytes changed.');
}
$before = new BlockMarkupIndex('<!-- wp:paragraph /-->');
$after = new BlockMarkupIndex('<!-- wp:group --><!-- wp:paragraph /--><!-- /wp:group -->');
if (1 !== count($before->ranges()) || 2 !== count($after->ranges())) throw new RuntimeException('Rewrite indexes must be independent.');
echo "Block markup index scaling passed.\n";
