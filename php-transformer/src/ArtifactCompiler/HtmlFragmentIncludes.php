<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use InvalidArgumentException;

/** Artifact-local SSI includes. No filesystem, URL fetching, or server execution. */
final class HtmlFragmentIncludes
{
    public const MAX_DEPTH = 16;
    public const MAX_INCLUDES = 4096;

    public static function isComponentPath(string $path): bool
    {
        return 1 === preg_match('#(^|/)(parts|template-parts)/[^/]+\.html?$#i', $path);
    }

    /** @param array<int,array<string,mixed>> $files @param array<int,string> $entrypoints */
    public static function virtualRoot(array $files, array $entrypoints): string
    {
        $entry = $entrypoints[0] ?? '';
        $paths = array_column($files, 'path');
        if ('' === $entry) foreach ($files as $file) if (!empty($file['entrypoint']) || 'entry' === ($file['role'] ?? null)) { $entry = (string) $file['path']; break; }
        if ('' === $entry) $entry = in_array('index.html', $paths, true) ? 'index.html' : (in_array('website/index.html', $paths, true) ? 'website/index.html' : 'index.html');
        $entry = ArtifactPath::safeRelativePath($entry);
        if ('' === $entry) throw new InvalidArgumentException('html_include_unsafe_entrypoint');
        return '.' === dirname($entry) ? '' : dirname($entry) . '/';
    }

    /** @return array<int,array{virtual:string,offset:int,length:int}> */
    public static function directives(string $source, string $path): array
    {
        $directives = array();
        foreach (HtmlTagScanner::scan($source, '#comment') as $comment) {
            $token = $comment['tag'];
            if (!str_starts_with($token, '<!--#')) continue;
            if (1 !== preg_match('~^<!--#include\s+virtual\s*=\s*(["\'])(/[^"\']*)\1\s*-->$~D', $token, $match)) throw new InvalidArgumentException('html_include_invalid_directive: ' . $path);
            $virtual = $match[2];
            if (str_starts_with($virtual, '//') || preg_match('~[\\\\%?#\x00-\x20]|(?:^|/)\.{1,2}(?:/|$)~', $virtual) || !preg_match('/\.html?$/i', $virtual)) throw new InvalidArgumentException('html_include_unsafe_path: ' . $virtual);
            if (count($directives) >= self::MAX_INCLUDES) throw new InvalidArgumentException('html_include_count_exceeded: ' . $path);
            $directives[] = array('virtual' => $virtual, 'offset' => $comment['offset'], 'length' => strlen($token));
        }
        return $directives;
    }

    /**
     * Expansion precedes inline asset extraction and ownership partitioning.
     * Only comments are replaced: surrounding bytes and fragment URLs are intact.
     * The resulting content digest is also the geometry-proof identity; evidence
     * bound to unresolved source is deliberately not promoted to resolved HTML.
     *
     * @param array<int,array<string,mixed>> $files
     * @param array<int,string> $entrypoints
     * @param array<string,int> $limits
     * @return array<int,array<string,mixed>>
     */
    public function expand(array $files, array $entrypoints, array $limits, callable $payload, array $reports = array()): array
    {
        $contents = array();
        $active = false;
        $duplicates = array();
        foreach ($files as $file) {
            $path = ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''));
            if (!preg_match('/\.html?$/i', $path)) continue;
            $content = $payload($file, $path)['content'];
            if (!isset($file['content_base64']) && !isset($file['payload_reference'])) {
                foreach (array('content', 'body', 'text') as $key) if (is_string($file[$key] ?? null)) { $content = $file[$key]; break; }
            }
            if (isset($contents[$path])) $duplicates[] = $path;
            $contents[$path] = $content;
            $active = $active || str_contains($content, '<!--#');
        }
        if (!$active) return $files;
        if (array() !== $duplicates) throw new InvalidArgumentException('html_include_duplicate_path: ' . $duplicates[0]);
        $root = self::virtualRoot($files, $entrypoints);
        $referenced = array();
        $count = 0;
        $total = 0;
        $resolve = function (string $path, array $stack) use (&$resolve, &$referenced, &$count, $contents, $root, $limits): string {
            if (isset($stack[$path])) throw new InvalidArgumentException('html_include_cycle: ' . $path);
            if (count($stack) >= self::MAX_DEPTH) throw new InvalidArgumentException('html_include_depth_exceeded: ' . $path);
            $stack[$path] = true;
            $source = $contents[$path];
            if (strlen($source) > $limits['max_file_bytes']) throw new InvalidArgumentException('html_include_file_budget_exceeded: ' . $path);
            $result = '';
            $offset = 0;
            foreach (self::directives($source, $path) as $directive) {
                $position = $directive['offset'];
                $target = $root . substr($directive['virtual'], 1);
                if (!array_key_exists($target, $contents)) throw new InvalidArgumentException('html_include_missing_path: ' . $target);
                if (++$count > self::MAX_INCLUDES) throw new InvalidArgumentException('html_include_count_exceeded: ' . $path);
                $referenced[$target] = true;
                $fragment = $resolve($target, $stack);
                $prefix = substr($source, $offset, $position - $offset);
                if (strlen($result) + strlen($prefix) + strlen($fragment) > $limits['max_file_bytes']) throw new InvalidArgumentException('html_include_file_budget_exceeded: ' . $path);
                $result .= $prefix . $fragment;
                $offset = $position + $directive['length'];
            }
            if (strlen($result) + strlen($source) - $offset > $limits['max_file_bytes']) throw new InvalidArgumentException('html_include_file_budget_exceeded: ' . $path);
            return $result . substr($source, $offset);
        };
        foreach ($files as &$file) {
            $path = ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''));
            if (isset($contents[$path]) && str_contains($contents[$path], '<!--#')) {
                $file['content'] = $resolve($path, array());
                if (!isset($file['metadata']['compilation'])) $file['metadata']['compilation'] = array('scope' => 'page', 'id' => $path);
                $file['metadata']['compilation']['resolved_html_includes'] = true;
                unset($file['content_base64'], $file['payload_reference']);
            }
            if (!ArtifactNormalizer::isReferenceBackedBinary($file) && !isset($reports[$path])) $total += $payload($file, $path)['bytes'];
            if ($total > $limits['max_total_bytes']) throw new InvalidArgumentException('html_include_total_budget_exceeded');
        }
        unset($file);
        foreach ($files as &$file) {
            if (!isset($referenced[$file['path']])) continue;
            if (!str_contains($contents[$file['path']], '<!--#')) $file['content'] = $contents[$file['path']];
            $file['metadata']['compilation'] = array('scope' => 'shared', 'included_component' => true, 'resolved_html_includes' => true);
            // Included HTML is source data, never an additional route.
            $file['role'] = 'template-part';
        }
        unset($file);
        return $files;
    }
}
