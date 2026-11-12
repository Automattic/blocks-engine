<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use InvalidArgumentException;

/**
 * The theme files every site plan writes around its documents.
 *
 * `style.css` and `theme.json` scaffold the theme, `functions.php` bootstraps
 * its assets, scripts and runtime, and `document-canvas.php` is written when a
 * document root needs it. Each template and template part is written beside
 * them. Validation rebuilds the bootstrap and canvas from the plan and proves
 * the plan's writes hold exactly those bytes.
 */
final class ThemeScaffold
{
    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $templates @param array<int,array<string,mixed>> $parts @param array<int,array<string,mixed>> $pages @param array<int,array<string,mixed>> $menus @return array<int,array<string,mixed>> */
    public static function writes(array $assets, array $templates, array $parts, array $scripts, array $theme, array $tokens, array $pages = array(), array $menus = array(), bool $runtimeEmoji = false, array $rootDocuments = array()): array
    {
        $writes = array(WordPressSitePlan::write('theme_scaffold', 'style.css', "/*\nTheme Name: Blocks Engine Site\nText Domain: blocks-engine-site\n*/\n"), WordPressSitePlan::write('theme_scaffold', 'theme.json', json_encode($theme, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"));
        $writes[] = WordPressSitePlan::write('theme_bootstrap', 'functions.php', ThemeBootstrap::functionsPhp($assets, $scripts, $parts, $tokens, $templates, $pages, $menus, $runtimeEmoji, $rootDocuments));
        if (DocumentRootContext::needsCanvas(array_merge($pages, $rootDocuments))) $writes[] = WordPressSitePlan::write('theme_scaffold', 'document-canvas.php', DocumentRootContext::canvas());
        foreach ( $templates as $template ) $writes[] = WordPressSitePlan::write('theme_template', $template['target_path'], $template['canonical_block_markup']);
        foreach ( $parts as $part ) $writes[] = WordPressSitePlan::write('theme_template_part', 'parts/' . $part['slug'] . '.html', $part['canonical_block_markup']);
        return $writes;
    }

    /** @param array<string,mixed> $plan @param array<string,array<string,mixed>> $writes */
    public static function assertValid(array $plan, array $writes): void
    {
        $style = $writes['style.css'] ?? null;
        $themeJson = $writes['theme.json'] ?? null;
        if (!is_array($style) || 'theme_scaffold' !== ($style['kind'] ?? null) || 'wordpress-site-plan/style.css' !== ($style['source_path'] ?? null) || !preg_match('/^\/\*\nTheme Name:\s+[^\n]+\nText Domain:\s+[a-z0-9-]+\n\*\/\n$/', (string) ($style['payload']['data'] ?? ''))) throw new InvalidArgumentException('WordPress site plan style.css scaffold is invalid.');
        if (!is_array($themeJson) || 'theme_scaffold' !== ($themeJson['kind'] ?? null) || 'wordpress-site-plan/theme.json' !== ($themeJson['source_path'] ?? null)) throw new InvalidArgumentException('WordPress site plan theme.json scaffold is invalid.');
        try { $theme = json_decode((string) $themeJson['payload']['data'], true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new InvalidArgumentException('WordPress site plan theme.json is not valid JSON.'); }
        if (!is_array($theme) || 3 !== ($theme['version'] ?? null) || !is_array($theme['settings'] ?? null) || !is_array($theme['styles'] ?? null)) throw new InvalidArgumentException('WordPress site plan theme.json shape is unsupported.');
        $bootstrap = $writes['functions.php'] ?? null;
        $scriptLoading = DocumentScripts::loading($plan['pages'], $plan['template_parts'], $plan['assets'], $plan['reference_tokens'], $plan['operations'], $plan['runtime_declarations']);
        if (!is_array($bootstrap) || 'theme_bootstrap' !== ($bootstrap['kind'] ?? null) || 'wordpress-site-plan/functions.php' !== ($bootstrap['source_path'] ?? null) || ThemeBootstrap::functionsPhp($plan['assets'], $scriptLoading['scripts'], $plan['template_parts'], $plan['reference_tokens'], $plan['templates'], $plan['pages'], $plan['menus'], 'wordpress' === ($plan['theme']['runtime_emoji'] ?? null), $plan['theme']['document_roots'] ?? array()) !== ($bootstrap['canonical_payload'] ?? $bootstrap['payload']['data'] ?? null)) throw new InvalidArgumentException('WordPress site plan functions.php bootstrap is invalid.');
        if (DocumentRootContext::needsCanvas(array_merge($plan['pages'], $plan['theme']['document_roots'] ?? array()))) {
            $canvas = $writes['document-canvas.php'] ?? null;
            if (!is_array($canvas) || 'theme_scaffold' !== ($canvas['kind'] ?? null) || 'wordpress-site-plan/document-canvas.php' !== ($canvas['source_path'] ?? null) || DocumentRootContext::canvas() !== ($canvas['payload']['data'] ?? null)) throw new InvalidArgumentException('WordPress site plan document canvas is invalid.');
        }
    }
}
