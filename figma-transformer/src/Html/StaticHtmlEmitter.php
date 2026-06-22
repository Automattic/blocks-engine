<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Emits static HTML artifacts from a normalized scenegraph.
 */
final class StaticHtmlEmitter
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $assetsById = array();

    /**
     * @param array<string, mixed> $scenegraph Normalized Figma scenegraph.
     * @param array<string, mixed> $options Transformation options.
     * @return array<string, mixed>
     */
    public function emit(array $scenegraph, array $options = array()): array
    {
        $title = $this->sanitizeText((string) ($scenegraph['name'] ?? 'Figma Site'));
        $nodes = $this->nodeList($scenegraph);
        $diagnostics = array();
        $assetFiles = $this->normalizeAssets($scenegraph['assets'] ?? array(), $diagnostics);

        $body = '';
        $cssRules = array(
            'html{box-sizing:border-box}',
            '*,*::before,*::after{box-sizing:inherit}',
            'body{margin:0}',
            'img{display:block;max-width:100%;height:auto}',
        );

        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            $body .= $this->emitNode($node, $cssRules, 0);
        }

        $files = array(
            array(
                'path'      => 'index.html',
                'role'      => 'entrypoint',
                'mime_type' => 'text/html',
                'content'   => "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>" . $title . "</title>\n<link rel=\"stylesheet\" href=\"style.css\">\n</head>\n<body>\n<main class=\"figma-root\" data-figma-root=\"true\">\n" . $body . "</main>\n</body>\n</html>\n",
            ),
            array(
                'path'      => 'style.css',
                'role'      => 'stylesheet',
                'mime_type' => 'text/css',
                'content'   => implode("\n", $cssRules) . "\n",
            ),
        );

        foreach ( $assetFiles as $assetFile ) {
            $files[] = $assetFile;
        }

        return array(
            'status'        => 'success',
            'diagnostics'   => $diagnostics,
            'files'         => $files,
            'assets'        => $this->assetReport($assetFiles),
            'source_report' => array(
                'name'       => $title,
                'node_count' => $this->countNodes($nodes),
                'schema'     => $scenegraph['schema'] ?? null,
            ),
            'metrics'       => array(
                'node_count'  => $this->countNodes($nodes),
                'asset_count' => count($assetFiles),
            ),
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, string>   $cssRules
     */
    private function emitNode(array $node, array &$cssRules, int $depth): string
    {
        $id = $this->sanitizeAttribute((string) ($node['id'] ?? ''));
        $name = (string) ($node['name'] ?? '');
        $attributeName = $this->sanitizeAttribute($name);
        $text = $this->sanitizeText((string) ($node['characters'] ?? $node['text'] ?? ''));
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        $tag = $this->tagName($type, $name, $depth);
        $className = 'figma-node-' . $this->slug($id . '-' . $name);
        $children = $this->nodeList($node);
        $content = $text;

        foreach ( $children as $child ) {
            if ( is_array($child) ) {
                $content .= $this->emitNode($child, $cssRules, $depth + 1);
            }
        }

        $styles = $this->styleDeclarations($node, $type);
        if ( ! empty($styles) ) {
            $cssRules[] = '.' . $className . '{' . implode(';', $styles) . '}';
        }

        $attributes = sprintf(' class="%1$s" data-figma-node-id="%2$s" data-figma-node-name="%3$s"', $className, $id, $attributeName);
        if ( 'RECTANGLE' === $type && '' === $content ) {
            $attributes .= ' aria-hidden="true"';
        }

        return sprintf("<%1\$s%2\$s>%3\$s</%1\$s>\n", $tag, $attributes, $content);
    }

    private function tagName(string $type, string $name, int $depth): string
    {
        $lowerName = strtolower($name);

        if ( 'TEXT' === $type ) {
            if ( str_contains($lowerName, 'title') || str_contains($lowerName, 'heading') || str_contains($lowerName, 'headline') ) {
                return 0 === $depth ? 'h1' : 'h2';
            }

            return 'p';
        }

        if ( str_contains($lowerName, 'header') ) {
            return 'header';
        }

        if ( str_contains($lowerName, 'footer') ) {
            return 'footer';
        }

        if ( str_contains($lowerName, 'nav') || str_contains($lowerName, 'menu') ) {
            return 'nav';
        }

        if ( str_contains($lowerName, 'article') ) {
            return 'article';
        }

        if ( 'FRAME' === $type ) {
            return 'section';
        }

        return 'div';
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function styleDeclarations(array $node, string $type): array
    {
        $styles = array();

        $box = is_array($node['box'] ?? null) ? $node['box'] : array();
        foreach ( array('width', 'height') as $dimension ) {
            if ( isset($box[$dimension]) && is_numeric($box[$dimension]) ) {
                $styles[] = $dimension . ':' . $this->number((float) $box[$dimension]) . 'px';
            }
        }

        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : array();
        if ( 'absolute' === ($layout['positioning'] ?? null) ) {
            $styles[] = 'position:absolute';
            foreach ( array('x' => 'left', 'y' => 'top') as $dimension => $property ) {
                if ( isset($box[$dimension]) && is_numeric($box[$dimension]) ) {
                    $styles[] = $property . ':' . $this->number((float) $box[$dimension]) . 'px';
                }
            }
        }

        $background = $this->backgroundColor($node);
        if ( null !== $background ) {
            $styles[] = 'background:' . $background;
        }

        $assetPath = $this->nodeAssetPath($node);
        if ( null !== $assetPath ) {
            $styles[] = 'background-image:url("' . $assetPath . '")';
            $styles[] = 'background-size:cover';
            $styles[] = 'background-position:center';
        }

        if ( 'TEXT' === $type ) {
            foreach ( array('fontSize' => 'font-size', 'fontWeight' => 'font-weight', 'lineHeight' => 'line-height') as $source => $property ) {
                if ( isset($node[$source]) && is_numeric($node[$source]) ) {
                    $unit = 'font-weight' === $property ? '' : 'px';
                    $styles[] = $property . ':' . $this->number((float) $node[$source]) . $unit;
                }
            }

            $color = $this->color($node['color'] ?? $node['textColor'] ?? null);
            if ( null !== $color ) {
                $styles[] = 'color:' . $color;
            }
        }

        foreach ( array(
            'display'         => 'display',
            'flex_direction'  => 'flex-direction',
            'justify_content' => 'justify-content',
            'align_items'     => 'align-items',
            'flex_wrap'       => 'flex-wrap',
        ) as $source => $property ) {
            if ( isset($layout[$source]) && is_scalar($layout[$source]) && '' !== (string) $layout[$source] ) {
                $styles[] = $property . ':' . (string) $layout[$source];
            }
        }

        if ( isset($layout['padding']) && is_array($layout['padding']) ) {
            foreach ( array('top', 'right', 'bottom', 'left') as $edge ) {
                if ( isset($layout['padding'][$edge]) && is_numeric($layout['padding'][$edge]) ) {
                    $styles[] = 'padding-' . $edge . ':' . $this->number((float) $layout['padding'][$edge]) . 'px';
                }
            }
        }

        if ( isset($layout['item_spacing']) && is_numeric($layout['item_spacing']) ) {
            $styles[] = 'gap:' . $this->number((float) $layout['item_spacing']) . 'px';
        }

        return $styles;
    }

    /**
     * @param mixed $assets
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssets(mixed $assets, array &$diagnostics): array
    {
        $this->assetsById = array();
        if ( ! is_array($assets) ) {
            return array();
        }

        $files = array();
        foreach ( $assets as $key => $asset ) {
            if ( ! is_array($asset) ) {
                continue;
            }

            $id = (string) ($asset['id'] ?? $key);
            $content = $asset['content'] ?? $asset['data'] ?? null;
            $source = (string) ($asset['url'] ?? $asset['src'] ?? '');

            if ( null === $content ) {
                if ( preg_match('/^https?:\/\//', $source) ) {
                    $diagnostics[] = array(
                        'severity' => 'warning',
                        'code'     => 'external_asset_omitted',
                        'message'  => 'External asset URL omitted from static output.',
                        'asset_id' => $id,
                    );
                }
                continue;
            }

            $mimeType = (string) ($asset['mime_type'] ?? $asset['mimeType'] ?? 'application/octet-stream');
            $path = 'assets/' . $this->slug((string) ($asset['name'] ?? $id)) . '.' . $this->extensionForMimeType($mimeType);
            $file = array(
                'path'      => $path,
                'role'      => 'asset',
                'mime_type' => $mimeType,
                'content'   => (string) $content,
                'source_id' => $id,
            );

            $files[] = $file;
            $this->assetsById[$id] = $file;
        }

        usort(
            $files,
            static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path'])
        );

        return $files;
    }

    /**
     * @param array<int, array<string, mixed>> $assetFiles
     * @return array<int, array<string, mixed>>
     */
    private function assetReport(array $assetFiles): array
    {
        $assets = array();
        foreach ( $assetFiles as $file ) {
            $content = (string) ($file['content'] ?? '');
            $assets[] = array(
                'id'        => (string) ($file['source_id'] ?? ''),
                'path'      => (string) $file['path'],
                'mime_type' => (string) $file['mime_type'],
                'bytes'     => strlen($content),
                'hash'      => hash('sha256', $content),
            );
        }

        return $assets;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeAssetPath(array $node): ?string
    {
        $assetId = (string) ($node['asset_id'] ?? $node['assetId'] ?? $node['image_ref'] ?? $node['imageRef'] ?? '');
        if ( '' === $assetId || ! isset($this->assetsById[$assetId]) ) {
            return null;
        }

        return (string) $this->assetsById[$assetId]['path'];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function backgroundColor(array $node): ?string
    {
        return $this->color($node['background'] ?? $node['backgroundColor'] ?? $node['fill'] ?? $node['fills'][0]['color'] ?? null);
    }

    private function color(mixed $value): ?string
    {
        if ( is_string($value) && preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value) ) {
            return strtolower($value);
        }

        if ( ! is_array($value) ) {
            return null;
        }

        $red = $this->colorChannel($value['r'] ?? $value['red'] ?? null);
        $green = $this->colorChannel($value['g'] ?? $value['green'] ?? null);
        $blue = $this->colorChannel($value['b'] ?? $value['blue'] ?? null);
        if ( null === $red || null === $green || null === $blue ) {
            return null;
        }

        return sprintf('#%02x%02x%02x', $red, $green, $blue);
    }

    private function colorChannel(mixed $value): ?int
    {
        if ( ! is_numeric($value) ) {
            return null;
        }

        $channel = (float) $value;
        if ( $channel <= 1 ) {
            $channel *= 255;
        }

        return max(0, min(255, (int) round($channel)));
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match ( $mimeType ) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/svg+xml' => 'svg',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    /**
     * @param array<string, mixed> $container
     * @return array<int, mixed>
     */
    private function nodeList(array $container): array
    {
        if ( is_array($container['nodes'] ?? null) ) {
            return array_values($container['nodes']);
        }

        if ( is_array($container['children'] ?? null) ) {
            return array_values($container['children']);
        }

        return array();
    }

    /**
     * @param array<int, mixed> $nodes
     */
    private function countNodes(array $nodes): int
    {
        $count = 0;
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }

            ++$count;
            $count += $this->countNodes($this->nodeList($node));
        }

        return $count;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '');
        $slug = trim($slug, '-');

        return '' === $slug ? 'node' : $slug;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }

    private function sanitizeText(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sanitizeAttribute(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
