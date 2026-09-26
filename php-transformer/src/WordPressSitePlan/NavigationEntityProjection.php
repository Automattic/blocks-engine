<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

final class NavigationEntityProjection
{
    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,array<string,mixed>> $menus
     * @return array{pages:array<int,array<string,mixed>>,parts:array<int,array<string,mixed>>,menus:array<int,array<string,mixed>>}
     */
    public static function project(array $pages, array $parts, array $menus): array
    {
        $occurrences = array();
        foreach ($parts as $index => $part) {
            foreach (self::navigationBlocks((string) ($part['canonical_block_markup'] ?? '')) as $block) {
                $signature = self::destinationSignature($block['inner']);
                if ('' === $signature) {
                    continue;
                }
                $occurrences[] = array(
                    'document' => 'part',
                    'index' => $index,
                    'block' => $block,
                    'signature' => $signature,
                    'items' => $block['items'],
                    'source_path' => self::partSourcePath($part),
                    'title' => self::documentTitle($part, 'Navigation'),
                    'area' => (string) ($part['area'] ?? ''),
                );
            }
        }
        foreach ($pages as $index => $page) {
            foreach (self::navigationBlocks((string) ($page['canonical_block_markup'] ?? '')) as $block) {
                $signature = self::destinationSignature($block['inner']);
                if ('' === $signature) {
                    continue;
                }
                $occurrences[] = array(
                    'document' => 'page',
                    'index' => $index,
                    'block' => $block,
                    'signature' => $signature,
                    'items' => $block['items'],
                    'source_path' => (string) ($page['source_path'] ?? ''),
                    'title' => 'Navigation',
                    'area' => '',
                );
            }
        }
        if (array() === $occurrences) {
            return array('pages' => $pages, 'parts' => $parts, 'menus' => $menus);
        }

        $clusters = array();
        foreach ($occurrences as $occurrence) {
            $clusters[$occurrence['signature']][] = $occurrence;
        }

        $entities = array();
        $usedSlugs = array();
        $order = 0;
        foreach ($clusters as $cluster) {
            $canonical = self::canonicalOccurrence($cluster);
            $inner = ShellExtraction::withoutCurrentNavigationState($canonical['block']['inner']);
            $sourcePath = $canonical['source_path'];
            $slug = self::slugFromTitle($canonical['title']);
            if (isset($usedSlugs[$slug])) {
                $slug .= '-' . substr(WordPressSitePlan::identity('menu', $sourcePath, $slug), 0, 8);
            }
            $usedSlugs[$slug] = true;
            $identity = WordPressSitePlan::identity('menu', $sourcePath, $slug);
            $token = 'navigation-' . substr($identity, 0, 16);
            $entities[] = array(
                'kind' => 'menu',
                'source_path' => $sourcePath,
                'target_slug' => $slug,
                'title' => $canonical['title'],
                'source_relation' => 'navigation_entity',
                'order' => $order,
                'items' => $canonical['items'],
                'block_markup' => $inner,
                'token' => $token,
                'reconciliation_identity' => $identity,
            );
            ++$order;
        }

        return array('pages' => $pages, 'parts' => $parts, 'menus' => $entities);
    }

    /**
     * @param array<int,array<string,mixed>> $cluster
     * @return array<string,mixed>
     */
    private static function canonicalOccurrence(array $cluster): array
    {
        usort($cluster, static function (array $left, array $right): int {
            $leftHeader = 'header' === ($left['area'] ?? '') ? 0 : 1;
            $rightHeader = 'header' === ($right['area'] ?? '') ? 0 : 1;
            if ($leftHeader !== $rightHeader) {
                return $leftHeader <=> $rightHeader;
            }
            $leftPart = 'part' === ($left['document'] ?? '') ? 0 : 1;
            $rightPart = 'part' === ($right['document'] ?? '') ? 0 : 1;
            if ($leftPart !== $rightPart) {
                return $leftPart <=> $rightPart;
            }
            return ($left['index'] ?? 0) <=> ($right['index'] ?? 0);
        });
        return $cluster[0];
    }

    /**
     * @return array<int,array{offset:int,length:int,inner:string,attrs:array<string,mixed>,items:int}>
     */
    private static function navigationBlocks(string $markup): array
    {
        $blocks = array();
        foreach (WordPressSitePlan::blockRanges($markup) as $range) {
            $block = substr($markup, $range['offset'], $range['length']);
            if (!preg_match('/^<!--\s*wp:navigation(?!-)/', $block)) {
                continue;
            }
            $openEnd = strpos($block, '-->');
            if (false === $openEnd) {
                continue;
            }
            $opening = substr($block, 0, $openEnd + 3);
            if (preg_match('/\/\s*-->$/', $opening)) {
                continue;
            }
            $attrs = array();
            if (preg_match('/\{.*\}/s', $opening, $json)) {
                $decoded = json_decode($json[0], true);
                if (is_array($decoded)) {
                    $attrs = $decoded;
                }
            }
            if (isset($attrs['ref'])) {
                continue;
            }
            $innerEnd = strrpos($block, '<!-- /wp:navigation');
            if (false === $innerEnd) {
                continue;
            }
            $inner = substr($block, $openEnd + 3, $innerEnd - ($openEnd + 3));
            $items = self::destinationItems($inner);
            if (array() === $items) {
                continue;
            }
            $blocks[] = array(
                'offset' => $range['offset'],
                'length' => $range['length'],
                'inner' => $inner,
                'attrs' => $attrs,
                'items' => count($items),
            );
        }
        return $blocks;
    }

    private static function destinationSignature(string $inner): string
    {
        return implode("\n", self::destinationItems($inner));
    }

    /** @return array<int,string> */
    private static function destinationItems(string $inner): array
    {
        $items = array();
        $offset = 0;
        while (preg_match('/<!--\s*wp:navigation-(?:link|submenu)\s*/', $inner, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1];
            $openEnd = strpos($inner, '-->', $start);
            if (false === $openEnd) {
                break;
            }
            $opening = substr($inner, $start, $openEnd + 3 - $start);
            $attrs = array();
            if (preg_match('/\{.*\}/s', $opening, $json)) {
                $decoded = json_decode($json[0], true);
                if (is_array($decoded)) {
                    $attrs = $decoded;
                }
            }
            $items[] = (string) ($attrs['label'] ?? '') . "\t" . (string) ($attrs['url'] ?? '');
            $offset = $openEnd + 3;
        }
        return $items;
    }

    /** @param array<string,mixed> $part */
    private static function partSourcePath(array $part): string
    {
        $source = (string) ($part['source_path'] ?? '');
        if ('' !== $source) {
            return $source;
        }
        $slug = (string) ($part['slug'] ?? 'navigation');
        return 'parts/' . $slug . '.html';
    }

    /** @param array<string,mixed> $document */
    private static function documentTitle(array $document, string $fallback): string
    {
        $title = trim((string) ($document['title'] ?? ''));
        return '' !== $title ? $title : $fallback;
    }

    private static function slugFromTitle(string $title): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
        return '' !== $slug ? $slug : 'navigation';
    }
}
