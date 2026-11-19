<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal;

/** Journals shared by the pages one process compiles. */
final class MemoryChromeJournalStore implements ChromeJournalStore
{
    /** @var array<string,array<string,mixed>> */
    private array $journals = array();

    public function journal(string $key): ?array
    {
        return $this->journals[$key] ?? null;
    }

    public function record(string $key, array $journal): void
    {
        $this->journals[$key] ??= $journal;
    }
}
