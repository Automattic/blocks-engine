<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssStylesheetTransformer;
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
                $this->composeDocument($primaryHtml, $variantDocuments)
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
    private function composeDocument(string $primaryHtml, array $variants): string
    {
        $primaryBody = $this->body($primaryHtml);
        if (null === $primaryBody) {
            throw new \InvalidArgumentException('Document variant primary source must contain a body element.');
        }

        $allClasses = array('site-document-variant-default');
        foreach ($variants as $variant) {
            $allClasses[] = $this->variantClass($variant['id']);
        }
        $controlCss = '.' . implode(',.', array_slice($allClasses, 1)) . '{display:none!important}';
        $variantMarkup = '';
        $variantStyles = '';
        foreach ($variants as $variant) {
            $body = $this->body($variant['html']);
            if (null === $body) {
                throw new \InvalidArgumentException(sprintf('Document variant "%s" must contain a body element.', $variant['path']));
            }
            $variantClass = $this->variantClass($variant['id']);
            $hidden = array_map(static fn(string $class): string => '.' . $class, $allClasses);
            $controlCss .= '@media ' . $variant['media'] . '{' . implode(',', $hidden) . '{display:none!important}.' . $variantClass . '{display:contents!important}}';

            $bodyClasses = $this->attribute($body['opening'], 'class');
            $bodyStyle = $this->attribute($body['opening'], 'style');
            $classes = trim($variantClass . ' ' . $bodyClasses);
            $variantMarkup .= '<div class="' . htmlspecialchars($classes, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
                . ('' !== $bodyStyle ? ' style="' . htmlspecialchars($bodyStyle, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"' : '')
                . '>' . preg_replace('@<style\b[^>]*>[\s\S]*?</style\s*>@i', '', $body['content']) . '</div>';

            foreach ($this->styles($variant['html']) as $style) {
                $css = $this->scopeDocumentSelectors($style['css'], $variantClass);
                $media = '' !== $style['media'] ? $variant['media'] . ' and ' . $style['media'] : $variant['media'];
                $variantStyles .= '<style media="' . htmlspecialchars($media, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . $css . '</style>';
            }
        }

        $bodyMarkup = '<div class="site-document-variant-default">' . $primaryBody['content'] . '</div>' . $variantMarkup;
        $composed = substr_replace($primaryHtml, $bodyMarkup, $primaryBody['content_offset'], strlen($primaryBody['content']));
        $styles = '<style>' . $controlCss . '</style>' . $variantStyles;
        return preg_match('@</head\s*>@i', $composed)
            ? (string) preg_replace('@</head\s*>@i', $styles . '</head>', $composed, 1)
            : $styles . $composed;
    }

    private function scopeDocumentSelectors(string $css, string $variantClass): string
    {
        $scope = '.' . $variantClass;
        return (new CssStylesheetTransformer())->transform($css, static function (string $prelude) use ($scope): string {
            $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
            if (null === $selectors) {
                return $prelude;
            }
            foreach ($selectors as &$selector) {
                $selector = (string) preg_replace('/(?<![-_a-z0-9])(?::root|html|body)(?![-_a-z0-9])/i', $scope, $selector);
                $quoted = preg_quote($scope, '/');
                $selector = (string) preg_replace('/' . $quoted . '(?:\s+|\s*[>+~]\s*)' . $quoted . '/', $scope, $selector);
            }
            unset($selector);
            return implode(',', $selectors);
        });
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
