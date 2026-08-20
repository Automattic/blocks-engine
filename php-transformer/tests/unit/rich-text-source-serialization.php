<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( $condition ) {
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$factory = new BlockFactory();
$blocks = array(
    $factory->create('core/heading', array( 'content' => 'Event notifications', 'level' => 2 )),
    $factory->create('core/group', array(), array(
        $factory->create('core/heading', array( 'content' => 'Nested heading', 'level' => 3 )),
    )),
);

$serialized = ( new Runtime() )->serializeBlocks($blocks);

$assert(
    str_contains($serialized, '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Event notifications</h2><!-- /wp:heading -->'),
    'Heading content stays in saved HTML and is omitted from delimiter attributes.'
);
$assert(
    str_contains($serialized, '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Nested heading</h3><!-- /wp:heading -->'),
    'Nested heading content is recursively omitted from delimiter attributes.'
);
$assert(
    'Event notifications' === ($blocks[0]['attrs']['content'] ?? null),
    'Canonical serialization does not mutate transformer working blocks.'
);

if ( 0 === $failures ) {
    echo "rich-text source serialization ok\n";
}

exit(0 === $failures ? 0 : 1);
