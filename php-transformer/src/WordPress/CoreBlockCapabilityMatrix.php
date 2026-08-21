<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

use RuntimeException;

/**
 * The transformer-owned classification of the core metadata snapshot. Runtime
 * registration answers availability; it never changes this capability policy.
 */
final class CoreBlockCapabilityMatrix
{
    public const SCHEMA = 'blocks-engine/php-transformer/core-block-capabilities/v1';

    private const HTML_INFERABLE = array('core/audio', 'core/button', 'core/buttons', 'core/code', 'core/column', 'core/columns', 'core/cover', 'core/details', 'core/embed', 'core/file', 'core/gallery', 'core/group', 'core/heading', 'core/icon', 'core/image', 'core/list', 'core/list-item', 'core/math', 'core/media-text', 'core/navigation', 'core/navigation-link', 'core/navigation-submenu', 'core/paragraph', 'core/preformatted', 'core/pullquote', 'core/quote', 'core/search', 'core/separator', 'core/shortcode', 'core/spacer', 'core/table', 'core/video');
    private const SEMANTIC_MODEL = array('core/accordion', 'core/accordion-heading', 'core/accordion-item', 'core/accordion-panel', 'core/block', 'core/breadcrumbs', 'core/comment-author-name', 'core/comment-content', 'core/comment-date', 'core/comment-edit-link', 'core/comment-reply-link', 'core/comment-template', 'core/comments', 'core/comments-pagination', 'core/comments-pagination-next', 'core/comments-pagination-numbers', 'core/comments-pagination-previous', 'core/comments-title', 'core/footnotes', 'core/home-link', 'core/navigation-overlay-close', 'core/page-list', 'core/page-list-item', 'core/pattern', 'core/post-author', 'core/post-author-biography', 'core/post-author-name', 'core/post-comments-count', 'core/post-comments-form', 'core/post-comments-link', 'core/post-content', 'core/post-date', 'core/post-excerpt', 'core/post-featured-image', 'core/post-navigation-link', 'core/post-template', 'core/post-terms', 'core/post-time-to-read', 'core/post-title', 'core/query', 'core/query-no-results', 'core/query-pagination', 'core/query-pagination-next', 'core/query-pagination-numbers', 'core/query-pagination-previous', 'core/query-title', 'core/query-total', 'core/read-more', 'core/site-logo', 'core/site-tagline', 'core/site-title', 'core/template-part', 'core/term-count', 'core/term-description', 'core/term-name', 'core/term-template', 'core/terms-query');
    private const PROVIDER_RUNTIME = array('core/archives', 'core/avatar', 'core/calendar', 'core/categories', 'core/latest-comments', 'core/latest-posts', 'core/loginout', 'core/more', 'core/nextpage', 'core/playlist', 'core/playlist-track', 'core/rss', 'core/social-link', 'core/social-links', 'core/tag-cloud');
    private const VERSION_GATED = array('core/tab-list', 'core/tab-panel', 'core/tab-panels', 'core/tabs');
    private const NON_TARGETED = array('core/freeform', 'core/html', 'core/legacy-widget', 'core/missing', 'core/text-columns', 'core/verse', 'core/widget-group');
    // Preserve the established coverage order; later implementations append in name order.
    private const TRANSFORMER_OUTPUT_ORDER = array('core/audio', 'core/button', 'core/buttons', 'core/code', 'core/column', 'core/columns', 'core/details', 'core/embed', 'core/file', 'core/gallery', 'core/group', 'core/heading', 'core/icon', 'core/image', 'core/list', 'core/list-item', 'core/math', 'core/navigation', 'core/navigation-link', 'core/paragraph', 'core/preformatted', 'core/pullquote', 'core/quote', 'core/separator', 'core/shortcode', 'core/spacer', 'core/navigation-submenu', 'core/table', 'core/video', 'core/search');

    /** @return array<string,array<string,mixed>> */
    public function blocks(): array
    {
        $blocks = array();
        foreach (self::HTML_INFERABLE as $name) $blocks[$name] = $this->entry('html_inferable', 'implemented', 'contract_tested');
        foreach (self::SEMANTIC_MODEL as $name) $blocks[$name] = $this->entry('semantic_model_site_plan', 'not_implemented', 'not_verified');
        foreach (self::PROVIDER_RUNTIME as $name) $blocks[$name] = $this->entry('provider_runtime', 'not_implemented', 'not_verified');
        foreach (self::VERSION_GATED as $name) $blocks[$name] = $this->entry('version_gated', 'not_implemented', 'not_verified', '7.1', 'https://github.com/Automattic/blocks-engine/issues/924');
        foreach (self::NON_TARGETED as $name) $blocks[$name] = $this->entry('intentionally_non_targeted', 'not_applicable', 'not_applicable');
        ksort($blocks, SORT_STRING);
        return $blocks;
    }

    /** Fails closed when a generated metadata snapshot gains an unclassified block. */
    public function assertCoversSnapshot(): void
    {
        $snapshot = $this->snapshotBlockNames();
        $matrix = array_keys($this->blocks());
        $missing = array_values(array_diff($snapshot, $matrix));
        $stale = array_values(array_diff($matrix, $snapshot));
        if (array() !== $missing || array() !== $stale) {
            throw new RuntimeException('Core block capability matrix does not match the bundled metadata snapshot. Missing: ' . implode(', ', $missing) . '; stale: ' . implode(', ', $stale));
        }
    }

    /**
     * CI/contract boundary for live registries. Production reports unknown core
     * blocks so a newer WordPress runtime remains transformable while policy is
     * updated deliberately.
     *
     * @param array<int,string> $available
     */
    public function assertClassifiesAvailableBlocks(array $available): void
    {
        $available = array_values(array_unique($available));
        sort($available, SORT_STRING);
        $unclassified = array_values(array_diff($available, array_keys($this->blocks())));
        if (array() !== $unclassified) {
            throw new RuntimeException('Core block capability matrix has no classification for available runtime block(s): ' . implode(', ', $unclassified));
        }
    }

    /** @param array<int,string> $available @return array<string,mixed> */
    public function coverage(array $available): array
    {
        $matrix = $this->blocks();
        $available = array_values(array_unique($available));
        sort($available, SORT_STRING);
        $unclassified = array_values(array_diff($available, array_keys($matrix)));
        $summary = array();
        foreach ($matrix as $entry) {
            $key = $entry['applicability'] . ':' . $entry['implementation'] . ':' . $entry['verification'];
            $summary[$key] = ($summary[$key] ?? 0) + 1;
        }
        ksort($summary, SORT_STRING);
        return array('schema' => self::SCHEMA, 'snapshot_block_count' => count($matrix), 'runtime_available_blocks' => $available, 'unclassified_runtime_blocks' => $unclassified, 'supported_blocks' => $this->supportedBlocks($matrix), 'summary' => $summary, 'blocks' => $matrix);
    }

    /** @param array<string,array<string,mixed>> $matrix @return array<int,string> */
    private function supportedBlocks(array $matrix): array
    {
        $supported = array_keys(array_filter($matrix, static fn(array $entry): bool => 'implemented' === $entry['implementation'] && 'contract_tested' === $entry['verification']));
        $ordered = array_values(array_filter(self::TRANSFORMER_OUTPUT_ORDER, static fn(string $name): bool => in_array($name, $supported, true)));
        $remaining = array_values(array_diff($supported, $ordered));
        sort($remaining, SORT_STRING);
        return array_merge($ordered, $remaining);
    }

    /** @return array<int,string> */
    private function snapshotBlockNames(): array
    {
        $path = dirname(__DIR__, 2) . '/resources/wordpress-latest-core-block-attributes.json';
        $snapshot = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($snapshot['blocks'] ?? null)) throw new RuntimeException('Bundled core block metadata snapshot is unavailable.');
        $blocks = array_keys($snapshot['blocks']);
        sort($blocks, SORT_STRING);
        return $blocks;
    }

    /** @return array<string,mixed> */
    private function entry(string $applicability, string $implementation, string $verification, string $minimumRuntime = '', string $tracker = ''): array
    {
        return array_filter(array('applicability' => $applicability, 'implementation' => $implementation, 'verification' => $verification, 'minimum_runtime' => $minimumRuntime, 'tracker' => $tracker), static fn(string $value): bool => '' !== $value);
    }
}
