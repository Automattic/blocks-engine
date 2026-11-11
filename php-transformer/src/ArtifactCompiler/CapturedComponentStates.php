<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use DOMDocument;
use DOMElement;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;

/** State-owned probe evidence from the v2 component inventory. */
final class CapturedComponentStates
{
    /** Reuse evidence only when the source component subtree is identical, never by selector alone.
     * Documents are inspected one at a time; the inventory retains only distinct fingerprints.
     */
    public static function shareEvidence(?array $report, array $files): ?array
    {
        if (!CapturedInteractionReport::valid($report)) return $report;
        $receipt = CaptureReceiptReport::read($files);
        if (null === $receipt) return $report;
        $routes = CaptureReceiptRoutes::resolve($files, $receipt);
        $contents = array_column($files, 'content', 'path');
        $inventory = array();
        foreach ($report['pages'] as $page) {
            if (empty($page['components'])) continue;
            $path = $routes[CaptureReceiptRoutes::normalizedUrl((string) ($page['sourceUrl'] ?? ''))] ?? '';
            if (!is_string($contents[$path] ?? null)) continue;
            $document = self::document($contents[$path]);
            foreach ($page['components'] as $component) {
                $selector = $component['selector'] ?? '';
                if (!is_string($selector) || '' === $selector) continue;
                $parsed = CssSelectorMatcher::parse($selector);
                if (!$parsed) continue;
                foreach ($document->getElementsByTagName('*') as $node) {
                    if (!(CssSelectorMatcher::matches($node, $parsed)['matches'] ?? false)) continue;
                    $hash = self::fingerprint($node);
                    $inventory[$selector][$hash][hash('sha256', serialize($component))] = $component;
                }
            }
        }
        if (!$inventory) return $report;
        foreach ($report['pages'] as &$page) {
            $path = $routes[CaptureReceiptRoutes::normalizedUrl((string) ($page['sourceUrl'] ?? ''))] ?? '';
            if (!is_string($contents[$path] ?? null) || !is_array($page['components'] ?? null)) continue;
            $document = self::document($contents[$path]);
            $components = array();
            $owned = array();
            foreach ($page['components'] as $component) {
                $components[hash('sha256', serialize($component))] = $component;
                if (is_string($component['selector'] ?? null)) $owned[$component['selector']] = true;
            }
            foreach ($inventory as $selector => $identities) {
                // A route that captured this control keeps its own states; other
                // routes' variants only fill controls the route did not capture.
                if (isset($owned[$selector])) continue;
                $parsed = CssSelectorMatcher::parse($selector);
                foreach ($document->getElementsByTagName('*') as $node) {
                    if (!(CssSelectorMatcher::matches($node, $parsed)['matches'] ?? false)) continue;
                    // One control needs one variant; the first captured route supplies it.
                    $variants = $identities[self::fingerprint($node)] ?? array();
                    if (array() !== $variants) $components[array_key_first($variants)] = reset($variants);
                }
            }
            $page['components'] = array_values($components);
        }
        unset($page);
        return $report;
    }

    private static function document(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        HtmlSerialization::loadHtml($document, '<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        return $document;
    }

    private static function fingerprint(DOMElement $node): string
    {
        // A trigger alone is not an identity: include its owning source subtree.
        if (in_array(strtolower($node->tagName), array('button', 'a', 'input'), true) && $node->parentNode instanceof DOMElement) $node = $node->parentNode;
        return hash('sha256', serialize(self::normalizedNode($node)));
    }

    private static function normalizedNode(\DOMNode $node): array
    {
        if (!$node instanceof DOMElement) return array($node->nodeType, $node->nodeValue);
        $attributes = array();
        foreach ($node->attributes as $attribute) {
            if ('style' === $attribute->name) {
                $declarations = CssRuleAnalyzer::declarations($attribute->value, array('*'));
                // Stable property sorting preserves the order of repeated declarations.
                usort($declarations, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
                if ($declarations) $attributes['style'] = $declarations;
                continue;
            }
            $attributes[$attribute->name] = $attribute->value;
        }
        ksort($attributes);
        $children = array();
        foreach ($node->childNodes as $child) {
            if (XML_TEXT_NODE === $child->nodeType && '' === trim((string) $child->nodeValue)) continue;
            $children[] = self::normalizedNode($child);
        }
        return array(strtolower($node->tagName), $attributes, $children);
    }

    /** Retire only an exact recognized helper whose complete inventory was projected. */
    public static function retireRuntime(DOMDocument $document, string $marker, array $selectors): array
    {
        $retired = array();
        foreach (iterator_to_array($document->getElementsByTagName('script')) as $script) {
            if (!$script->hasAttribute($marker)) continue;
            $body = trim($script->textContent);
            $recognized = false;
            if (CaptureEvidence::COMPONENT_RUNTIME === $marker) {
                $recognized = '36922bf2813c545f39a0e9c559c72165e219e59185f477d6f3e207ecfe612aed' === hash('sha256', $body);
                foreach ($document->getElementsByTagName('*') as $root) {
                    if ($root->hasAttribute(CaptureEvidence::TABS) && 'true' !== $root->parentNode?->attributes?->getNamedItem('data-blocks-engine-captured-selectable-set')?->nodeValue) $recognized = false;
                }
            } elseif (CaptureEvidence::COMPONENT_CAROUSEL_RUNTIME === $marker && str_starts_with($body, '(function(){var configs=')) {
                $end = strpos($body, ';configs.forEach');
                if (false !== $end && 'fa1521586b999b80142ed3737550f073df0fbea75c6b2a1e9111bb7ce726d3c1' === hash('sha256', substr($body, $end))) {
                    $configs = json_decode(substr($body, strlen('(function(){var configs='), $end - strlen('(function(){var configs=')), true);
                    $recognized = is_array($configs) && array() !== $configs;
                    foreach ($configs ?? array() as $config) if (!in_array($config['selector'] ?? null, $selectors, true)) $recognized = false;
                }
            }
            if (!$recognized) continue;
            $retired[] = array('body' => $body, 'attribute' => $marker, 'reason' => 'native_component_replaces_capture_replay');
            $script->parentNode?->removeChild($script);
        }
        return $retired;
    }

    /** Remove the matching normalized inline assets and record replacement evidence. */
    public static function omitScripts(array &$files, array $retired): array
    {
        $proofs = array();
        $kept = array();
        foreach (ArtifactNormalizer::inlineOccurrences($files) as $file) {
            $source = (string) ($file['source_path'] ?? '');
            $record = null;
            foreach ($retired[$source] ?? array() as $candidate) if (trim((string) ($file['content'] ?? '')) === $candidate['body']) $record = $candidate;
            if (null === $record || 'inline-script' !== ($file['source'] ?? null)) { $kept[] = $file; continue; }
            $proofs[] = array('schema' => 'blocks-engine/native-runtime-replacement/v1', 'source_path' => $source,
                'asset_source_path' => $file['path'], 'body_hash' => hash('sha256', $record['body']),
                'attribute' => $record['attribute'], 'reason' => $record['reason']);
        }
        $files = (new ArtifactNormalizer())->contentAddressInlineFiles($kept);
        return $proofs;
    }

    /** @return array<int, array<string, mixed>> */
    public static function evidence(array $components, array $kinds): array
    {
        $result = array();
        foreach ($components as $component) {
            if ('slideshow' === ($component['function'] ?? null)) continue;
            if (!is_array($component) || !in_array($component['kind'] ?? null, $kinds, true)
                || !in_array($component['status'] ?? null, array('captured', 'partial'), true)
                || !is_array($component['states'] ?? null)) {
                continue;
            }
            foreach ($component['states'] as $state) {
                if (is_array($state) && is_array($state['evidence'] ?? null)) {
                    // Inventory kind is declared evidence, not a guess from the panel's paint.
                    $probe = $state['evidence'];
                    if (in_array($component['kind'], array('dialog', 'listbox', 'carousel'), true)) {
                        $probe['kind'] = 'carousel' === $component['kind'] && 'gallery' === ($probe['kind'] ?? null) ? 'gallery' : $component['kind'];
                    }
                    $result[] = $probe;
                }
            }
        }
        return $result;
    }
}
