<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use DOMDocument;
use DOMElement;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

/** Conservative supplements for a sole product with an already joined purchase binding. */
final class ProductDetailPageEvidence
{
    private static function imageIdentity(string $url, string $source): string
    {
        // Resolve relative paths without dropping queries (often rendition selectors).
        $query = parse_url($url, PHP_URL_QUERY);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return $host . '/' . SchemaProductEvidence::urlRoute($url, $source) . (is_string($query) ? '?' . $query : '');
    }

    public static function fill(array &$row, array $schema, array $purchase, array $files, array $fallbacks, array &$diagnostics): void
    {
        $source = $purchase['source'] ?? $row['source_path'];
        $record = static function (string $field) use (&$diagnostics, $source, $row, $purchase): void {
            $diagnostics[] = array('code' => 'product_page_field_derived', 'severity' => 'info', 'source_path' => $source, 'slug' => $row['slug'], 'field' => $field, 'selector' => $purchase['selector'] ?? '', 'message' => 'Product ' . $field . ' filled from joined detail-page evidence.');
        };
        if (!array_key_exists('hasVariant', $schema) && !isset($row['variants']) && !in_array('ProductGroup', (array) ($schema['@type'] ?? array()), true)) {
            $combinations = array(array());
            foreach ($purchase['controls'] ?? array() as $control) {
                if ('select' !== ($control['tag'] ?? '') || empty($control['label'])) continue;
                $options = array();
                foreach ($control['options'] ?? array() as $option) {
                    $value = trim((string) ($option['value'] ?? ''));
                    if ('' === $value || !empty($option['disabled'])) continue;
                    $key = SchemaProductEvidence::productSlug($control['label']);
                    $valueSlug = SchemaProductEvidence::productSlug($value);
                    if ('' === $key || '' === $valueSlug) continue;
                    $options[$valueSlug] = array('key' => $key, 'label' => $control['label'], 'value' => $valueSlug, 'value_label' => $option['label'] ?? $value);
                }
                if (!$options) continue;
                if (count($combinations) * count($options) > 1000) {
                    $combinations = array_fill(0, 1001, array($options[array_key_first($options)]));
                    break;
                }
                $next = array();
                foreach ($combinations as $combination) foreach ($options as $option) $next[] = array_merge($combination, array($option));
                $combinations = $next;

            }
            if ($combinations[0] ?? array()) {
                $offer = $schema['offers'] ?? array();
                if (array_is_list($offer)) $offer = $offer[0] ?? array();
                $price = SchemaProductEvidence::productPrice($offer['price'] ?? null);
                $low = SchemaProductEvidence::productPrice($offer['lowPrice'] ?? null);
                $high = SchemaProductEvidence::productPrice($offer['highPrice'] ?? null);
                if ('' === $price && '' !== $low && '' !== $high && (float) $low === (float) $high) $price = $low;
                $countMatches = !isset($offer['offerCount']) || (is_numeric($offer['offerCount']) && (float) $offer['offerCount'] === (float) count($combinations));
                if ('' !== $price && $countMatches && count($combinations) <= 1000) {
                    $row['variants'] = array_map(static fn (array $attributes): array => array('attributes' => $attributes, 'regular_price' => $price, 'stock_status' => $row['stock_status'] ?? 'instock'), $combinations);
                    $record('variants');
                } else {
                    $diagnostics[] = array('code' => 'product_page_variants_declined', 'severity' => 'warning', 'source_path' => $source, 'slug' => $row['slug'], 'message' => 'Purchase combinations require a uniform offer price and matching offerCount.', 'combination_count' => count($combinations));
                }
            }
        }
        $html = '';
        foreach ($files as $file) if ($source === ($file['path'] ?? null)) $html = $file['content'] ?? '';
        if ('' === $html) return;
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $owner = null;
        foreach ($document->getElementsByTagName('*') as $element) {
            if (SourceDom::elementSelector($element) === ($purchase['selector'] ?? '')) { $owner = $element; break; }
        }
        if (!$owner instanceof DOMElement) return;
        if (!isset($row['images']) && isset($row['image'])) {
            // The nearest non-document ancestor containing images is the detail container.
            for ($container = $owner; $container instanceof DOMElement && !in_array(strtolower($container->tagName), array('body', 'html'), true); $container = $container->parentNode) {
                $images = $container->getElementsByTagName('img');
                if (0 === $images->length) continue;
                $groups = array();
                foreach ($images as $image) {
                    $src = trim($image->getAttribute('src'));
                    if ('' === $src) continue;
                    $urls = array(self::imageIdentity($src, $source));
                    foreach (explode(',', $image->getAttribute('srcset')) as $candidate) {
                        $url = preg_split('/\s+/', trim($candidate))[0] ?? '';
                        if ('' !== $url) $urls[] = self::imageIdentity($url, $source);
                    }
                    $group = array('urls' => $urls, 'row' => array('src' => $src, 'alt' => $image->getAttribute('alt')));
                    // Merge connected responsive identities, including later bridging thumbnails.
                    foreach ($groups as $index => $existing) {
                        if (!array_intersect($group['urls'], $existing['urls'])) continue;
                        $group['urls'] = array_unique(array_merge($group['urls'], $existing['urls']));
                        if ($existing['order'] < ($group['order'] ?? PHP_INT_MAX)) {
                            $group['row'] = $existing['row'];
                            $group['order'] = $existing['order'];
                        }
                        unset($groups[$index]);
                    }
                    $group['order'] ??= count($groups) ? max(array_column($groups, 'order')) + 1 : 0;
                    $groups[] = $group;
                }
                usort($groups, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
                foreach ($groups as $index => $group) {
                    if (!in_array(self::imageIdentity($row['image'], $source), $group['urls'], true)) continue;
                    $first = $group['row'];
                    $first['src'] = $row['image'];
                    unset($groups[$index]);
                    $row['images'] = array_merge(array($first), array_column($groups, 'row'));
                    $record('images');
                    break;
                }
                break;
            }
        }
        if (isset($row['category']) || array_key_exists('category', $schema)) return;
        $listingRoutes = array();
        foreach ($fallbacks as $fallback) {
            if ('html_product_grid_fallback' !== ($fallback['diagnostic_code'] ?? '')) continue;
            foreach ($fallback['products'] ?? array() as $card) {
                if (SchemaProductEvidence::urlRoute($card['url'] ?? '', $fallback['source'] ?? '') !== SchemaProductEvidence::urlRoute($row['url'], $source)) continue;
                $listingRoutes[] = SchemaProductEvidence::urlRoute('/' . ($fallback['source'] ?? ''), $source);
            }
        }
        foreach ($document->getElementsByTagName('a') as $link) {
            if (SchemaProductEvidence::urlRoute($link->getAttribute('href'), $source) !== SchemaProductEvidence::urlRoute($row['url'], $source)) continue;
            $trail = $link->parentNode;
            // A list item wraps one breadcrumb, not the whole trail.
            if ($trail instanceof DOMElement && 'li' === strtolower($trail->tagName)) $trail = $trail->parentNode;
            if (!$trail instanceof DOMElement || in_array(strtolower($trail->tagName), array('body', 'main', 'html'), true)) continue;
            $links = iterator_to_array($trail->getElementsByTagName('a'));
            if (end($links) !== $link) continue;
            foreach ($links as $candidate) {
                if (!in_array(SchemaProductEvidence::urlRoute($candidate->getAttribute('href'), $source), $listingRoutes, true)) continue;
                $category = trim($candidate->textContent);
                if ('' !== $category) { $row['category'] = $category; $record('category'); return; }
            }
        }
    }
}
