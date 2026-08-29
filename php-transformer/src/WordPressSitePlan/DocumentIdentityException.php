<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use InvalidArgumentException;

/** A fail-closed identity failure that carries every offending compiled document. */
final class DocumentIdentityException extends InvalidArgumentException
{
    /**
     * @param array<int,array{source_path:string,reason:string,document_kind:string}> $failures
     */
    public function __construct(private array $failures)
    {
        parent::__construct(self::summary($failures));
    }

    /**
     * @return array<int,array{source_path:string,reason:string,document_kind:string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return array<int,array<string,mixed>> */
    public function diagnostics(): array
    {
        return WordPressSitePlan::documentIdentityDiagnostics($this->failures);
    }

    /**
     * @param array<int,array{source_path:string,reason:string,document_kind:string}> $failures
     */
    private static function summary(array $failures): string
    {
        $count = count($failures);
        $empty = count(array_filter($failures, static fn (array $failure): bool => 'empty_block_markup' === $failure['reason']));
        $paths = array_slice(array_values(array_filter(array_column($failures, 'source_path'), static fn (mixed $path): bool => is_string($path) && '' !== $path)), 0, 3);
        $listed = implode(', ', $paths);
        $more = $count > count($paths) ? sprintf(' (+%d more)', $count - count($paths)) : '';

        return sprintf(
            '%d compiled site document(s) lack a safe identity or block markup (%d empty markup, %d unsafe identity): %s%s',
            $count,
            $empty,
            $count - $empty,
            $listed,
            $more
        );
    }
}
