<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal;

/** Where pages of one site find and leave {@see ChromeJournal} recordings. */
interface ChromeJournalStore
{
    /** @return array<string,mixed>|null */
    public function journal(string $key): ?array;

    /** @param array<string,mixed> $journal */
    public function record(string $key, array $journal): void;
}
