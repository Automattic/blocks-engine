<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

final class Runtime
{
    public function hasWordPress(): bool
    {
        return $this->canParseBlocks() || $this->canSerializeBlocks() || function_exists('render_block');
    }

    public function canParseBlocks(): bool
    {
        return function_exists('parse_blocks');
    }

    public function canSerializeBlocks(): bool
    {
        return function_exists('serialize_blocks');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseBlocks(string $content): array
    {
        if ( $this->canParseBlocks() ) {
            return parse_blocks($content);
        }

        $blocks = $this->parseSerializedBlocks($content);
        if ( array() !== $blocks ) {
            return $blocks;
        }

        return '' === trim($content) ? array() : array(
            array(
                'blockName'    => null,
                'attrs'        => array(),
                'innerBlocks'  => array(),
                'innerHTML'    => $content,
                'innerContent' => array( $content ),
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function serializeBlocks(array $blocks): string
    {
        if ( $this->canSerializeBlocks() ) {
            return serialize_blocks($blocks);
        }

        $serialized = '';
        foreach ( $blocks as $block ) {
            $serialized .= $this->serializeBlock($block);
        }

        return $serialized;
    }

    /**
     * @param array<string, mixed> $block
     */
    public function renderBlock(array $block): string
    {
        if ( function_exists('render_block') ) {
            return render_block($block);
        }

        return $this->renderStaticBlock($block);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function renderBlocks(array $blocks): string
    {
        $html = '';
        foreach ( $blocks as $block ) {
            $html .= $this->renderBlock($block);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function serializeBlock(array $block): string
    {
        $blockName = isset($block['blockName']) ? (string) $block['blockName'] : '';
        if ( '' === $blockName ) {
            return $this->renderStaticBlock($block);
        }

        $name  = str_starts_with($blockName, 'core/') ? substr($blockName, 5) : $blockName;
        $attrs = empty($block['attrs']) ? '' : ' ' . json_encode($block['attrs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $inner = $this->renderStaticBlock($block);

        if ( '' === $inner ) {
            return '<!-- wp:' . $name . $attrs . ' /-->';
        }

        return '<!-- wp:' . $name . $attrs . ' -->' . $inner . '<!-- /wp:' . $name . ' -->';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseSerializedBlocks(string $content): array
    {
        if ( ! preg_match_all('/<!--\s*(\/)?wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)(?:\s+(\{.*?\}))?\s*(\/)?\s*-->/s', $content, $matches, PREG_OFFSET_CAPTURE) ) {
            return array();
        }

        $blocks = array();
        $stack  = array();
        $cursor = 0;

        foreach ( $matches[0] as $index => $match ) {
            $raw     = $match[0];
            $offset  = $match[1];
            $between = substr($content, $cursor, $offset - $cursor);
            if ( '' !== $between && array() !== $stack ) {
                $stack[array_key_last($stack)]['innerContent'][] = $between;
            }

            $isClose = '' !== ($matches[1][$index][0] ?? '');
            $name    = $matches[2][$index][0];
            $attrs   = $this->decodeBlockAttrs($matches[3][$index][0] ?? '');
            $isVoid  = '' !== ($matches[4][$index][0] ?? '');

            if ( $isClose ) {
                $frame = array_pop($stack);
                if ( ! is_array($frame) || $frame['name'] !== $name ) {
                    return array();
                }

                $block = $this->createParsedBlock($name, $frame['attrs'], $frame['innerBlocks'], $frame['innerContent']);
                $this->appendParsedBlock($blocks, $stack, $block);
            } elseif ( $isVoid ) {
                $this->appendParsedBlock($blocks, $stack, $this->createParsedBlock($name, $attrs, array(), array()));
            } else {
                $stack[] = array(
                    'name'         => $name,
                    'attrs'        => $attrs,
                    'innerBlocks'  => array(),
                    'innerContent' => array(),
                );
            }

            $cursor = $offset + strlen($raw);
        }

        if ( array() !== $stack ) {
            return array();
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBlockAttrs(string $json): array
    {
        if ( '' === trim($json) ) {
            return array();
        }

        $attrs = json_decode($json, true);

        return is_array($attrs) ? $attrs : array();
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @param array<int, string|null> $innerContent
     * @return array<string, mixed>
     */
    private function createParsedBlock(string $name, array $attrs, array $innerBlocks, array $innerContent): array
    {
        $innerHTML = '';
        foreach ( $innerContent as $part ) {
            if ( null !== $part ) {
                $innerHTML .= $part;
            }
        }

        return array(
            'blockName'    => str_contains($name, '/') ? $name : 'core/' . $name,
            'attrs'        => $attrs,
            'innerBlocks'  => $innerBlocks,
            'innerHTML'    => $innerHTML,
            'innerContent' => $innerContent,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $stack
     * @param array<string, mixed> $block
     */
    private function appendParsedBlock(array &$blocks, array &$stack, array $block): void
    {
        if ( array() === $stack ) {
            $blocks[] = $block;
            return;
        }

        $key = array_key_last($stack);
        $stack[$key]['innerBlocks'][]  = $block;
        $stack[$key]['innerContent'][] = null;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderStaticBlock(array $block): string
    {
        $innerContent = $block['innerContent'] ?? null;
        $innerBlocks  = $block['innerBlocks'] ?? array();

        if ( is_array($innerContent) ) {
            $html       = '';
            $blockIndex = 0;
            foreach ( $innerContent as $part ) {
                if ( null === $part ) {
                    $innerBlock = is_array($innerBlocks) && isset($innerBlocks[$blockIndex]) && is_array($innerBlocks[$blockIndex]) ? $innerBlocks[$blockIndex] : null;
                    $html      .= null === $innerBlock ? '' : $this->renderStaticBlock($innerBlock);
                    ++$blockIndex;
                    continue;
                }

                $html .= (string) $part;
            }

            return $html;
        }

        return isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
    }
}
