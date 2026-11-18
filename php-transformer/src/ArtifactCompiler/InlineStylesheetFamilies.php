<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * Inline `<style>` bodies are content addressed, so one style element whose
 * text differs slightly per route becomes one file per variant. Route-variant
 * reconciliation groups by path, so those variants would each ship in full.
 *
 * A family is the same style element across routes: the same position among
 * the route's stylesheet links, the same media and type, and substantial rule
 * overlap, taking at most one sheet per route in document order. Every
 * occurrence keeps its own route's cascade position. Each family is folded onto one representative
 * path carrying every member's occurrences, and every member's page
 * projections are re-addressed to it. Route-variant reconciliation then emits
 * the shared rules once and keeps the differing rules route-scoped, exactly as
 * it does for a linked stylesheet whose projections differ per route.
 */
final class InlineStylesheetFamilies
{
    /** A member shares at least this fraction of the larger sheet's rules. */
    private const MIN_RULE_OVERLAP = 0.5;

    /**
     * Occurrence fields a style element keeps across routes. The ordinal among
     * inline sheets is not one of them: a route may add its own small sheet
     * earlier in the head, shifting every later index.
     */
    private const POSITION_FIELDS = array('stylesheet_link_position', 'media', 'source_media', CaptureEvidence::DEVICE_STYLE);

    /**
     * @param list<array<string,mixed>> $files Files keyed uniquely by path.
     * @param list<array<string,mixed>> $projections Owned author stylesheet projections.
     * @param list<array<string,mixed>> $primaryProjections Entry projections.
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>}
     */
    public static function fold(array $files, array $projections, array $primaryProjections): array
    {
        $projectionsByPath = array();
        foreach ($projections as $projection) {
            if (is_string($projection['path'] ?? null)) $projectionsByPath[$projection['path']][] = $projection;
        }
        $candidates = array();
        foreach ($files as $index => $file) {
            $key = self::positionKey($file, $projectionsByPath[(string) ($file['path'] ?? '')] ?? array());
            if (null !== $key) $candidates[$key][] = $index;
        }
        $renamed = array();
        foreach ($candidates as $members) {
            if (count($members) < 2) continue;
            // Families in document order: each sheet joins the first family it
            // substantially overlaps that has no occurrence on its routes yet.
            $families = array();
            foreach ($members as $member) {
                $rules = self::rules((string) $files[$member]['content']);
                $owners = self::owners($files[$member]);
                foreach ($families as $key => $family) {
                    // One route has one occurrence of an element; a family
                    // never takes two sheets from the same route.
                    if (array() !== array_intersect_key($family['owners'], $owners) || !self::overlaps($family['rules'], $rules)) continue;
                    $families[$key]['owners'] += $owners;
                    foreach ($files[$member]['inline_references'] as $reference) {
                        $files[$family['index']]['inline_references'][] = $reference;
                    }
                    $renamed[(string) $files[$member]['path']] = (string) $files[$family['index']]['path'];
                    unset($files[$member]);
                    continue 2;
                }
                $families[] = array('index' => $member, 'rules' => $rules, 'owners' => $owners);
            }
        }
        if (array() === $renamed) return array(array_values($files), $projections, $primaryProjections);
        $rename = static function (array $projection) use ($renamed): array {
            $path = $projection['path'] ?? null;
            if (is_string($path) && isset($renamed[$path])) $projection['path'] = $renamed[$path];
            return $projection;
        };
        return array(array_values($files), array_map($rename, $projections), array_map($rename, $primaryProjections));
    }

    /**
     * A page-owned inline sheet whose every occurrence sits at one position and
     * whose every projection belongs to one of its occurrence routes.
     *
     * @param array<string,mixed> $file
     * @param list<array<string,mixed>> $projections
     */
    private static function positionKey(array $file, array $projections): ?string
    {
        if ('css' !== ($file['kind'] ?? '') || 'inline-style' !== ($file['source'] ?? '') || !is_string($file['content'] ?? null) || !is_array($file['inline_references'] ?? null) || array() === $file['inline_references'] || array() === $projections) return null;
        $keys = array();
        foreach ($file['inline_references'] as $reference) {
            if (!is_array($reference) || isset($reference['metadata']) || !is_string($reference['source_path'] ?? null)) return null;
            $position = array((string) ($file['intent'] ?? ''), (string) ($file['type'] ?? ''));
            foreach (self::POSITION_FIELDS as $field) $position[] = (string) ($reference[$field] ?? '');
            $keys[implode("\0", $position)] = true;
        }
        if (1 !== count($keys)) return null;
        $owners = self::owners($file);
        foreach ($projections as $projection) {
            $owner = (string) ($projection['owner'] ?? '');
            if ('' === $owner || isset($projection['member_owners']) || !isset($owners[$owner])) return null;
        }
        return (string) array_key_first($keys);
    }

    /** @param array<string,mixed> $file @return array<string,true> */
    private static function owners(array $file): array
    {
        $owners = array();
        foreach ($file['inline_references'] as $reference) $owners[(string) $reference['source_path']] = true;
        return $owners;
    }

    /** @return array<string,true> Style rules with their at-rule context. */
    private static function rules(string $css): array
    {
        $rules = array();
        (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $selector, string $body, array $conditions) use (&$rules): void {
            $rules[implode("\0", $conditions) . "\0" . trim($selector) . '{' . trim($body) . '}'] = true;
        });
        return $rules;
    }

    /** @param array<string,true> $first @param array<string,true> $second */
    private static function overlaps(array $first, array $second): bool
    {
        $largest = max(count($first), count($second));
        return 0 < $largest && count(array_intersect_key($first, $second)) >= self::MIN_RULE_OVERLAP * $largest;
    }
}
