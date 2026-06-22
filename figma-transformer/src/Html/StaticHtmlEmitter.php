<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\Html;

/**
 * Emits static HTML artifacts from a normalized scenegraph.
 */
final class StaticHtmlEmitter
{
    /**
     * @param array<string, mixed> $scenegraph Normalized Figma scenegraph.
     * @param array<string, mixed> $options Transformation options.
     * @return array<string, mixed>
     */
    public function emit(array $scenegraph, array $options = array()): array
    {
        $title = $this->sanitizeText((string) ($scenegraph['name'] ?? 'Figma Site'));
        $nodes = is_array($scenegraph['nodes'] ?? null) ? $scenegraph['nodes'] : array();

        $body = '';
        foreach ( $nodes as $node ) {
            if ( ! is_array($node) ) {
                continue;
            }
            $body .= $this->emitNode($node);
        }

        $html = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>" . $title . "</title>\n<link rel=\"stylesheet\" href=\"style.css\">\n</head>\n<body>\n<main data-figma-root=\"true\">\n" . $body . "</main>\n</body>\n</html>\n";

        return array(
            'status'        => 'success',
            'diagnostics'   => array(),
            'files'         => array(
                array(
                    'path'      => 'index.html',
                    'role'      => 'entrypoint',
                    'mime_type' => 'text/html',
                    'content'   => $html,
                ),
                array(
                    'path'      => 'style.css',
                    'role'      => 'stylesheet',
                    'mime_type' => 'text/css',
                    'content'   => "body{margin:0;font-family:system-ui,sans-serif}main{display:flex;flex-direction:column}\n[data-figma-node-id]{box-sizing:border-box}\n",
                ),
            ),
            'assets'        => array(),
            'source_report' => array(
                'name'       => $title,
                'node_count' => count($nodes),
            ),
            'metrics'       => array(
                'node_count' => count($nodes),
            ),
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function emitNode(array $node): string
    {
        $id = $this->sanitizeAttribute((string) ($node['id'] ?? ''));
        $name = $this->sanitizeAttribute((string) ($node['name'] ?? ''));
        $text = $this->sanitizeText((string) ($node['text'] ?? $node['name'] ?? ''));
        $type = strtoupper((string) ($node['type'] ?? 'FRAME'));
        $tag = 'TEXT' === $type ? 'p' : 'section';

        return sprintf("<%1\$s data-figma-node-id=\"%2\$s\" data-figma-name=\"%3\$s\">%4\$s</%1\$s>\n", $tag, $id, $name, $text);
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
