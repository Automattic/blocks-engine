<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;

/**
 * Composes typed viewport-specific source documents before artifact normalization.
 */
final class ResponsiveDocumentVariants
{
    /** @param array<string,mixed> $artifact @return array<string,mixed> */
    public function compose(array $artifact): array
    {
        if (!isset($artifact['document_variants'])) {
            return $artifact;
        }
        if (!is_array($artifact['document_variants'])) {
            throw new \InvalidArgumentException('Artifact document_variants must be an array.');
        }

        $filesKey = $this->filesKey($artifact);
        if (null === $filesKey) {
            throw new \InvalidArgumentException('Artifact document variants require an explicit files collection.');
        }
        $files = $artifact[$filesKey];
        $declarations = $artifact['document_variants'];
        usort($declarations, static fn(mixed $left, mixed $right): int => strcmp(
            is_array($left) ? (string) ($left['source_path'] ?? '') : '',
            is_array($right) ? (string) ($right['source_path'] ?? '') : ''
        ));

        foreach ($declarations as $declaration) {
            if (!is_array($declaration)) {
                throw new \InvalidArgumentException('Each document variant declaration must be an object.');
            }
            $sourcePath = $this->safePath($declaration['source_path'] ?? null, 'source_path');
            $variants = $declaration['variants'] ?? null;
            if (!is_array($variants) || array() === $variants) {
                throw new \InvalidArgumentException('Each document variant declaration must contain variants.');
            }
            usort($variants, static fn(mixed $left, mixed $right): int => strcmp(
                is_array($left) ? (string) ($left['id'] ?? '') : '',
                is_array($right) ? (string) ($right['id'] ?? '') : ''
            ));

            $primaryIndex = $this->fileIndex($files, $sourcePath);
            if (null === $primaryIndex) {
                throw new \InvalidArgumentException(sprintf('Document variant primary source "%s" is missing.', $sourcePath));
            }
            $primaryHtml = $this->fileContent($files[$primaryIndex], $sourcePath);
            $variantDocuments = array();
            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    throw new \InvalidArgumentException('Each document variant must be an object.');
                }
                $id = strtolower(trim((string) ($variant['id'] ?? '')));
                if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id)) {
                    throw new \InvalidArgumentException('Document variant ids must be bounded lowercase identifiers.');
                }
                $variantPath = $this->safePath($variant['source_path'] ?? null, 'variant source_path');
                $media = trim((string) ($variant['media'] ?? ''));
                if ('' === $media || strlen($media) > 256 || !preg_match('/^[a-z0-9\s():.,\/_-]+$/i', $media)) {
                    throw new \InvalidArgumentException('Document variant media must be a bounded CSS media query.');
                }
                $variantIndex = $this->fileIndex($files, $variantPath);
                if (null === $variantIndex || $variantPath === $sourcePath) {
                    throw new \InvalidArgumentException(sprintf('Document variant source "%s" is missing or aliases its primary.', $variantPath));
                }
                $variantDocuments[] = array(
                    'id' => $id,
                    'path' => $variantPath,
                    'media' => $media,
                    'html' => $this->fileContent($files[$variantIndex], $variantPath),
                );
            }

            $files[$primaryIndex] = $this->withFileContent(
                $files[$primaryIndex],
                $this->composeDocument($primaryHtml, $variantDocuments, $sourcePath)
            );
            $variantPaths = array_fill_keys(array_column($variantDocuments, 'path'), true);
            foreach ($files as $index => $file) {
                $path = $this->filePath($file, $index);
                if (isset($variantPaths[$path])) {
                    unset($files[$index]);
                }
            }
        }

        $artifact[$filesKey] = array_values($files);
        unset($artifact['document_variants']);
        return $artifact;
    }

    /** @param array<int,array{id:string,path:string,media:string,html:string}> $variants */
    private function composeDocument(string $primaryHtml, array $variants, string $sourcePath): string
    {
        $primaryHtml = $this->scopeDocumentStyles($primaryHtml, 'site-document-variant-default');
        $primaryBody = $this->body($primaryHtml);
        if (null === $primaryBody) {
            throw new \InvalidArgumentException('Document variant primary source must contain a body element.');
        }

        $allClasses = array('site-document-variant-default');
        foreach ($variants as $variant) {
            $allClasses[] = $this->variantClass($variant['id']);
        }
        $controlCss = '';
        $variantMarkup = '';
        $variantStyles = '';
        foreach ($variants as $variant) {
            $variantHtml = $this->rebaseDocumentReferences($variant['html'], $variant['path'], $sourcePath);
            $body = $this->body($variantHtml);
            if (null === $body) {
                throw new \InvalidArgumentException(sprintf('Document variant "%s" must contain a body element.', $variant['path']));
            }
            $variantClass = $this->variantClass($variant['id']);
            // Hide a variant only when its condition does not match. The active
            // wrapper retains the body display and geometry authored by its source.
            $controlCss .= '@media not all and ' . $variant['media'] . '{.' . $variantClass . '{display:none!important}}';
            $controlCss .= '@media ' . $variant['media'] . '{.site-document-variant-default{display:none!important}}';

            $bodyClasses = $this->attribute($body['opening'], 'class');
            $bodyStyle = $this->attribute($body['opening'], 'style');
            $classes = trim($variantClass . ' ' . $bodyClasses);
            $variantMarkup .= '<div class="' . htmlspecialchars($classes, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
                . ('' !== $bodyStyle ? ' style="' . htmlspecialchars($bodyStyle, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"' : '')
                . '>' . preg_replace('@<style\b[^>]*>[\s\S]*?</style\s*>@i', '', $body['content']) . '</div>';

            foreach ($this->styles($variantHtml) as $style) {
                $css = $this->scopeDocumentStylesheet($style['css'], $variantClass);
                $media = '' !== $style['media'] ? $variant['media'] . ' and ' . $style['media'] : $variant['media'];
                $variantStyles .= '<style media="' . htmlspecialchars($media, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . $css . '</style>';
            }
        }

        $primaryClasses = trim('site-document-variant-default ' . $this->attribute($primaryBody['opening'], 'class'));
        $primaryStyle = $this->attribute($primaryBody['opening'], 'style');
        $bodyMarkup = '<div class="' . htmlspecialchars($primaryClasses, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
            . ('' !== $primaryStyle ? ' style="' . htmlspecialchars($primaryStyle, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"' : '')
            . '>' . $primaryBody['content'] . '</div>' . $variantMarkup;
        $composed = substr_replace($primaryHtml, $bodyMarkup, $primaryBody['content_offset'], strlen($primaryBody['content']));
        $styles = '<style>' . $controlCss . '</style>' . $variantStyles;
        return preg_match('@</head\s*>@i', $composed)
            ? (string) preg_replace('@</head\s*>@i', $styles . '</head>', $composed, 1)
            : $styles . $composed;
    }

    private function rebaseDocumentReferences(string $html, string $variantPath, string $primaryPath): string
    {
        $rebase = fn(string $reference): string => $this->rebaseReference($reference, $variantPath, $primaryPath);
        $html = (string) preg_replace_callback(
            '~<\s*[a-z][a-z0-9:-]*(?:\s+(?:"[^"]*"|\'[^\']*\'|[^\'"<>])*)?/?>~is',
            static function (array $match) use ($rebase): string {
                preg_match('~^<\s*([a-z][a-z0-9:-]*)~i', $match[0], $elementMatch);
                $element = strtolower((string) ($elementMatch[1] ?? ''));
                return (string) preg_replace_callback(
                    '~(?<![a-z0-9:_-])(xlink:href|srcset|src|href|poster|style)\s*=\s*(["\'])(.*?)\2~is',
                    static function (array $attribute) use ($element, $rebase): string {
                        $name = strtolower($attribute[1]);
                        $value = 'href' === $name && in_array($element, array('a', 'area'), true)
                            ? $attribute[3]
                            : ('style' === $name
                                ? self::rebaseCss($attribute[3], $rebase)
                                : ('srcset' === $name ? SrcsetParser::rewrite($attribute[3], $rebase) : $rebase($attribute[3])));
                        return $attribute[1] . '=' . $attribute[2] . $value . $attribute[2];
                    },
                    $match[0]
                );
            },
            $html
        );
        return (string) preg_replace_callback(
            '~<style\b[^>]*>(.*?)</style\s*>~is',
            static fn(array $match): string => str_replace($match[1], self::rebaseCss($match[1], $rebase), $match[0]),
            $html
        );
    }

    /** @param callable(string):string $rebase */
    private static function rebaseCss(string $css, callable $rebase): string
    {
        $css = CssUrlRewriter::rewrite($css, $rebase);
        return (string) preg_replace_callback(
            '~(@import\s+)(["\'])([^"\']+)\2~i',
            static fn(array $match): string => $match[1] . $match[2] . $rebase($match[3]) . $match[2],
            $css
        );
    }

    private function rebaseReference(string $reference, string $variantPath, string $primaryPath): string
    {
        if ('' === trim($reference) || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/|#|\?)~i', $reference)) {
            return $reference;
        }
        preg_match('/^([^?#]*)(.*)$/s', $reference, $parts);
        $resolved = ArtifactPath::resolveRelativePath((string) ($parts[1] ?? ''), $variantPath);
        if ('' === $resolved) {
            return $reference;
        }
        $from = '.' === dirname($primaryPath) ? array() : explode('/', dirname($primaryPath));
        $to = explode('/', $resolved);
        while (array() !== $from && array() !== $to && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        return implode('/', array_merge(array_fill(0, count($from), '..'), $to)) . (string) ($parts[2] ?? '');
    }

    private function scopeDocumentSelectors(string $css): string
    {
        return (new CssStylesheetTransformer())->transform($css, static function (string $prelude): string {
            $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
            if (null === $selectors) {
                return $prelude;
            }
            foreach ($selectors as &$selector) {
                $selector = (string) preg_replace('/(?<![-_a-z0-9])(?::root|html|body|:host)(?![-_a-z0-9(])/i', ':scope', $selector);
                $selector = (string) preg_replace_callback(
                    '/(?<![-_a-z0-9]):host\(([^)]+)\)/i',
                    static fn(array $match): string => ':scope' . $match[1],
                    $selector
                );
                $selector = (string) preg_replace('/:scope(?:\s+|\s*[>+~]\s*):scope/', ':scope', $selector);
            }
            unset($selector);
            return implode(',', $selectors);
        });
    }

    private function scopeDocumentStylesheet(string $css, string $variantClass): string
    {
        $split = (new CssStylesheetTransformer())->splitLeadingAtRulePreamble($css);
        return $split['preamble'] . '@scope (.' . $variantClass . '){'
            . $this->scopeDocumentSelectors($split['stylesheet']) . '}';
    }

    private function scopeDocumentStyles(string $html, string $variantClass): string
    {
        return (string) preg_replace_callback(
            '@(<style\b[^>]*>)([\s\S]*?)(</style\s*>)@i',
            fn(array $match): string => $match[1] . $this->scopeDocumentStylesheet($match[2], $variantClass) . $match[3],
            $html
        );
    }

    /** @return array{opening:string,content:string,content_offset:int}|null */
    private function body(string $html): ?array
    {
        if (!preg_match('@<body\b[^>]*>([\s\S]*?)</body\s*>@i', $html, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $openingLength = strpos($match[0][0], '>') + 1;
        return array(
            'opening' => substr($match[0][0], 0, $openingLength),
            'content' => $match[1][0],
            'content_offset' => $match[0][1] + $openingLength,
        );
    }

    /** @return array<int,array{css:string,media:string}> */
    private function styles(string $html): array
    {
        if (!preg_match_all('@<style\b([^>]*)>([\s\S]*?)</style\s*>@i', $html, $matches, PREG_SET_ORDER)) {
            return array();
        }
        return array_map(fn(array $match): array => array(
            'css' => (string) $match[2],
            'media' => $this->attribute('<style ' . $match[1] . '>', 'media'),
        ), $matches);
    }

    private function attribute(string $tag, string $name): string
    {
        return preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match)
            ? html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';
    }

    private function variantClass(string $id): string
    {
        return 'site-document-variant-' . $id;
    }

    /** @param array<string,mixed> $artifact */
    private function filesKey(array $artifact): ?string
    {
        foreach (array('files', 'artifacts', 'outputs') as $key) {
            if (is_array($artifact[$key] ?? null)) {
                return $key;
            }
        }
        return null;
    }

    private function safePath(mixed $value, string $field): string
    {
        $path = is_string($value) ? ArtifactPath::safeRelativePath($value) : '';
        if ('' === $path) {
            throw new \InvalidArgumentException(sprintf('Document variant %s must be a safe relative path.', $field));
        }
        return $path;
    }

    /** @param array<int|string,mixed> $files */
    private function fileIndex(array $files, string $path): int|string|null
    {
        foreach ($files as $index => $file) {
            if ($path === $this->filePath($file, $index)) {
                return $index;
            }
        }
        return null;
    }

    private function filePath(mixed $file, int|string $index): string
    {
        $candidate = is_array($file) ? ($file['path'] ?? $file['name'] ?? $index) : $index;
        return is_scalar($candidate) ? ArtifactPath::safeRelativePath((string) $candidate) : '';
    }

    private function fileContent(mixed $file, string $path): string
    {
        if (is_string($file)) {
            return $file;
        }
        if (!is_array($file)) {
            throw new \InvalidArgumentException(sprintf('Document variant source "%s" is not a file record.', $path));
        }
        if (is_string($file['content'] ?? null)) {
            return $file['content'];
        }
        if (is_string($file['content_base64'] ?? null)) {
            $decoded = base64_decode($file['content_base64'], true);
            if (false !== $decoded) {
                return $decoded;
            }
        }
        throw new \InvalidArgumentException(sprintf('Document variant source "%s" requires embedded text content.', $path));
    }

    private function withFileContent(mixed $file, string $content): mixed
    {
        if (is_string($file)) {
            return $content;
        }
        $file['content'] = $content;
        $file['encoding'] = 'utf8';
        unset($file['content_base64'], $file['bytes']);
        return $file;
    }
}
