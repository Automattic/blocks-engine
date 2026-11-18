<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

/**
 * The core/embed metadata contract for a frame no oEmbed provider serves.
 *
 * Translation records the source iframe's bounded attributes under this key;
 * the generated theme reads them to render the source's own bare iframe.
 * Both layers depend on this vocabulary, never on each other.
 */
final class EmbedFrameMetadata
{
    /** core/embed `metadata` key holding the source frame's bounded attributes. */
    public const KEY = 'blocksEngineIframe';
}
