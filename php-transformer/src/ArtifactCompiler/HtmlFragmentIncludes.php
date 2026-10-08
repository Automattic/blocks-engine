<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use InvalidArgumentException;

/** Canonical artifact-local HTML includes. No filesystem, URL fetching, or server execution. */
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
        if (false === stripos($source, '#include')) return array();
        $directives = array();
        foreach (HtmlTagScanner::scan($source, '#comment') as $comment) {
            $token = $comment['tag'];
            if (1 !== preg_match('/^<!--\s*#include/i', $token)) continue;
            if (1 !== preg_match('~^<!--#include virtual="(/parts/[a-zA-Z0-9_-]+\.html)" -->$~D', $token, $match)) throw new InvalidArgumentException('html_include_invalid_directive: ' . $path);
            if (count($directives) >= self::MAX_INCLUDES) throw new InvalidArgumentException('html_include_count_exceeded: ' . $path);
            $directives[] = array('virtual' => $match[1], 'offset' => $comment['offset'], 'length' => strlen($token));
        }
        return $directives;
    }

    /**
     * Expansion precedes inline asset extraction and ownership partitioning.
     * Only comments are replaced: surrounding bytes and fragment URLs are intact.
     * The resulting content digest is also the geometry-proof identity; evidence
     * bound to unresolved source is deliberately not promoted to resolved HTML.
     * Include accounting is per resolved document: shared fragments are resolved
     * once and reused, and every reuse charges the fragment's full logical
     * expansion count, keeping the per-document bound deterministic regardless
     * of file order or cache warmth.
     *
     * @param array<int,array<string,mixed>> $files
     * @param array<int,string> $entrypoints
     * @param array<string,int> $limits
     * @return array<int,array<string,mixed>>
     */
    public function expand(array $files, array $entrypoints, array $limits, callable $payload, array $reports = array()): array
    {
        $contents = array();
        $directives = array();
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
            $directives[$path] = self::directives($content, $path);
            $active = $active || array() !== $directives[$path];
        }
        if (!$active) return $files;
        if (array() !== $duplicates) throw new InvalidArgumentException('html_include_duplicate_path: ' . $duplicates[0]);
        $root = self::virtualRoot($files, $entrypoints);
        $referenced = array();
        $resolved = array();
        $total = 0;
        $count = 0;
        $resolve = function (string $path, array $stack) use (&$resolve, &$referenced, &$resolved, &$count, $contents, $directives, $root, $limits): array {
            if (isset($stack[$path])) throw new InvalidArgumentException('html_include_cycle: ' . $path);
            if (count($stack) >= self::MAX_DEPTH) throw new InvalidArgumentException('html_include_depth_exceeded: ' . $path);
            if (isset($resolved[$path])) {
                if (count($stack) + $resolved[$path]['height'] >= self::MAX_DEPTH) throw new InvalidArgumentException('html_include_depth_exceeded: ' . $path);
                $count += $resolved[$path]['uses'];
                if ($count > self::MAX_INCLUDES) throw new InvalidArgumentException('html_include_count_exceeded: ' . $path);
                return $resolved[$path];
            }
            $stack[$path] = true;
            $source = $contents[$path];
            if (strlen($source) > $limits['max_file_bytes']) throw new InvalidArgumentException('html_include_file_budget_exceeded: ' . $path);
            $result = '';
            $offset = 0;
            $height = 0;
            $uses = 0;
            foreach ($directives[$path] as $directive) {
                $position = $directive['offset'];
                $target = $root . substr($directive['virtual'], 1);
                if (!array_key_exists($target, $contents)) throw new InvalidArgumentException('html_include_missing_path: ' . $target);
                if (++$count > self::MAX_INCLUDES) throw new InvalidArgumentException('html_include_count_exceeded: ' . $path);
                $referenced[$target] = true;
                $fragment = $resolve($target, $stack);
                $uses += 1 + $fragment['uses'];
                $height = max($height, 1 + $fragment['height']);
                $prefix = substr($source, $offset, $position - $offset);
                if (strlen($result) + strlen($prefix) + strlen($fragment['content']) > $limits['max_file_bytes']) throw new InvalidArgumentException('html_include_file_budget_exceeded: ' . $path);
                $result .= $prefix . $fragment['content'];
                $offset = $position + $directive['length'];
            }
            if (strlen($result) + strlen($source) - $offset > $limits['max_file_bytes']) throw new InvalidArgumentException('html_include_file_budget_exceeded: ' . $path);
            return $resolved[$path] = array('content' => $result . substr($source, $offset), 'height' => $height, 'uses' => $uses);
        };
        try {
            foreach ($files as &$file) {
                $path = ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''));
                if (isset($contents[$path]) && array() !== $directives[$path]) {
                    $count = 0;
                    $file['content'] = $resolve($path, array())['content'];
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
                if (array() === $directives[$file['path']]) $file['content'] = $contents[$file['path']];
                $file['metadata']['compilation'] = array('scope' => 'shared', 'included_component' => true, 'resolved_html_includes' => true);
                // Included HTML is source data, never an additional route.
                $file['role'] = 'template-part';
            }
            unset($file);
            return $files;
        } finally {
            // Recursive closures capture their own reference. Break that cycle
            // on success and failure so source and expanded payloads are freed
            // at this call boundary, rather than a later automatic GC pass.
            $resolve = null;
        }
    }
}
