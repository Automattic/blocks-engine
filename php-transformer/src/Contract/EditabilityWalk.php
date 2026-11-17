<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Contract;

use Automattic\BlocksEngine\PhpTransformer\Support\ArrayField;

/** One pass over a block tree, accumulating the editability metrics and signals EditabilityReport assembles. */
final class EditabilityWalk
{
    private const RICH_TEXT_ATTRIBUTES = array(
        'core/heading' => array('content'),
        'core/list-item' => array('content'),
        'core/paragraph' => array('content'),
    );

    /** @var array<string,int> */
    public array $blockTypes = array();
    /** @var array<int,array<string,mixed>> */
    public array $signals = array();
    /** @var array<string,mixed> */
    public array $deepestBlock = array();

    /**
     * @param array<string,int|float> $metrics
     * @param array<string,true> $runtimeBlockPaths
     * @param array<string,true> $visualBlockPaths
     * @param array<string,array<string,mixed>> $provenanceByBlockPath
     * @param array<string,true> $rawHtmlBlockNames
     */
    public function __construct(
        public array $metrics,
        private readonly string $sourcePath,
        private readonly string $generatedCarrierCss,
        private readonly array $runtimeBlockPaths,
        private readonly array $visualBlockPaths,
        private readonly array $provenanceByBlockPath,
        private readonly array $rawHtmlBlockNames
    ) {
    }

    /** @param array<int,mixed> $blocks @param array<int,int> $path */
    public function walk(array $blocks, int $depth, array $path): void
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) continue;
            $blockPath = array_merge($path, array($index));
            $name = is_string($block['blockName'] ?? null) && '' !== $block['blockName'] ? $block['blockName'] : 'core/freeform';
            $attrs = ArrayField::array($block, 'attrs');
            $innerBlocks = ArrayField::array($block, 'innerBlocks');
            $this->metrics['block_count']++;
            $this->metrics['max_nesting_depth'] = max($this->metrics['max_nesting_depth'], $depth);
            if ($depth > ($this->deepestBlock['depth'] ?? 0)) {
                $this->deepestBlock = array_filter(array_merge(array(
                    'depth' => $depth,
                    'block_path' => implode('.', $blockPath),
                    'block_name' => $name,
                ), $this->provenanceByBlockPath[implode('.', $blockPath)] ?? array()), static fn(mixed $value): bool => is_array($value) || '' !== $value);
            }
            $this->blockTypes[$name] = ($this->blockTypes[$name] ?? 0) + 1;
            if (array() === $innerBlocks) $this->metrics['leaf_block_count']++;
            if ($this->isWrapper($name)) {
                $this->metrics['wrapper_block_count']++;
                if (array() === $innerBlocks && '' === trim(strip_tags((string) ($block['innerHTML'] ?? '')))) {
                    $emptyKind = $this->emptyGroupKind($name, $attrs, isset($this->runtimeBlockPaths['blocks.' . implode('.', $blockPath)]), isset($this->visualBlockPaths['blocks.' . implode('.', $blockPath)]));
                    if ('empty_visual_group' === $emptyKind) {
                        $this->metrics['empty_visual_group_count']++;
                    } elseif ('empty_runtime_group' === $emptyKind) {
                        $this->metrics['empty_runtime_group_count']++;
                    } else {
                        $this->metrics['empty_wrapper_count']++;
                    }
                    $this->signals[] = $this->signal($emptyKind, $blockPath, $name);
                }
            } elseif (self::isLayoutShell($name) && array() === $innerBlocks && '' === trim(strip_tags((string) ($block['innerHTML'] ?? '')))) {
                // A folded chain around nothing is classified like the empty group it replaced.
                $wrappers = ArrayField::array($attrs, 'wrappers');
                $innermost = end($wrappers);
                $innermostClass = is_array($innermost) ? (string) ($innermost['attributes']['class'] ?? '') : '';
                $emptyKind = $this->emptyGroupKind('core/group', array('className' => $innermostClass), isset($this->runtimeBlockPaths['blocks.' . implode('.', $blockPath)]), isset($this->visualBlockPaths['blocks.' . implode('.', $blockPath)]));
                $this->metrics['wrapper_block_count']++;
                $this->metrics[$emptyKind . '_count']++;
                $this->signals[] = $this->signal($emptyKind, $blockPath, $name);
            } else {
                $this->metrics['content_block_count']++;
            }
            if ('core/html' === $name || 'core/freeform' === $name || isset($this->rawHtmlBlockNames[$name])) {
                $this->metrics['raw_html_block_count']++;
                $this->signals[] = $this->signal('raw_html_block', $blockPath, $name);
            }
            $className = ArrayField::string($attrs, 'className');
            foreach (preg_split('/\s+/', trim($className)) ?: array() as $class) {
                if (str_starts_with($class, 'blocks-engine-source-')) $this->metrics['source_marker_class_count']++;
                if (str_starts_with($class, 'be-inline-geometry-')) $this->metrics['generated_geometry_class_count']++;
                if (preg_match('/^be-responsive-counterpart-[a-f0-9]{12}$/', $class)) $this->metrics['responsive_counterpart_count']++;
            }
            if (self::isLayoutShell($name)) {
                $ownershipPath = 'blocks.' . implode('.', $blockPath);
                $wrapperCount = is_array($attrs['wrappers'] ?? null) ? count($attrs['wrappers']) : 0;
                $editableDescendants = $this->countBlocks($innerBlocks);
                $this->metrics['custom_wrapper_block_count']++;
                $this->metrics['layout_shell_wrapper_count'] += $wrapperCount;
                $this->metrics['layout_shell_editable_descendant_count'] += $editableDescendants;
                $this->signals[] = array_filter(array(
                    'kind' => 'layout_shell',
                    'source_path' => $this->sourcePath,
                    'block_path' => implode('.', $blockPath),
                    'block_name' => $name,
                    'wrapper_count' => $wrapperCount,
                    'editable_descendant_count' => $editableDescendants,
                    'runtime_owned' => isset($this->runtimeBlockPaths[$ownershipPath]),
                    'visual_owned' => isset($this->visualBlockPaths[$ownershipPath]),
                    'source_selector' => is_string(($this->provenanceByBlockPath[implode('.', $blockPath)] ?? array())['selector'] ?? null) ? $this->provenanceByBlockPath[implode('.', $blockPath)]['selector'] : '',
                ), static fn(mixed $value): bool => is_bool($value) || is_int($value) || '' !== $value);
            }
            $this->inspectAttributes($attrs, $name, $blockPath, $this->provenanceByBlockPath[implode('.', $blockPath)] ?? array());
            if (array() !== $innerBlocks) $this->walk($innerBlocks, $depth + 1, $blockPath);
        }
    }

    private function isWrapper(string $name): bool
    {
        return in_array($name, array('core/group', 'core/columns', 'core/column', 'core/buttons'), true);
    }

    /** Custom layout shells serialize exact source wrapper chains inside one bounded carrier block. */
    public static function isLayoutShell(string $name): bool
    {
        return str_ends_with($name, '/layout-shell');
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function countBlocks(array $blocks): int
    {
        $count = 0;
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            ++$count;
            if (is_array($block['innerBlocks'] ?? null)) $count += $this->countBlocks($block['innerBlocks']);
        }
        return $count;
    }

    /** @param array<string,mixed> $attrs */
    private function emptyGroupKind(string $name, array $attrs, bool $runtimeOwned, bool $visualOwned): string
    {
        if ($runtimeOwned || '' !== (string) ($attrs['anchor'] ?? '')) return 'empty_runtime_group';
        if ($visualOwned) return 'empty_visual_group';
        if ('core/column' === $name) return 'empty_visual_group';
        if ('core/group' !== $name) return 'empty_wrapper';
        if ($this->hasVisualGroupAttributes($attrs)) return 'empty_visual_group';
        $className = (string) ($attrs['className'] ?? '');
        foreach (preg_split('/\s+/', trim($className)) ?: array() as $class) {
            if (preg_match('/^be-inline-geometry-[a-f0-9]{16}(?:-[a-f0-9]{16})?$/', $class) && str_contains($this->generatedCarrierCss, '.' . $class . '{')) return 'empty_visual_group';
        }
        return 'empty_wrapper';
    }

    /** @param array<string,mixed> $attrs */
    private function hasVisualGroupAttributes(array $attrs): bool
    {
        foreach (array('backgroundColor', 'gradient', 'layout') as $key) if (!empty($attrs[$key])) return true;
        $style = ArrayField::array($attrs, 'style');
        if (array_filter((array) ($style['color'] ?? array()), static fn (mixed $value, string $key): bool => in_array($key, array('background', 'gradient', 'duotone'), true) && !empty($value), ARRAY_FILTER_USE_BOTH)) return true;
        foreach (array('background', 'border', 'dimensions', 'shadow', 'spacing') as $key) if (!empty($style[$key])) return true;
        return false;
    }

    /** @param array<string,mixed> $attrs @param array<int,int> $path @param array<string,mixed> $provenance */
    private function inspectAttributes(array $attrs, string $blockName, array $path, array $provenance): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($attrs), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $key => $value) {
            if (!is_string($value) || !$this->containsStructuralHtml($value)) continue;
            $this->metrics['html_bearing_attribute_count']++;
            $kind = 'html_bearing_attribute';
            if ('core/table' === $blockName && 'content' === (string) $key) {
                $this->metrics['html_bearing_table_cell_count']++;
                $kind = 'html_bearing_table_cell';
            } elseif (in_array((string) $key, self::RICH_TEXT_ATTRIBUTES[$blockName] ?? array(), true)) {
                $this->metrics['structural_rich_text_attribute_count']++;
                $this->metrics['structural_rich_text_attribute_bytes'] += strlen($value);
                $kind = 'structural_rich_text_attribute';
            }
            $this->signals[] = $this->signal($kind, $path, $blockName, (string) $key, $provenance);
        }
    }

    private function containsStructuralHtml(string $value): bool
    {
        if (!preg_match_all('/<\/?([a-z][a-z0-9:-]*)\b[^>]*>/i', $value, $matches)) return false;
        foreach ($matches[1] as $tag) if (!RichTextInlineTags::isAllowed((string) $tag)) return true;
        return false;
    }

    /** @param array<int,int> $path @param array<string,mixed> $provenance @return array<string,mixed> */
    private function signal(string $kind, array $path, string $blockName, string $attribute = '', array $provenance = array()): array
    {
        return array_filter(array(
            'kind' => $kind,
            'source_path' => $this->sourcePath,
            'block_path' => implode('.', $path),
            'block_name' => $blockName,
            'attribute' => $attribute,
            'source_selector' => ArrayField::string($provenance, 'selector'),
            'source_fragment' => is_string($provenance['source_fragment'] ?? null) ? mb_strcut($provenance['source_fragment'], 0, 512, 'UTF-8') : '',
        ), static fn(string $value): bool => '' !== $value);
    }
}
