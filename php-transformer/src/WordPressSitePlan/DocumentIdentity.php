<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlMarkupParser;

/**
 * Which compiled pages and template parts can become site-plan documents.
 *
 * Each document needs a safe, unique source path and a block markup body. A
 * compiled site that carries any document without them fails closed, and the
 * failures are reported as bounded diagnostics naming every offender.
 */
final class DocumentIdentity
{
    public const MAX_DIAGNOSTICS = 50;

    /**
     * Collect every compiled page or template part that cannot become a site-plan document.
     *
     * @param array<string,mixed> $compiledSite
     * @return array<int,array{source_path:string,reason:string,document_kind:string}>
     */
    public static function compiledSiteFailures(array $compiledSite): array
    {
        $pages = ArrayField::array($compiledSite, 'pages');
        $parts = ArrayField::array($compiledSite, 'template_parts');
        $parts = array_values(array_filter($parts, static fn (mixed $part): bool => is_array($part) && 'entry_shell' !== ($part['placement']['kind'] ?? null)));

        return array_merge(
            self::failures($pages, 'page'),
            self::failures($parts, 'template_part')
        );
    }

    /**
     * @param mixed $documents
     * @return array<int,array{source_path:string,reason:string,document_kind:string}>
     */
    public static function failures(mixed $documents, string $documentKind): array
    {
        if ( ! is_array($documents) ) {
            return array();
        }
        $failures = array();
        foreach ( $documents as $document ) {
            if ( ! is_array($document) || ! WordPressSitePlan::safePath($document['source_path'] ?? null) ) {
                $failures[] = array(
                    'source_path' => is_array($document) && is_string($document['source_path'] ?? null) ? $document['source_path'] : '',
                    'reason' => 'unsafe_identity',
                    'document_kind' => $documentKind,
                );
                continue;
            }
            if ('page' === $documentKind && self::isEmptyRedirect($document)) continue;
            // Native term captures may contain no presentation at all. Defer
            // their content validation until taxonomy ownership is resolved;
            // any unproven capture still faces the final page contract.
            if ('page' === $documentKind) {
                $classes = DocumentRootContext::bodyClasses($document);
                if (in_array('archive', $classes, true) && (in_array('tag', $classes, true) || in_array('category', $classes, true))) continue;
            }
            if ( ! is_string($document['block_markup'] ?? null) || '' === trim($document['block_markup']) ) {
                $failures[] = array(
                    'source_path' => $document['source_path'],
                    'reason' => 'empty_block_markup',
                    'document_kind' => $documentKind,
                );
            }
        }

        return $failures;
    }

    /**
     * @param array<int,array{source_path:string,reason:string,document_kind:string}> $failures
     * @return array<int,array<string,mixed>>
     */
    public static function diagnostics(array $failures): array
    {
        $total = count($failures);
        if ( 0 === $total ) {
            return array();
        }
        $retained = array_slice($failures, 0, self::MAX_DIAGNOSTICS);
        $diagnostics = array();
        foreach ( $retained as $failure ) {
            $diagnostics[] = self::diagnostic($failure, $total);
        }
        if ( $total > count($retained) ) {
            $diagnostics[] = array(
                'code' => 'wordpress_site_plan_not_self_contained',
                'severity' => 'error',
                'message' => sprintf('%d compiled site documents lack a safe identity or block markup; %d omitted from this diagnostic list.', $total, $total - count($retained)),
                'reason' => 'truncated',
                'document_count' => $total,
                'omitted_count' => $total - count($retained),
                'reason_code' => 'wordpress_site_plan_not_self_contained',
                'pattern_family' => 'site_plan_document',
                'repair_bucket' => 'restore_compiled_document_identity',
            );
        }

        return $diagnostics;
    }

    /**
     * @param array{source_path:string,reason:string,document_kind:string} $failure
     * @return array<string,mixed>
     */
    private static function diagnostic(array $failure, int $documentCount): array
    {
        $path = mb_strcut($failure['source_path'], 0, 256, 'UTF-8');
        $kind = 'template_part' === $failure['document_kind'] ? 'template part' : 'page';
        $named = '' === $path ? 'Compiled ' . $kind : 'Compiled ' . $kind . ' "' . $path . '"';
        $message = 'unsafe_identity' === $failure['reason']
            ? $named . ' lacks a safe source path.'
            : $named . ' has empty block markup.';

        return array(
            'code' => 'wordpress_site_plan_not_self_contained',
            'severity' => 'error',
            'message' => mb_strcut($message, 0, 256, 'UTF-8'),
            'source_path' => $path,
            'document_kind' => substr($failure['document_kind'], 0, 64),
            'reason' => substr($failure['reason'], 0, 64),
            'document_count' => $documentCount,
            'reason_code' => 'wordpress_site_plan_not_self_contained',
            'pattern_family' => 'site_plan_document',
            'repair_bucket' => 'restore_compiled_document_identity',
        );
    }

    /** @param array<string,mixed> $document */
    public static function isEmptyRedirect(array $document): bool
    {
        if (!empty($document['entrypoint']) || !is_string($document['html'] ?? null)) return false;
        $html = $document['html'];
        $redirect = false;
        foreach (HtmlMarkupParser::htmlMarkupNodes($html) as $node) {
            if ('meta' === ($node['name'] ?? null) && 'refresh' === strtolower((string) ($node['attributes']['http-equiv'] ?? ''))) $redirect = true;
        }
        $body = preg_replace('~<(head|script|style)\\b[^>]*>.*?</\\1>~is', '', $html) ?? $html;
        return $redirect && '' === trim(strip_tags($body)) && !preg_match('~<(?:img|video|audio|iframe|svg|canvas|form)\\b~i', $body);
    }
}
