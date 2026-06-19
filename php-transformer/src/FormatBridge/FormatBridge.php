<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge;

final class FormatBridge
{
    public function __construct(
        private readonly AdapterRegistry $registry = new AdapterRegistry(),
        private readonly Normalizer $normalizer = new Normalizer()
    ) {
        $this->registry->register(new BlocksAdapter());
        $this->registry->register(new HtmlAdapter());
    }

    public function registerAdapter(FormatAdapterInterface $adapter): void
    {
        $this->registry->register($adapter);
    }

    /**
     * @return list<string>
     */
    public function supportedFormats(): array
    {
        return $this->registry->slugs();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function normalize(string $content, string $format, array $options = array()): string
    {
        return $this->normalizer->normalize($content, $format, $this->registry, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, array<string, mixed>>
     */
    public function toBlocks(string $content, string $from, array $options = array()): array
    {
        $this->normalize($content, $from, $options);

        $adapter = $this->registry->get($from);

        return $adapter ? $adapter->toBlocks($content, $options) : array();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function convert(string $content, string $from, string $to, array $options = array()): string
    {
        if ( $from === $to ) {
            return $this->normalize($content, $from, $options);
        }

        $blocks = $this->toBlocks($content, $from, $options);
        if ( 'blocks' === $to ) {
            $adapter = $this->registry->get($to);

            return $adapter ? $adapter->fromBlocks($blocks, $options) : '';
        }

        $adapter = $this->registry->get($to);

        return $adapter ? $adapter->fromBlocks($blocks, $options) : '';
    }
}
