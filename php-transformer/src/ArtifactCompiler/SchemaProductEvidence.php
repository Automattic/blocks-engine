<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Support\HtmlMarkupParser;

/**
 * Provider-neutral schema.org `Product` and `ProductGroup` evidence read from JSON-LD in compiled
 * HTML documents (top-level, `@graph`, and `ItemList` -> `ListItem.item`).
 */
final class SchemaProductEvidence
{
    /**
     * @param array<int,mixed> $files Compiled files (`path`, `content`).
     * @param callable(string):string $slug
     * @param callable(mixed):string $price
     * @param array<int,array<string,mixed>> $diagnostics
     * @return array<string,array<string,mixed>> Product rows keyed by slug.
     */
    public static function rows(array $files, callable $slug, callable $price, array &$diagnostics = array()): array
    {
        $documents = array();
        foreach ($files as $file) {
            if (is_array($file) && is_string($file['path'] ?? null) && is_string($file['content'] ?? null) && false !== stripos($file['content'], 'ld+json')) $documents[$file['path']] = $file['content'];
        }
        ksort($documents, SORT_STRING);

        $chosen = array();
        foreach ($documents as $path => $content) {
            $pagePath = self::routePath($path);
            $schemas = array();
            foreach (HtmlMarkupParser::htmlMarkupNodes($content) as $node) {
                if ('rawtext' !== ($node['kind'] ?? null) || 'script' !== ($node['name'] ?? null)) continue;
                $type = strtolower(trim(explode(';', (string) ($node['attributes']['type'] ?? ''), 2)[0]));
                if ('application/ld+json' !== $type) continue;
                $schemas[] = json_decode($node['content'], true);
            }
            // Site evidence may follow the Product in another script or graph.
            $siteNames = array();
            foreach ($schemas as $schema) {
                foreach (self::typedNodes($schema, 'WebSite') as $site) {
                    $name = self::name($site['name'] ?? null);
                    if ('' !== $name) $siteNames[] = $name;
                }
            }
            foreach ($schemas as $schema) {
                foreach (self::typedNodes($schema, 'Product') as $product) {
                    $row = self::row($product, $path, $slug, $price, $siteNames);
                    if (null !== $row && isset($row[1]['_variants_undeclared'])) {
                        unset($row[1]['_variants_undeclared']);
                        $diagnostics[] = array('code' => 'product_variants_undeclared', 'severity' => 'warning', 'source_path' => $path, 'message' => 'ProductGroup variants lack declared, distinct attribute combinations.', 'slug' => $row[0]);
                    }
                    if (null === $row) continue;
                    [$productSlug, $fields, $urlPath] = $row;
                    $matches = '' !== $urlPath && $urlPath === $pagePath;
                    if (!isset($chosen[$productSlug]) || ($matches && !$chosen[$productSlug][1])) $chosen[$productSlug] = array($fields, $matches);
                }
            }
        }
        return array_map(static fn (array $entry): array => $entry[0], $chosen);
    }

    /** @return array<int,array<string,mixed>> */
    private static function typedNodes(mixed $value, string $type): array
    {
        if (!is_array($value)) return array();
        $types = $value['@type'] ?? array();
        $accepted = 'Product' === $type ? array('Product', 'ProductGroup') : array($type);
        $found = (is_array($types) || is_string($types)) && array_intersect($accepted, (array) $types) ? array($value) : array();
        if (array_is_list($value)) foreach ($value as $child) $found = array_merge($found, self::typedNodes($child, $type));
        // hasVariant children belong to the group, never separate simple products.
        foreach (is_array($value['@graph'] ?? null) ? $value['@graph'] : array() as $child) $found = array_merge($found, self::typedNodes($child, $type));
        foreach (is_array($value['itemListElement'] ?? null) ? $value['itemListElement'] : array() as $item) if (is_array($item)) $found = array_merge($found, self::typedNodes($item['item'] ?? null, $type));
        return $found;
    }

    /**
     * @param list<string> $siteNames
     * @return array{0:string,1:array<string,mixed>,2:string}|null
     */
    private static function row(array $product, string $path, callable $slug, callable $price, array $siteNames): ?array
    {
        $offers = $product['offers'] ?? array();
        if (is_array($offers) && array_is_list($offers)) $offers = $offers[0] ?? array();
        if (!is_array($offers)) $offers = array();
        $spec = $offers['priceSpecification'] ?? array();
        if (is_array($spec) && (isset($spec['billingDuration']) || isset($spec['unitCode']) || isset($spec['referenceQuantity']))) return null;
        $regularPrice = $price('AggregateOffer' === ($offers['@type'] ?? null) ? ($offers['lowPrice'] ?? null) : ($offers['price'] ?? null));
        $salePrice = self::salePrice($offers, $regularPrice, $price);
        $isGroup = in_array('ProductGroup', (array) ($product['@type'] ?? array()), true);
        $variants = array();
        $variantImages = array();
        $declared = true;
        $combinations = array();
        if ($isGroup) {
            $children = $product['hasVariant'] ?? array();
            if (is_array($children) && !array_is_list($children)) $children = array($children);
            foreach (is_array($children) ? $children : array() as $child) {
                if (!is_array($child) || !in_array('Product', (array) ($child['@type'] ?? array()), true)) continue;
                $variantImages = array_merge($variantImages, self::images($child['image'] ?? null, self::name($child['name'] ?? null)));
                $variant = self::row($child, $path, $slug, $price, array());
                if (null === $variant) { $declared = false; continue; }
                $fields = $variant[1];
                $attributes = self::attributes($child, $product['variesBy'] ?? array(), $slug);
                $combination = array_column($attributes, 'value', 'key');
                ksort($combination, SORT_STRING);
                $identity = json_encode($combination);
                if (!$attributes || isset($combinations[$identity])) $declared = false;
                $combinations[$identity] = true;
                $variant = array_intersect_key($fields, array_flip(array('sku', 'regular_price', 'sale_price', 'image')));
                $variant['stock_status'] = $fields['stock_status'] ?? 'instock';
                $variant['attributes'] = $attributes;
                $variants[] = $variant;
            }
            if ($variants) {
                $regularPrice = array_reduce($variants, static fn (string $lowest, array $variant): string => '' === $lowest || (float) $variant['regular_price'] < (float) $lowest ? $variant['regular_price'] : $lowest, '');
                $salePrice = '';
            }
        }
        $name = self::name($product['name'] ?? null);
        foreach ($siteNames as $siteName) {
            if (preg_match('/^(.+?)\s+[—–|·:-]\s+' . preg_quote($siteName, '/') . '\z/u', $name, $match) && '' !== trim($match[1])) {
                $name = trim($match[1]);
                break;
            }
        }
        if ('' === $name || '' === $regularPrice) return null;
        $url = is_string($product['url'] ?? null) ? $product['url'] : (is_string($offers['url'] ?? null) ? $offers['url'] : '');
        $urlPath = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $segments = array_values(array_filter(explode('/', trim($urlPath, '/')), 'strlen'));
        $productSlug = $slug($segments ? end($segments) : $name);
        if ('' === $productSlug) return null;
        $fields = array('name' => $name, 'slug' => $productSlug, 'regular_price' => $regularPrice, 'source_path' => $path);
        if (is_string($product['description'] ?? null)) {
            $description = trim(strip_tags(html_entity_decode($product['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ('' !== $description) $fields['description'] = $description;
        }
        $category = $product['category'] ?? null;
        if (is_array($category)) $category = $category['name'] ?? null;
        if (is_string($category) && '' !== trim($category)) {
            // schema.org allows a `>`- or `/`-delimited path; the leaf names the product's category.
            $leaf = trim((string) preg_replace('~^.*[>/]~', '', html_entity_decode($category, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ('' !== $leaf) $fields['category'] = $leaf;
        }
        $image = $product['image'] ?? null;
        if (is_array($image) && array_is_list($image)) $image = $image[0] ?? null;
        if (is_array($image)) $image = $image['contentUrl'] ?? $image['url'] ?? null;
        if (is_string($image) && '' !== trim($image)) $fields['image'] = trim($image);
        if ('' !== $salePrice) $fields['sale_price'] = $salePrice;
        $stock = self::stock($offers['availability'] ?? null);
        if ('' !== $stock) $fields['stock_status'] = $stock;
        $sku = self::name($product['sku'] ?? null);
        // A group identifier is not inherently a merchant SKU. Only accept an
        // explicitly labelled PropertyValue, never guess from its spelling.
        $groupID = $product['productGroupID'] ?? null;
        if ($isGroup && '' === $sku && is_array($groupID) && 'sku' === strtolower(self::name($groupID['propertyID'] ?? $groupID['name'] ?? null))) $sku = self::name($groupID['value'] ?? null);
        if ('' !== $sku) $fields['sku'] = rawurldecode($sku);
        $images = self::images($product['image'] ?? null, $name);
        $images = array_merge($images, $variantImages);
        $unique = array();
        foreach ($images as $entry) if (!isset($unique[$entry['src']])) $unique[$entry['src']] = $entry;
        // A single legacy Product image keeps its existing representation.
        if ($unique && ($isGroup || count($unique) > 1)) {
            $fields['images'] = array_values($unique);
            $fields['image'] = $fields['images'][0]['src'];
        }
        $keywords = $product['keywords'] ?? array();
        if (is_string($keywords)) $keywords = explode(',', $keywords);
        $tags = array();
        foreach (is_array($keywords) ? $keywords : array() as $keyword) {
            $tag = self::name($keyword);
            if ('' !== $tag && !in_array($tag, $tags, true)) $tags[] = $tag;
        }
        if ($tags) $fields['tags'] = $tags;
        if ($isGroup) {
            if ($variants && $declared) $fields['variants'] = $variants;
            else $fields['_variants_undeclared'] = true;
        }
        return array($productSlug, $fields, self::routePath($urlPath));
    }

    /** @return list<array{src:string,alt:string}> */
    private static function images(mixed $value, string $alt): array
    {
        if (!is_array($value) || !array_is_list($value)) $value = array($value);
        $images = array();
        foreach ($value as $image) {
            $src = is_array($image) ? ($image['contentUrl'] ?? $image['url'] ?? null) : $image;
            if (is_string($src) && '' !== trim($src)) $images[] = array('src' => trim($src), 'alt' => is_array($image) ? self::name($image['caption'] ?? $image['name'] ?? $alt) : $alt);
        }
        return $images;
    }

    /** Extract only explicit list/strikethrough prices, leaving ordinary offers unchanged. */
    private static function salePrice(array $offers, string &$regular, callable $price): string
    {
        $specs = $offers['priceSpecification'] ?? array();
        if (!is_array($specs)) return '';
        if (!array_is_list($specs)) $specs = array($specs);
        foreach ($specs as $spec) {
            if (!is_array($spec)) continue;
            $type = preg_replace('~^https?://schema.org/~', '', self::name($spec['priceType'] ?? null));
            if (!in_array($type, array('StrikethroughPrice', 'ListPrice'), true)) continue;
            $list = $price($spec['price'] ?? null);
            if ('' !== $list && '' !== $regular && (float) $list > (float) $regular) {
                $sale = $regular;
                $regular = $list;
                return $sale;
            }
        }
        return '';
    }

    private static function stock(mixed $value): string
    {
        $value = preg_replace('~^https?://schema.org/~', '', self::name($value));
        if (in_array($value, array('InStock', 'LimitedAvailability', 'PreOrder', 'BackOrder'), true)) return 'instock';
        if (in_array($value, array('OutOfStock', 'SoldOut', 'Discontinued'), true)) return 'outofstock';
        return '';
    }

    /** @return list<array{key:string,label:string,value:string,value_label:string}> */
    private static function attributes(array $product, mixed $variesBy, callable $slug): array
    {
        $properties = $product['additionalProperty'] ?? array();
        if (!is_array($properties)) $properties = array();
        if (!array_is_list($properties)) $properties = array($properties);
        foreach (is_array($variesBy) ? $variesBy : array($variesBy) as $property) {
            $key = preg_replace('~^https?://schema.org/~', '', self::name($property));
            if (in_array($key, array('size', 'color', 'material', 'pattern'), true) && isset($product[$key])) {
                $properties[] = array('@type' => 'PropertyValue', 'name' => ucfirst($key), 'value' => $product[$key]);
            }
        }
        $attributes = array();
        foreach ($properties as $property) {
            if (!is_array($property) || !in_array('PropertyValue', (array) ($property['@type'] ?? array()), true)) continue;
            $label = self::name($property['name'] ?? null);
            $value = self::name($property['value'] ?? null);
            $key = $slug($label);
            $valueSlug = $slug($value);
            if ('' !== $key && '' !== $valueSlug) $attributes[$key] = array('key' => $key, 'label' => $label, 'value' => $valueSlug, 'value_label' => $value);
        }
        return array_values($attributes);
    }

    private static function name(mixed $value): string
    {
        return is_scalar($value) ? trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    /** Route-like path: no slashes at the ends, no `.html` extension, no trailing `index`. */
    private static function routePath(string $path): string
    {
        $path = preg_replace('~\.html?$~i', '', trim($path, '/')) ?? '';
        return trim(preg_replace('~(^|/)index$~', '', $path) ?? '', '/');
    }
}
