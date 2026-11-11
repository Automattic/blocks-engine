<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
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

    /** Connected rendition identities, retaining the first image's preferred URL and DOM order. */
    private static function imageGroups(DOMElement $container, string $source): array
    {
        $groups = array();
        foreach ($container->getElementsByTagName('img') as $order => $image) {
            $urls = array();
            $src = '';
            foreach (array('data-image', 'data-src', 'src') as $attribute) {
                $url = trim($image->getAttribute($attribute));
                if ('' === $url) continue;
                if ('' === $src) $src = $url;
                $urls[] = self::imageIdentity($url, $source);
            }
            foreach (array('srcset', 'data-srcset') as $attribute) {
                foreach (explode(',', $image->getAttribute($attribute)) as $candidate) {
                    $url = preg_split('/\s+/', trim($candidate))[0] ?? '';
                    if ('' !== $url) $urls[] = self::imageIdentity($url, $source);
                }
            }
            if ('' === $src) continue;
            $group = array('urls' => $urls, 'order' => $order, 'row' => array('src' => $src, 'alt' => $image->getAttribute('alt')));
            foreach ($groups as $index => $existing) {
                if (!array_intersect($group['urls'], $existing['urls'])) continue;
                $group['urls'] = array_unique(array_merge($group['urls'], $existing['urls']));
                if ($existing['order'] < $group['order']) {
                    $group['row'] = $existing['row'];
                    $group['order'] = $existing['order'];
                }
                unset($groups[$index]);
            }
            $groups[] = $group;
        }
        usort($groups, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        return $groups;
    }

    /** Resolve only the caption of this control's own option group. */
    private static function optionGroupLabel(DOMElement $select): string
    {
        $group = $select->parentNode;
        while ($group instanceof DOMElement && 'fieldset' !== strtolower($group->tagName) && 'group' !== $group->getAttribute('role')) {
            $group = $group->parentNode;
        }
        if (!$group instanceof DOMElement) return '';
        $caption = '';
        if ('fieldset' === strtolower($group->tagName)) {
            foreach ($group->childNodes as $child) {
                if ($child instanceof DOMElement && 'legend' === strtolower($child->tagName)) {
                    $caption = $child->textContent;
                    break;
                }
            }
        }
        if ('' === trim($caption)) {
            $parts = array();
            $visit = static function (DOMElement $element) use (&$visit, &$parts, $select): bool {
                if ($element === $select) return false;
                // Never borrow option text or another control/group's caption.
                if (in_array(strtolower($element->tagName), array('input', 'select', 'textarea', 'button', 'fieldset', 'script', 'style'), true) || 'group' === $element->getAttribute('role')) return true;
                $containsSelect = false;
                $containsControl = false;
                foreach ($element->getElementsByTagName('*') as $descendant) {
                    if ($descendant === $select) $containsSelect = true;
                    if (in_array(strtolower($descendant->tagName), array('input', 'select', 'textarea', 'button'), true)) $containsControl = true;
                }
                if ($containsControl && !$containsSelect) return true;
                foreach ($element->childNodes as $child) {
                    if ($child instanceof DOMElement && !$visit($child)) return false;
                    if ($child instanceof \DOMText) $parts[] = $child->textContent;
                }
                return true;
            };
            foreach ($group->childNodes as $child) {
                if ($child instanceof DOMElement && !$visit($child)) break;
            }
            $caption = implode(' ', $parts);
        }
        return rtrim(trim(preg_replace('/\s+/u', ' ', $caption) ?? ''), ': ');
    }

    public static function fill(array &$row, array $schema, array $purchase, array $files, array $pageRoutes, string $entryRoot, array &$diagnostics): void
    {
        $source = $purchase['source'] ?? $row['source_path'];
        $record = static function (string $field) use (&$diagnostics, $source, $row, $purchase): void {
            $diagnostics[] = array('code' => 'product_page_field_derived', 'severity' => 'info', 'source_path' => $source, 'slug' => $row['slug'], 'field' => $field, 'selector' => $purchase['selector'] ?? '', 'message' => 'Product ' . $field . ' filled from joined detail-page evidence.');
        };
        $html = '';
        foreach ($files as $file) if ($source === ($file['path'] ?? null)) $html = $file['content'] ?? '';
        $elements = array();
        if ('' !== $html) {
            $document = HtmlSerialization::parseDocument($html);
            foreach ($document->getElementsByTagName('*') as $element) $elements[SourceDom::elementSelector($element)] = $element;
        }
        if (!array_key_exists('hasVariant', $schema) && !isset($row['variants']) && !in_array('ProductGroup', (array) ($schema['@type'] ?? array()), true)) {
            $combinations = array(array());
            foreach ($purchase['controls'] ?? array() as $control) {
                if ('select' !== ($control['tag'] ?? '')) continue;
                $label = trim((string) ($control['label'] ?? ''));
                $select = $elements[$control['selector'] ?? ''] ?? null;
                if ('' === $label && $select instanceof DOMElement) $label = self::optionGroupLabel($select);
                if ('' === $label) continue;
                $options = array();
                foreach ($control['options'] ?? array() as $option) {
                    $value = trim((string) ($option['value'] ?? ''));
                    if ('' === $value || !empty($option['disabled'])) continue;
                    $key = SchemaProductEvidence::productSlug($label);
                    $valueSlug = SchemaProductEvidence::productSlug($value);
                    if ('' === $key || '' === $valueSlug) continue;
                    $options[$valueSlug] = array('key' => $key, 'label' => $label, 'value' => $valueSlug, 'value_label' => $option['label'] ?? $value);
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
        $owner = $elements[$purchase['selector'] ?? ''] ?? null;
        if (!$owner instanceof DOMElement) return;
        if (!isset($row['images']) && isset($row['image'])) {
            $declaredIdentity = self::imageIdentity($row['image'], $source);
            $displayed = false;
            foreach (self::imageGroups($document->documentElement, $source) as $group) {
                if (in_array($declaredIdentity, $group['urls'], true)) $displayed = true;
            }
            // A metadata-only primary needs a multi-photo gallery, not a purchase badge.
            for ($container = $owner; $container instanceof DOMElement && !in_array(strtolower($container->tagName), array('body', 'html'), true); $container = $container->parentNode) {
                $groups = self::imageGroups($container, $source);
                if (!$displayed) {
                    // Purchase-column decorations are not photos in the sibling gallery.
                    $purchaseGroups = self::imageGroups($owner, $source);
                    $branch = $owner;
                    while ($branch->parentNode instanceof DOMElement && $branch->parentNode !== $container) $branch = $branch->parentNode;
                    if ($branch !== $container) $purchaseGroups = self::imageGroups($branch, $source);
                    $purchaseUrls = array_merge(array(), ...array_column($purchaseGroups, 'urls'));
                    $groups = array_values(array_filter($groups, static fn (array $group): bool => !array_intersect($group['urls'], $purchaseUrls)));
                    if (count($groups) < 2) continue;
                    $row['images'] = array_column($groups, 'row');
                    $record('images');
                    break;
                }
                foreach ($groups as $index => $group) {
                    if (!in_array(self::imageIdentity($row['image'], $source), $group['urls'], true)) continue;
                    $first = $group['row'];
                    unset($groups[$index]);
                    $row['images'] = array_merge(array($first), array_column($groups, 'row'));
                    $record('images');
                    break;
                }
                if (isset($row['images'])) break;
            }
        }
        if (isset($row['category']) || array_key_exists('category', $schema)) return;
        $route = static fn (string $url, string $path): string => SchemaProductEvidence::pageRoute($url, $path, $entryRoot);
        $productRoute = $pageRoutes[$source] ?? $route($row['url'], $source);
        $listingRoutes = array();
        foreach ($files as $file) {
            $path = $file['path'] ?? '';
            if ($path === $source || !isset($pageRoutes[$path])) continue;
            if ('' === ($file['content'] ?? '')) continue;
            $listing = HtmlSerialization::parseDocument($file['content']);
            foreach ($listing->getElementsByTagName('a') as $card) {
                if ($route($card->getAttribute('href'), $path) === $productRoute) $listingRoutes[] = $pageRoutes[$path];
            }
        }
        foreach ($document->getElementsByTagName('a') as $link) {
            if ($route($link->getAttribute('href'), $source) !== $productRoute) continue;
            $trail = $link->parentNode;
            // A list item wraps one breadcrumb, not the whole trail.
            if ($trail instanceof DOMElement && 'li' === strtolower($trail->tagName)) $trail = $trail->parentNode;
            if (!$trail instanceof DOMElement || in_array(strtolower($trail->tagName), array('body', 'main', 'html'), true)) continue;
            $links = iterator_to_array($trail->getElementsByTagName('a'));
            if (end($links) !== $link) continue;
            array_pop($links);
            foreach (array_reverse($links) as $candidate) {
                if (!in_array($route($candidate->getAttribute('href'), $source), $listingRoutes, true)) continue;
                $category = trim($candidate->textContent);
                if ('' !== $category) { $row['category'] = $category; $record('category'); return; }
            }
        }
    }
}
