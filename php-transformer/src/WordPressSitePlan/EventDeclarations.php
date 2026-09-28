<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use DateTimeImmutable;

/** Source-backed Event facts; the existing page remains the sole route owner. */
final class EventDeclarations
{
    /** @param array<int,array<string,mixed>> $documents @param array<int,array<string,mixed>> $routes @param array<int,array<string,mixed>> $declarations @return array<int,array<string,mixed>> */
    public static function add(array $documents, array $routes, array $declarations): array
    {
        foreach ($declarations as $declaration) {
            if ('entity_collection' === ($declaration['kind'] ?? null) && 'events' === ($declaration['type'] ?? null)) return $declarations;
        }
        if (count($declarations) >= 100) return $declarations;
        $routesBySource = array_column($routes, 'target_path', 'source_path');
        $entities = array();
        foreach ($documents as $document) {
            $source = $document['source_path'] ?? null;
            $html = $document['html'] ?? null;
            if (!is_string($source) || !isset($routesBySource[$source]) || !is_string($html)) continue;
            // A captured detail document must make one unambiguous Event claim.
            preg_match_all('~<script\b([^>]*)>(.*?)</script\s*>~is', $html, $scripts, PREG_SET_ORDER);
            $candidates = array();
            foreach (array_slice($scripts, 0, 32) as $script) {
                if (!preg_match('~\btype\s*=\s*(["\'])application/ld\+json\1~i', $script[1]) || strlen($script[2]) > 262144) continue;
                $json = json_decode($script[2], true, 24);
                self::collect($json, $candidates, 0, false);
            }
            if (1 !== count($candidates)) continue;
            $event = self::entity($candidates[0], $source, $routesBySource[$source]);
            if (null !== $event) $entities[] = $event;
        }
        if (array() === $entities) return $declarations;
        usort($entities, static fn(array $a, array $b): int => strcmp($a['source_path'], $b['source_path']));
        $source = $entities[0]['source_path'];
        $declarations[] = array('kind' => 'entity_collection', 'type' => 'events', 'source_path' => $source, 'payload' => array('schema' => 'generic/events/v1', 'entities' => $entities));
        return RuntimeDeclarations::normalizeList($declarations);
    }

    /** @param array<int,array<string,mixed>> $found */
    private static function collect(mixed $value, array &$found, int $depth, bool $schemaContext): void
    {
        if (!is_array($value) || $depth > 8 || count($found) > 1) return;
        $context = $value['@context'] ?? null;
        $schemaContext = $schemaContext || (is_string($context) && in_array(rtrim($context, '/'), array('https://schema.org', 'http://schema.org'), true));
        $types = $value['@type'] ?? null;
        if (is_string($types)) $types = array($types);
        if (is_array($types) && (($schemaContext && in_array('Event', $types, true)) || array_intersect(array('https://schema.org/Event', 'http://schema.org/Event'), $types))) { $found[] = $value; return; }
        foreach ($value as $child) if (is_array($child)) self::collect($child, $found, $depth + 1, $schemaContext);
    }

    /** @param array<string,mixed> $value @return array<string,mixed>|null */
    private static function entity(array $value, string $source, string $route): ?array
    {
        $name = $value['name'] ?? null;
        $start = $value['startDate'] ?? null;
        $end = $value['endDate'] ?? null;
        if (!is_string($name) || '' === trim($name) || strlen($name) > 512 || !self::offsetDate($start) || !self::offsetDate($end) || new DateTimeImmutable($end) < new DateTimeImmutable($start)) return null;
        $venue = $value['location'] ?? null;
        $image = $value['image'] ?? null;
        if (is_array($image)) $image = $image['url'] ?? ($image[0] ?? null);
        $entity = array('source_path' => $source, 'source_route' => $route, 'name' => trim($name), 'start_date' => $start, 'end_date' => $end);
        $description = $value['description'] ?? null;
        if (is_string($description) && '' !== trim($description) && strlen($description) <= 8192) $entity['description'] = trim($description);
        if (is_array($venue) && is_string($venue['name'] ?? null) && '' !== trim($venue['name']) && strlen($venue['name']) <= 512) {
            $place = array('name' => trim($venue['name']));
            $address = $venue['address'] ?? null;
            if (is_string($address) && '' !== trim($address) && strlen($address) <= 1024) $place['address'] = trim($address);
            if (is_array($address)) {
                $parts = array();
                foreach (array('streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry') as $key) {
                    if (is_string($address[$key] ?? null) && '' !== trim($address[$key]) && strlen($address[$key]) <= 512) $parts[$key] = trim($address[$key]);
                }
                if ($parts) $place['address'] = $parts;
            }
            $entity['venue'] = $place;
        }
        if (is_string($image) && strlen($image) <= 2048 && preg_match('~^https?://~i', $image) && filter_var($image, FILTER_VALIDATE_URL)) $entity['image'] = $image;
        return $entity;
    }

    private static function offsetDate(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 40 || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) return false;
        try {
            foreach (array('!Y-m-d\TH:i:sP', '!Y-m-d\TH:iP') as $format) {
                $date = DateTimeImmutable::createFromFormat($format, $value);
                $errors = DateTimeImmutable::getLastErrors();
                if (false !== $date && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count']))) return true;
            }
            return false;
        }
        catch (\Exception) { return false; }
    }
}
