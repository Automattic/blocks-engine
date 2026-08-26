<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/** Per-transform generated block definitions and registration state. */
final class GeneratedBlockRegistry
{
    /** @var array<int, array<string, mixed>> */
    private array $definitions = array();

    /** @var array<string, true> */
    private array $registered = array();

    public function __construct(private readonly string $namespace)
    {
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function blockName(string $localName): string
    {
        return $this->namespace . '/' . $localName;
    }

    public function has(string $identity): bool
    {
        return isset($this->registered[$identity]);
    }

    /** @param array<string, mixed> $definition */
    public function register(string $identity, array $definition): void
    {
        if ($this->has($identity)) {
            return;
        }

        $this->registered[$identity] = true;
        $this->definitions[] = $definition;
    }

    /** @param array<string, mixed> $definition */
    public function append(array $definition): void
    {
        $this->definitions[] = $definition;
    }

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }
}
