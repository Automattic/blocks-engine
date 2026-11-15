<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;
use Automattic\BlocksEngine\PhpTransformer\Support\StylesheetActivation;

/**
 * What the documents' stylesheet links say about each stylesheet file: the
 * media condition every linking document agrees on, a separate occurrence
 * for each distinct stylesheet-set activation, and source stylesheets no
 * document links, which stay as provenance rather than active theme CSS.
 */
final class LinkedStylesheets
{
    /**
     * Carry linked stylesheet media metadata from every page into the site
     * asset set. Per-page CSS analysis sees link media directly; the global
     * WordPress asset plan is built later from file records and otherwise loses
     * the condition that keeps responsive declarations on their source
     * breakpoint.
     *
     * @param array<int,array<string,mixed>> $files
     * @return array<int,array<string,mixed>>
     */
    public static function withDocumentMedia(array $files): array
    {
        $uniformMedia = self::documentLinkMedia($files);
        $initialCount = count($files);
        $byPath = array();
        $reserved = array();
        foreach ( $files as $index => $file ) {
            $path = (string) ($file['path'] ?? '');
            if ( '' === $path ) continue;
            $byPath[$path] = $index;
            $reserved[$path] = true;
        }

        /** @var array<string,array<string,string>> $variants */
        $variants = array();
        /** @var array<string,int> $occurrences */
        $occurrences = array();

        foreach ( array_slice($files, 0, $initialCount) as $document ) {
            if ( 'html' !== ($document['kind'] ?? null) || ! is_string($document['path'] ?? null) || ! is_string($document['content'] ?? null) ) continue;
            $documentPath = $document['path'];
            $links = StyleTagScanner::scanLinks($document['content']);
            $activation = StylesheetActivation::links($document['content']);
            foreach ( $links as $linkIndex => $link ) {
                $tag = $link['tag'];
                if ( ! StyleTagScanner::isStylesheetRel(HtmlTagScanner::attribute($tag, 'rel')) || ! StyleTagScanner::isCssType(HtmlTagScanner::attribute($tag, 'type')) ) continue;
                $sourcePath = ArtifactPath::linkedFilePath(HtmlTagScanner::attribute($tag, 'href'), $documentPath, $files);
                $index = $byPath[$sourcePath] ?? null;
                if ( ! is_int($index) || 'css' !== ($files[$index]['kind'] ?? null) ) continue;
                $state = $activation[$link['offset']];
                $variant = json_encode($state);
                $reference = array(
                    'source_path' => $documentPath,
                    'selector' => 'link:nth-of-type(' . ($linkIndex + 1) . ')',
                    'element' => 'link',
                    'attribute' => 'href',
                    'value' => HtmlTagScanner::attribute($tag, 'href'),
                    'url' => HtmlTagScanner::attribute($tag, 'href'),
                );
                if ( isset($variants[$sourcePath][$variant]) ) {
                    $variantIndex = $byPath[$variants[$sourcePath][$variant]] ?? null;
                    if ( is_int($variantIndex) ) {
                        $files[$variantIndex]['references'] = array_values(array_unique(array_merge($files[$variantIndex]['references'] ?? array(), array($reference)), SORT_REGULAR));
                    }
                    continue;
                }
                // Media and type belong to link instances, never to URI aliases.
                // Every accepted CSS link type is equivalent, so the resource
                // records the first instance's declared type as metadata.
                $media = $uniformMedia[$sourcePath] ?? ($files[$index]['media'] ?? '');
                $type = $files[$index]['type'] ?? trim(HtmlTagScanner::attribute($tag, 'type'));
                if ( ! isset($variants[$sourcePath]) ) {
                    $variants[$sourcePath][$variant] = $sourcePath;
                    $files[$index]['media'] = $media;
                    if (array_key_exists(CaptureEvidence::SOURCE_MEDIA, HtmlTagScanner::attributes($tag))) $files[$index]['source_media'] = StyleTagScanner::authorMedia($tag);
                    $files[$index]['type'] = $type;
                    $files[$index]['stylesheet_activation'] = $state;
                    $files[$index]['stylesheet_source_path'] = $sourcePath;
                    $files[$index]['stylesheet_occurrence'] = 1;
                    $files[$index]['references'] = array($reference);
                    $occurrences[$sourcePath] = 1;
                    continue;
                }

                // A differing stylesheet-set activation is a different member of
                // the document's stylesheet sets, delivered with its own link
                // attributes, so it keeps its own resource.
                $occurrence = ++$occurrences[$sourcePath];
                $alias = self::allocateStylesheetOccurrencePath(self::stylesheetOccurrencePath($sourcePath, $occurrence), $reserved);
                $aliasFile = $files[$index];
                $aliasFile['path'] = $alias;
                $aliasFile['source'] = 'stylesheet-occurrence';
                $aliasFile['source_path'] = $sourcePath;
                $aliasFile['stylesheet_source_path'] = $sourcePath;
                $aliasFile['stylesheet_occurrence'] = $occurrence;
                $aliasFile['media'] = $media;
                unset($aliasFile['source_media']);
                if (array_key_exists(CaptureEvidence::SOURCE_MEDIA, HtmlTagScanner::attributes($tag))) $aliasFile['source_media'] = StyleTagScanner::authorMedia($tag);
                $aliasFile['type'] = $type;
                $aliasFile['stylesheet_activation'] = $state;
                $aliasFile['references'] = array($reference);
                $aliasFile['provenance']['source_path'] = $sourcePath;
                $files[] = $aliasFile;
                $byPath[$alias] = count($files) - 1;
                $variants[$sourcePath][$variant] = $alias;
            }
        }

        return $files;
    }

    /**
     * The media condition each stylesheet carries when every document linking
     * it agrees on one non-empty value. Disagreement or any unconditioned link
     * leaves the stylesheet unconditioned, as the browser would load it.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<string, string>
     */
    public static function documentLinkMedia(array $files): array
    {
        $media = array();
        foreach ( $files as $file ) {
            if ( 'html' !== ($file['kind'] ?? null) || ! is_string($file['content'] ?? null) ) {
                continue;
            }
            foreach ( StyleTagScanner::scanLinks($file['content']) as $link ) {
                $tag = (string) $link['tag'];
                if ( ! StyleTagScanner::isStylesheetRel(HtmlTagScanner::attribute($tag, 'rel')) || ! StyleTagScanner::isCssType(HtmlTagScanner::attribute($tag, 'type')) ) {
                    continue;
                }
                $path = ArtifactPath::linkedFilePath(HtmlTagScanner::attribute($tag, 'href'), (string) $file['path'], $files);
                if ( '' === $path ) {
                    continue;
                }
                $value = trim(HtmlTagScanner::attribute($tag, 'media'));
                $media[$path] = array_key_exists($path, $media) && $media[$path] !== $value ? '' : $value;
            }
        }

        return array_filter($media, static fn (string $value): bool => '' !== $value && 'all' !== strtolower($value));
    }

    private static function stylesheetOccurrencePath(string $path, int $occurrence): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = '' === $extension ? $path : substr($path, 0, -strlen($extension) - 1);
        return $base . '.occurrence-' . $occurrence . ('' === $extension ? '' : '.' . $extension);
    }

    /** @param array<string, true> $reserved */
    private static function allocateStylesheetOccurrencePath(string $candidate, array &$reserved): string
    {
        $path = $candidate;
        $index = 1;
        while ( isset($reserved[$path]) ) {
            $extension = pathinfo($candidate, PATHINFO_EXTENSION);
            $base = '' === $extension ? $candidate : substr($candidate, 0, -strlen($extension) - 1);
            $path = $base . '-generated-' . $index++ . ('' === $extension ? '' : '.' . $extension);
        }
        $reserved[$path] = true;
        return $path;
    }

    /**
     * Keep source stylesheets as provenance when no HTML document links or
     * imports them. Theme-preference evidence retains its ownership metadata. A
     * linked copy remains an ordinary active stylesheet with its normal order,
     * media, projection and cascade semantics.
     *
     * @param array<int,array<string,mixed>> $files
     * @param array<int,array<string,mixed>> $runtimeDeclarations
     * @return array<int,array<string,mixed>>
     */
    public static function separateUnlinked(array $files, array $runtimeDeclarations): array
    {
        $unlinkedEvidencePaths = array();
        $linkedStylesheetPaths = array();
        $importQueue = array();
        foreach ( $files as $document ) {
            if ( 'html' !== ($document['kind'] ?? null) || ! is_string($document['path'] ?? null) || ! is_string($document['content'] ?? null) ) continue;
            foreach ( StyleTagScanner::scanLinks($document['content']) as $link ) {
                $tag = $link['tag'];
                if ( ! StyleTagScanner::isStylesheetRel(HtmlTagScanner::attribute($tag, 'rel'))
                    || ! StyleTagScanner::isCssType(HtmlTagScanner::attribute($tag, 'type')) ) continue;
                $path = ArtifactPath::linkedFilePath(HtmlTagScanner::attribute($tag, 'href'), $document['path'], $files);
                if ( '' !== $path ) $linkedStylesheetPaths[$path] = true;
            }
            foreach (StyleTagScanner::scan($document['content']) as $style) {
                if (StyleTagScanner::isCssType(HtmlTagScanner::attribute($style['attributes'], 'type'))) {
                    $importQueue[] = array($document['path'], $style['content']);
                }
            }
        }
        $byPath = array_column($files, null, 'path');
        foreach ($linkedStylesheetPaths as $path => $_) {
            $importQueue[] = array($path, $byPath[$path]['content'] ?? '');
        }
        // Walk only imports reachable from a document; a visited path also
        // bounds cycles. Imported author files must remain CSS resources.
        for ($cursor = 0; $cursor < count($importQueue); ++$cursor) {
            [$sourcePath, $content] = $importQueue[$cursor];
            $preamble = (new CssStylesheetTransformer())->splitLeadingAtRulePreamble($content);
            foreach ($preamble['statements'] as $statement) {
                $clean = preg_replace('#^(?:\s|/\*.*?\*/)*#s', '', $statement) ?? $statement;
                if (!preg_match('/^@import\s+(?:url\(\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s)]+))\s*\)|"([^"]+)"|\'([^\']+)\')/is', $clean, $match)) continue;
                $reference = $match[1] ?: ($match[2] ?: ($match[3] ?: ($match[4] ?: $match[5])));
                $path = ArtifactPath::linkedFilePath($reference, $sourcePath, $files);
                if ('' === $path || isset($linkedStylesheetPaths[$path])) continue;
                $linkedStylesheetPaths[$path] = true;
                $importQueue[] = array($path, $byPath[$path]['content'] ?? '');
            }
        }
        foreach ( $runtimeDeclarations as $declaration ) {
            if ( ThemePreferenceOwnership::DECLARATION_KIND !== ($declaration['kind'] ?? null)
                || ThemePreferenceOwnership::DECLARATION_TYPE !== ($declaration['type'] ?? null)
                || ! is_string($declaration['source_path'] ?? null) ) continue;
            try {
                $payload = ThemePreferenceOwnership::normalizePayload($declaration['payload'] ?? null, $declaration['source_path']);
            } catch ( \InvalidArgumentException ) {
                continue;
            }
            $directory = dirname($declaration['source_path']);
            $assetPrefix = '.' === $directory ? 'assets/' : trim($directory, '/') . '/assets/';
            foreach ( $payload['ownership']['stylesheet_evidence'] as $stylesheet ) {
                $path = $stylesheet['path'];
                $candidates = array_values(array_unique(array_filter(array(
                    $path,
                    ArtifactPath::safeRelativePath($assetPrefix . $path),
                ), static fn (string $candidate): bool => '' !== $candidate)));
                foreach ( $files as $index => $file ) {
                    if ( ! in_array($file['path'] ?? null, $candidates, true)
                        || ! preg_match('/\.css$/i', (string) ($file['path'] ?? ''))
                        || ! in_array($file['kind'] ?? null, array('css', 'asset'), true)
                        || ('stylesheet' !== ($file['role'] ?? null) && 'style' !== ($file['intent'] ?? null))
                        || isset($file['stylesheet_occurrence']) || isset($linkedStylesheetPaths[$file['path']]) ) continue;
                    $unlinkedEvidencePaths[$index] = array('source_path' => $path, 'sha256' => $stylesheet['sha256']);
                }
            }
        }

        foreach ( $files as $index => $file ) {
            if ( 'inline-style' === ($file['source'] ?? null)
                || ! preg_match('/\.css$/i', (string) ($file['path'] ?? ''))
                || ! in_array($file['kind'] ?? null, array('css', 'asset'), true)
                || ('stylesheet' !== ($file['role'] ?? null) && 'style' !== ($file['intent'] ?? null))
                || isset($file['stylesheet_occurrence']) || isset($linkedStylesheetPaths[$file['path']]) ) continue;
            $files[$index]['kind'] = 'asset';
            $files[$index]['role'] = 'source-provenance';
            $files[$index]['intent'] = 'evidence';
            if (isset($unlinkedEvidencePaths[$index])) {
                $files[$index]['theme_preference_stylesheet_provenance'] = $unlinkedEvidencePaths[$index];
            }
        }

        return $files;
    }
}
