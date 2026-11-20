<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal;

use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

/** Capture numbering is an address, not part of the captured markup. */
final class SubtreeIdentity
{
    public static function normalize(string $markup): string
    {
        // Also cover attributes inside escaped dialog panel-state HTML.
        return (string) preg_replace(
            '~(\b' . '(?:' . preg_quote(BoxTree::NODE_ATTRIBUTE, '~') . '|data-blocks-engine-richtext-marker)' . '\s*=\s*)("[^"]*"|\'[^\']*\'|&quot;.*?&quot;|&#34;.*?&#34;|[^\s>]+)~i',
            '$1""',
            $markup
        );
    }
}
