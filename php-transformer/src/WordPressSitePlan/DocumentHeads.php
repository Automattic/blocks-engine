<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Closure;

/**
 * Resolves the document head metadata one site plan run carries.
 *
 * Each link and script row is bound to a captured asset token or an artifact
 * route. A local link that names neither is omitted and reported as a bounded,
 * deduplicated warning; a head item WordPress does not render is dropped and
 * recorded as a unit finding.
 */
final class DocumentHeads
{
    private const MAX_OMITTED_LINK_DECLARATION_DIAGNOSTICS = 50;
    private const OMITTED_LINK_DECLARATION_DIAGNOSTIC_CODE = 'wordpress_site_plan_omitted_link_declaration';
    /** @var array<string,array<string,mixed>> */
    private array $omittedLinkDeclarations = array();
    /** @var array<string,array<string,bool>> */
    private array $omittedLinkDeclarationPages = array();
    /** @var array<string,bool> */
    private array $omittedLinkDeclarationOverflow = array();

    /** @param Closure(array<string,mixed>):void $recordFinding */
    public function __construct(private readonly RouteLinks $links, private readonly Closure $recordFinding)
    {
    }

    /** @param array<string,mixed> $document @param array<int,array<string,string>> $tokens @return array<string,mixed> */
    public function metadata(array $document, AssetReferenceCanonicalizer $references, array $routes): array
    {
        $metadata = is_array($document['document_metadata'] ?? null) ? $document['document_metadata'] : array('source_context' => array('source_path' => WordPressSitePlan::value($document, 'source_path'), 'kind' => 'document'), 'title' => WordPressSitePlan::value($document, 'title'), 'title_declaration' => array('order' => 0, 'placement' => 'head'), 'meta' => array(), 'links' => array(), 'scripts' => array());
        foreach (array('links', 'scripts') as $kind) {
            if (!is_array($metadata[$kind] ?? null)) $metadata[$kind] = array();
            foreach ($metadata[$kind] as &$row) {
                if (!is_array($row) || !is_string($row['url'] ?? null)) continue;
                $reference = $this->assetReference($row['url'], WordPressSitePlan::value($document, 'source_path'), $references, $routes);
                if (null !== $reference) {
                    $row['asset_reference'] = $reference;
                    unset($row['url']);
                    continue;
                }
                if ('links' !== $kind) continue;
                $route = $this->links->reference($row['url'], WordPressSitePlan::value($document, 'source_path'), $routes);
                if (null !== $route) { $row['url'] = $route; continue; }
                // A local link that names neither a captured asset nor a route has nothing to bind.
                // Omit it and say so; the strict link validator stays the integrity backstop.
                if (WordPressSitePlan::explicitUrl($row['url'])) continue;
                $this->recordOmittedLink(WordPressSitePlan::value($document, 'source_path'), $row);
                $row = null;
            }
            unset($row);
            $metadata[$kind] = array_values(array_filter($metadata[$kind], static fn(mixed $row): bool => is_array($row)));
            foreach ($metadata[$kind] as $index => &$row) $row['order'] = $index;
            unset($row);
        }
        if ('html' === ($metadata['source_context']['kind'] ?? null)) foreach (self::unrenderedItems($metadata) as $drop) $this->recordDroppedItem(WordPressSitePlan::value($document, 'source_path'), $drop);
        return $metadata;
    }

    /**
     * Records one omitted link declaration. An omission is a reportable quality
     * defect, not a silent edit: the plan keeps a bounded, deduplicated warning
     * per declaration so a consumer can show what the head lost.
     *
     * These land on the plan's own `diagnostics`, beside
     * `wordpress_site_plan_unresolved_navigation_link`. They are not envelope
     * diagnostics, so they do not reach `source_reports.wordpress_site_plan_diagnostics`
     * or `WordPressSitePlanView::diagnostics()`, which carry the failure
     * channel; a consumer wanting these reads the plan.
     *
     * @param array<string,mixed> $link
     */
    private function recordOmittedLink(string $sourcePath, array $link): void
    {
        $url = (string) ($link['url'] ?? '');
        $relation = trim((string) preg_replace('/\s+/', ' ', (string) ($link['rel'] ?? '')));
        // Keyed on the declaration rather than the page. WordPress prints the
        // same discovery links in every head, so keying on the page spends the
        // whole budget restating one site's boilerplate and names no other
        // defect: a 60-page capture reported 5 facts about 10 pages and gave
        // up. One row per distinct declaration, with the page it was first seen
        // on and how many pages carried it.
        //
        // Pages, not tags: fullDocumentMetadata() keeps duplicate links, so a
        // head that prints one pingback twice would otherwise report two
        // affected pages when it has one.
        $key = $relation . "\0" . $url;
        if (isset($this->omittedLinkDeclarations[$key])) { $this->omittedLinkDeclarationPages[$key][$sourcePath] = true; return; }
        // Counted as distinct declarations, the same unit as the rows above it,
        // so the listed rows and the remainder add up.
        if (count($this->omittedLinkDeclarations) >= self::MAX_OMITTED_LINK_DECLARATION_DIAGNOSTICS) { $this->omittedLinkDeclarationOverflow[$key] = true; return; }
        $label = $relation . ' link';
        $this->omittedLinkDeclarationPages[$key][$sourcePath] = true;
        $this->omittedLinkDeclarations[$key] = array_filter(array(
            'code' => self::OMITTED_LINK_DECLARATION_DIAGNOSTIC_CODE,
            'severity' => 'warning',
            'message' => UnitFindings::clip("Omitted {$url} ({$label} declaration), which names neither a captured asset nor an artifact route."),
            'source_path' => UnitFindings::clip($sourcePath),
            'rel' => UnitFindings::clip($relation),
            'value' => UnitFindings::clip($url),
            'reason_code' => 'unresolved_local_url',
        ), static fn(mixed $field): bool => '' !== $field);
    }

    /**
     * WordPress renders the document head: core owns charset and viewport,
     * platform fields own route SEO and the site icon, and stylesheets and
     * executable scripts are enqueued. Any other source head item is a loss.
     * @param array<string,mixed> $metadata
     * @return list<array{code:string,reason_code:string,tag:string,name:string}>
     */
    private static function unrenderedItems(array $metadata): array
    {
        $drops = array();
        $drop = static function (string $tag, string $name) use (&$drops): void { $drops[] = array('code' => 'document_head_element_dropped', 'reason_code' => 'unrendered', 'tag' => $tag, 'name' => $name); };
        foreach ($metadata['meta'] ?? array() as $row) {
            if ('head' !== ($row['placement'] ?? null) || isset($row['charset'])) continue;
            $equiv = strtolower(trim((string) ($row['http_equiv'] ?? '')));
            $name = strtolower(trim((string) ($row['name'] ?? $row['property'] ?? '')));
            if ('' !== $equiv) { if ('content-type' !== $equiv) $drop('meta', 'http-equiv=' . $equiv); continue; }
            if (in_array($name, array('viewport', 'description', 'robots', 'googlebot', 'bingbot', 'generator'), true) || str_starts_with($name, 'og:') || str_starts_with($name, 'twitter:')) continue;
            $drop('meta', $name);
        }
        foreach ($metadata['links'] ?? array() as $row) {
            if ('head' !== ($row['placement'] ?? null)) continue;
            $relations = preg_split('/\s+/', strtolower(trim((string) ($row['rel'] ?? '')))) ?: array();
            if (array() === array_diff($relations, array('stylesheet', 'icon', 'shortcut', 'apple-touch-icon', 'canonical', 'alternate', 'next', 'prev'))) continue;
            $drop('link', implode(' ', $relations));
        }
        foreach ($metadata['scripts'] ?? array() as $row) {
            if ('head' === ($row['placement'] ?? null) && 'inline' === ($row['source_kind'] ?? null) && !DocumentScripts::isExecutableType((string) ($row['type'] ?? ''), false)) $drop('script', strtolower((string) ($row['type'] ?? '')));
        }
        return $drops;
    }

    /** @param array{code:string,reason_code:string,tag:string,name:string} $drop */
    private function recordDroppedItem(string $sourcePath, array $drop): void
    {
        ($this->recordFinding)(array_filter(array(
            'code' => $drop['code'],
            'unit' => 'item',
            'message' => sprintf('Document head <%s> %s is not rendered by WordPress (%s); it was dropped.', $drop['tag'], $drop['name'], $drop['reason_code']),
            'source_path' => $sourcePath,
            'reason_code' => $drop['reason_code'],
            'tag' => $drop['tag'],
            'name' => $drop['name'],
        ), static fn(string $value): bool => '' !== $value));
    }

    /** @return array<int,array<string,mixed>> */
    public function diagnostics(): array
    {
        $diagnostics = array();
        foreach ($this->omittedLinkDeclarations as $key => $diagnostic) {
            $diagnostic['occurrences'] = count($this->omittedLinkDeclarationPages[$key] ?? array());
            $diagnostics[] = $diagnostic;
        }
        $overflow = count($this->omittedLinkDeclarationOverflow);
        if ($overflow > 0) $diagnostics[] = array('code' => self::OMITTED_LINK_DECLARATION_DIAGNOSTIC_CODE, 'severity' => 'warning', 'message' => sprintf('%d more distinct unresolved link declarations were omitted; omitted from this diagnostic list.', $overflow), 'reason' => 'truncated', 'omitted_count' => $overflow);
        return $diagnostics;
    }

    /** @param array<int,array<string,mixed>> $routes */
    private function assetReference(string $url, string $sourcePath, AssetReferenceCanonicalizer $references, array $routes): ?string
    {
        $reference = $references->reference($url, $sourcePath);
        if (null !== $reference) return $reference;
        $entryRoot = PageRoute::entryRoot($routes);
        if ('' === $entryRoot) return null;
        $rooted = ArtifactPath::resolveRelativePath(ltrim($url, '/'), $entryRoot . '/index.html');
        $reference = '' === $rooted ? null : $references->reference('/' . $rooted, $sourcePath);
        return $reference ?? $references->reference($url, '');
    }
}
