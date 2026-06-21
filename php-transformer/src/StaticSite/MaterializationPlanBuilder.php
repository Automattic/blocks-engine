<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\StaticSite;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;

final class MaterializationPlanBuilder
{
    public const SCHEMA = 'blocks-engine/php-transformer/materialization-plan/v1';

    /**
     * @return array<string,mixed>
     */
    public function fromResult(TransformerResult|array $result): array
    {
        $data = $result instanceof TransformerResult ? $result->toArray() : $result;
        $compiledSite = $data['source_reports']['compiled_site'] ?? array();
        return is_array($compiledSite) ? $this->fromCompiledSite($compiledSite) : $this->emptyPlan();
    }

    /**
     * @param array<string,mixed> $compiledSite
     * @return array<string,mixed>
     */
    public function fromCompiledSite(array $compiledSite): array
    {
        $pages = $this->pages((array) ($compiledSite['pages'] ?? array()));
        $templateParts = $this->templateParts((array) ($compiledSite['template_parts'] ?? array()));
        $assets = $this->assets((array) ($compiledSite['assets'] ?? array()));
        $visualRepair = is_array($compiledSite['visual_repair'] ?? null) ? $compiledSite['visual_repair'] : array();
        $assetRewriteCandidates = $this->assetRewriteCandidates($pages, $templateParts, $assets);

        $plan = array(
            'schema'         => self::SCHEMA,
            'source_schema'  => (string) ($compiledSite['schema'] ?? ''),
            'source_hash'    => (string) ($compiledSite['source_hash'] ?? ''),
            'entry_path'     => (string) ($compiledSite['entry_path'] ?? ''),
            'pages'          => $pages,
            'template_parts' => $templateParts,
            'template_part_writes' => $this->templatePartWrites($templateParts),
            'assets'         => $assets,
            'theme'          => $this->theme((array) ($compiledSite['theme'] ?? array()), $templateParts, $assets, $visualRepair),
            'visual_repair_css' => (string) ($visualRepair['css'] ?? ''),
            'asset_rewrite_candidates' => $assetRewriteCandidates,
            'rewrite_candidates' => $assetRewriteCandidates,
            'products'       => is_array($compiledSite['products'] ?? null) ? $compiledSite['products'] : array(),
            'totals'         => array(
                'pages'          => count($pages),
                'template_parts' => count($templateParts),
                'assets'         => count($assets),
            ),
        );

        if ( array() === $plan['products'] ) {
            unset($plan['products']);
        }

        return $plan;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyPlan(): array
    {
        return array(
            'schema' => self::SCHEMA,
            'pages' => array(),
            'template_parts' => array(),
            'template_part_writes' => array(),
            'assets' => array(),
            'theme' => array(),
            'asset_rewrite_candidates' => array(),
            'rewrite_candidates' => array(),
            'totals' => array('pages' => 0, 'template_parts' => 0, 'assets' => 0),
        );
    }

    /**
     * @param array<int,mixed> $pages
     * @return array<int,array<string,mixed>>
     */
    private function pages(array $pages): array
    {
        $planned = array();
        foreach ( $pages as $page ) {
            if ( ! is_array($page) ) {
                continue;
            }
            $planned[] = array_filter(array(
                'source_path' => (string) ($page['source_path'] ?? ''),
                'slug'        => (string) ($page['slug'] ?? ''),
                'title'       => (string) ($page['title'] ?? ''),
                'post_type'   => (string) (($page['metadata']['post_type'] ?? '') ?: 'page'),
                'body_format' => (string) ($page['body_format'] ?? ''),
                'block_markup' => (string) ($page['block_markup'] ?? ''),
                'entrypoint'  => ! empty($page['entrypoint']),
                'metadata'    => is_array($page['metadata'] ?? null) ? $page['metadata'] : array(),
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }
        return $planned;
    }

    /**
     * @param array<int,mixed> $templateParts
     * @return array<int,array<string,mixed>>
     */
    private function templateParts(array $templateParts): array
    {
        $planned = array();
        foreach ( $templateParts as $part ) {
            if ( ! is_array($part) ) {
                continue;
            }
            $planned[] = array_filter(array(
                'source_path' => (string) ($part['source_path'] ?? ''),
                'slug'        => (string) ($part['slug'] ?? ''),
                'title'       => (string) ($part['title'] ?? ''),
                'area'        => (string) ($part['area'] ?? 'uncategorized'),
                'body_format' => (string) ($part['body_format'] ?? ''),
                'block_markup' => (string) ($part['block_markup'] ?? ''),
                'metadata'    => is_array($part['metadata'] ?? null) ? $part['metadata'] : array(),
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }
        return $planned;
    }

    /**
     * @param array<int,array<string,mixed>> $templateParts
     * @return array<int,array<string,mixed>>
     */
    private function templatePartWrites(array $templateParts): array
    {
        $writes = array();
        foreach ( $templateParts as $part ) {
            $writes[] = array_filter(array(
                'type'        => 'wp_template_part',
                'source_path' => (string) ($part['source_path'] ?? ''),
                'slug'        => (string) ($part['slug'] ?? ''),
                'title'       => (string) ($part['title'] ?? ''),
                'area'        => (string) ($part['area'] ?? 'uncategorized'),
                'content'     => (string) ($part['block_markup'] ?? ''),
            ), static fn (mixed $value): bool => '' !== $value);
        }
        return $writes;
    }

    /**
     * @param array<int,mixed> $assets
     * @return array<int,array<string,mixed>>
     */
    private function assets(array $assets): array
    {
        $planned = array();
        foreach ( $assets as $asset ) {
            if ( ! is_array($asset) ) {
                continue;
            }
            $planned[] = array_filter(array(
                'path'      => (string) ($asset['path'] ?? ''),
                'kind'      => (string) ($asset['kind'] ?? ''),
                'role'      => (string) ($asset['role'] ?? ''),
                'intent'    => (string) ($asset['intent'] ?? ''),
                'mime_type' => (string) ($asset['mime_type'] ?? ''),
                'bytes'     => (int) ($asset['bytes'] ?? 0),
                'binary'    => ! empty($asset['binary']),
            ), static fn (mixed $value): bool => '' !== $value && 0 !== $value && false !== $value);
        }
        return $planned;
    }

    /**
     * @param array<string,mixed> $theme
     * @param array<int,array<string,mixed>> $templateParts
     * @param array<int,array<string,mixed>> $assets
     * @param array<string,mixed> $visualRepair
     * @return array<string,mixed>
     */
    private function theme(array $theme, array $templateParts, array $assets, array $visualRepair): array
    {
        return array_filter(array(
            'stylesheets' => $theme['stylesheets'] ?? $this->assetPathsByRole($assets, 'stylesheet'),
            'scripts' => $theme['scripts'] ?? $this->assetPathsByRole($assets, 'script'),
            'fonts' => $theme['fonts'] ?? $this->assetPathsByRole($assets, 'font'),
            'images' => $theme['images'] ?? $this->assetPathsByRole($assets, 'image'),
            'template_parts' => array_values(array_map(static fn (array $part): string => (string) ($part['source_path'] ?? ''), $templateParts)),
            'visual_repair_css' => (string) ($visualRepair['css'] ?? ''),
        ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
    }

    /**
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,string>
     */
    private function assetPathsByRole(array $assets, string $role): array
    {
        $paths = array();
        foreach ( $assets as $asset ) {
            if ( $role === ($asset['role'] ?? '') && '' !== ($asset['path'] ?? '') ) {
                $paths[] = (string) $asset['path'];
            }
        }
        return $paths;
    }

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $templateParts
     * @param array<int,array<string,mixed>> $assets
     * @return array<int,array<string,mixed>>
     */
    private function assetRewriteCandidates(array $pages, array $templateParts, array $assets): array
    {
        $assetPaths = array_values(array_filter(array_map(static fn (array $asset): string => (string) ($asset['path'] ?? ''), $assets)));
        if ( array() === $assetPaths ) {
            return array();
        }

        $candidates = array();
        foreach ( array('page' => $pages, 'template_part' => $templateParts) as $scope => $documents ) {
            foreach ( $documents as $document ) {
                $markup = (string) ($document['block_markup'] ?? '');
                foreach ( $assetPaths as $assetPath ) {
                    if ( '' === $markup || ! str_contains($markup, $assetPath) ) {
                        continue;
                    }

                    $candidates[] = array_filter(array(
                        'scope'       => $scope,
                        'source_path' => (string) ($document['source_path'] ?? ''),
                        'slug'        => (string) ($document['slug'] ?? ''),
                        'asset_path'  => $assetPath,
                    ), static fn (mixed $value): bool => '' !== $value);
                }
            }
        }

        return $candidates;
    }
}
