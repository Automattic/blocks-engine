<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ChromeJournal;

/**
 * Journals shared by staged page workers through one directory per import.
 * A journal is plain data, written once under its key; a write that loses a
 * race leaves the equal journal another worker wrote.
 */
final class DirectoryChromeJournalStore implements ChromeJournalStore
{
    private readonly MemoryChromeJournalStore $read;

    public function __construct(private readonly string $directory)
    {
        $this->read = new MemoryChromeJournalStore();
    }

    public function journal(string $key): ?array
    {
        $journal = $this->read->journal($key);
        if (null !== $journal) return $journal;
        $bytes = is_file($this->path($key)) ? file_get_contents($this->path($key)) : false;
        $journal = false === $bytes ? false : unserialize($bytes, array('allowed_classes' => false));
        if (!is_array($journal)) return null;
        $this->read->record($key, $journal);
        return $journal;
    }

    public function record(string $key, array $journal): void
    {
        $this->read->record($key, $journal);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) return;
        $temporary = $this->path($key) . '.' . bin2hex(random_bytes(4));
        if (false !== file_put_contents($temporary, serialize($journal))) rename($temporary, $this->path($key));
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/') . '/' . preg_replace('/[^a-f0-9]/', '', $key) . '.journal';
    }
}
