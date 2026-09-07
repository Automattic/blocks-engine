<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\BlockFactory;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;
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

$richTextCascade = ( new HtmlTransformer() )->transform(
    '<style>:root{--dark:0,0,0;--light:255,255,255}'
    . '.font-default{color:rgb(var(--dark))}.color-override{color:rgb(var(--light))}</style>'
    . '<p><span><span><span class="font-default" style="font-family:Arial"><span>First</span></span></span></span></p>'
    . '<p><span><span class="color-override"><span style="font-family:Arial"><span>Second</span></span></span></span></p>'
)->toArray();
$richTextMarkup = (string) ($richTextCascade['serialized_blocks'] ?? '');
$secondOffset = strpos($richTextMarkup, 'Second');
$secondStart = false === $secondOffset ? false : strrpos(substr($richTextMarkup, 0, $secondOffset), '<!-- wp:paragraph');
$secondMarkup = false === $secondStart || false === $secondOffset ? '' : substr($richTextMarkup, $secondStart, $secondOffset - $secondStart);
$assert(
    str_contains($secondMarkup, 'color:rgb(var(--light))')
        && ! str_contains($secondMarkup, 'color:rgb(var(--dark))'),
    'Separate RichText fragment documents do not leak an earlier fragment cascade into the same DOM path.'
);

$linkedAttachment = ( new HtmlTransformer() )->transform(
    '<p class="attachment"><a class="lightbox" href="https://example.test/model-full.jpg" target="_blank" rel="noopener"><img src="https://example.test/model.jpg" alt="Geological model"></a></p>'
)->toArray();
$attachmentMarkup = (string) ($linkedAttachment['serialized_blocks'] ?? '');
$assert(
    str_contains($attachmentMarkup, '<!-- wp:image ')
        && str_contains($attachmentMarkup, '<a href="https://example.test/model-full.jpg" target="_blank" rel="noopener" class="lightbox">')
        && str_contains($attachmentMarkup, '<img src="https://example.test/model.jpg" alt="Geological model"/>')
        && ! str_contains($attachmentMarkup, '<!-- wp:html'),
    'An image-only paragraph link lowers to a native linked image instead of a core/html fallback.'
);
$assert(
    'pass' === ( ( new BlockValidityValidator() )->validateBlocks($linkedAttachment['blocks'] ?? array())['status'] ?? '' ),
    'A linked image lowered from a paragraph stays Gutenberg-valid.'
);

if ( 0 === $failures ) {
    echo "rich-text source serialization ok\n";
}

exit(0 === $failures ? 0 : 1);
