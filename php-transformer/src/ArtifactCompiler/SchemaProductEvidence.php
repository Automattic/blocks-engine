<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Support\HtmlMarkupParser;

/**
 * Provider-neutral schema.org `Product` evidence read from JSON-LD in compiled
 * HTML documents (top-level, `@graph`, and `ItemList` -> `ListItem.item`).
 */
final class SchemaProductEvidence
{
    /**
     * @param array<int,mixed> $files Compiled files (`path`, `content`).
     * @param callable(string):string $slug
     * @param callable(mixed):string $price
     * @return array<string,array<string,string>> Product rows keyed by slug.
     */
    public static function rows(array $files, callable $slug, callable $price): array
    {
        $documents = array();
        foreach ($files as $file) {
            if (is_array($file) && is_string($file['path'] ?? null) && is_string($file['content'] ?? null) && false !== stripos($file['content'], 'ld+json')) $documents[$file['path']] = $file['content'];
        }
        ksort($documents, SORT_STRING);

        $chosen = array();
        foreach ($documents as $path => $content) {
            $pagePath = self::routePath($path);
            foreach (HtmlMarkupParser::htmlMarkupNodes($content) as $node) {
                if ('rawtext' !== ($node['kind'] ?? null) || 'script' !== ($node['name'] ?? null)) continue;
                $type = strtolower(trim(explode(';', (string) ($node['attributes']['type'] ?? ''), 2)[0]));
                if ('application/ld+json' !== $type) continue;
                foreach (self::products(json_decode($node['content'], true)) as $product) {
                    $row = self::row($product, $path, $slug, $price);
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
    private static function products(mixed $value): array
    {
        if (!is_array($value)) return array();
        $types = $value['@type'] ?? array();
        $found = is_array($types) || is_string($types) ? (in_array('Product', (array) $types, true) ? array($value) : array()) : array();
        foreach (is_array($value['@graph'] ?? null) ? $value['@graph'] : array() as $child) $found = array_merge($found, self::products($child));
        foreach (is_array($value['itemListElement'] ?? null) ? $value['itemListElement'] : array() as $item) if (is_array($item)) $found = array_merge($found, self::products($item['item'] ?? null));
        return $found;
    }

    /** @return array{0:string,1:array<string,string>,2:string}|null */
    private static function row(array $product, string $path, callable $slug, callable $price): ?array
    {
        $offers = $product['offers'] ?? array();
        if (is_array($offers) && array_is_list($offers)) $offers = $offers[0] ?? array();
        if (!is_array($offers)) $offers = array();
        $spec = $offers['priceSpecification'] ?? array();
        if (is_array($spec) && (isset($spec['billingDuration']) || isset($spec['unitCode']) || isset($spec['referenceQuantity']))) return null;
        $regularPrice = $price('AggregateOffer' === ($offers['@type'] ?? null) ? ($offers['lowPrice'] ?? null) : ($offers['price'] ?? null));
        $name = is_scalar($product['name'] ?? null) ? trim(html_entity_decode((string) $product['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
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
        return array($productSlug, $fields, self::routePath($urlPath));
    }

    /** Route-like path: no slashes at the ends, no `.html` extension, no trailing `index`. */
    private static function routePath(string $path): string
    {
        $path = preg_replace('~\.html?$~i', '', trim($path, '/')) ?? '';
        return trim(preg_replace('~(^|/)index$~', '', $path) ?? '', '/');
    }
}
