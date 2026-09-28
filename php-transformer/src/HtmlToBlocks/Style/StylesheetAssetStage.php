<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;

/** Materialize ordered stylesheet contributions into the run's asset contract. */
final class StylesheetAssetStage
{
    public function __construct(private readonly HtmlTransformerSession $session)
    {
    }

    /**
     * @param list<string> $beforeAuthorCssParts
     * @param list<string> $authorCssParts
     * @param list<string> $afterAuthorCssParts
     * @param list<array<string, mixed>> $authorStylesheetProjections
     */
    public function materialize(
        array $beforeAuthorCssParts,
        array $authorCssParts,
        array $afterAuthorCssParts,
        array $authorStylesheetProjections,
        bool $includeAuthorStyles,
        bool $hasAuthorStylesheetAssets
    ): void {
        $this->generated($beforeAuthorCssParts, 'engine-support', 'before-author', 'engine-support-before-author');
        if ( $includeAuthorStyles && $hasAuthorStylesheetAssets ) {
            foreach ( $authorStylesheetProjections as $projection ) {
                $this->authorProjection($projection);
            }
        } else {
            $this->generated($authorCssParts, 'author-css', 'author', 'source-author');
        }
        $this->generated($afterAuthorCssParts, 'engine-support', 'after-author', 'engine-support-after-author');
    }

    /** @param array<int, string> $cssParts */
    public function generated(array $cssParts, string $source, string $placement, string $pathPrefix, string $target = 'both'): void
    {
        $css = trim(implode("\n\n", $cssParts));
        if ( '' === $css ) {
            return;
        }

        $content = $css . "\n";
        $hash = hash('sha256', $content);
        $path = 'assets/css/' . $pathPrefix . '-' . substr($hash, 0, 16) . '.css';

        $this->session->assetMaterializationState()->register($path, array(
            'source'      => $source,
            'source_path' => '',
            'path'        => $path,
            'target_path' => $path,
            'kind'        => 'css',
            'role'        => 'stylesheet',
            'stylesheet_placement' => $placement,
            'stylesheet_target' => $target,
            'mime_type'   => 'text/css',
            'media_type'  => 'text/css',
            'content'     => $content,
            'bytes'       => strlen($content),
            'encoding'    => 'utf-8',
            'binary'      => false,
            'hash'        => $hash,
            'source_hash' => $hash,
        ));
    }

    /** @param array<string, mixed> $projection */
    private function authorProjection(array $projection): void
    {
        $path = trim((string) ($projection['path'] ?? ''), '/');
        $css = trim((string) ($projection['content'] ?? ''));
        if ( '' === $path || '' === $css ) {
            return;
        }

        $content = $css . "\n";
        $hash = hash('sha256', $content);
        $this->session->assetMaterializationState()->register($path, array(
            'source' => 'author-css',
            'source_path' => (string) ($projection['source_path'] ?? ''),
            'path' => $path,
            'target_path' => $path,
            'kind' => 'css',
            'role' => 'stylesheet',
            'stylesheet_placement' => 'author',
            'stylesheet_target' => 'both',
            'mime_type' => 'text/css',
            'media_type' => 'text/css',
            'media' => (string) ($projection['media'] ?? ''),
            'type' => (string) ($projection['type'] ?? ''),
            'content' => $content,
            'bytes' => strlen($content),
            'encoding' => 'utf-8',
            'binary' => false,
            'hash' => $hash,
            'source_hash' => (string) ($projection['source_hash'] ?? $hash),
        ));
    }
}
