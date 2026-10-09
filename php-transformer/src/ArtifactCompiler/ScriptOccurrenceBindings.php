<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use DOMDocument;
use DOMElement;

/** Keep extracted script occurrences bound to surviving nodes during projection. */
final class ScriptOccurrenceBindings
{
    /** @var list<DOMElement> */
    private array $original;

    public function __construct(private readonly DOMDocument $document)
    {
        $this->original = iterator_to_array($document->getElementsByTagName('script'));
    }

    /** @param list<array<string,mixed>> $files */
    public function rebind(array &$files, string $sourcePath): void
    {
        $selectors = array();
        foreach ($this->document->getElementsByTagName('script') as $index => $script) {
            foreach ($this->original as $originalIndex => $original) {
                // Node identity distinguishes equal bodies, empty/data scripts,
                // and external occurrences without guessing from their content.
                if ($original->isSameNode($script)) {
                    $selectors['script:nth-of-type(' . ($originalIndex + 1) . ')'] = 'script:nth-of-type(' . ($index + 1) . ')';
                    break;
                }
            }
        }
        foreach ($files as &$file) {
            if ('inline-script' !== ($file['source'] ?? null) || $sourcePath !== ($file['source_path'] ?? null)) continue;
            $selector = $file['selector'] ?? null;
            if (is_string($selector) && isset($selectors[$selector])) $file['selector'] = $selectors[$selector];
        }
        unset($file);
    }
}
