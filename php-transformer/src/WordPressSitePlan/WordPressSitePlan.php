<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use InvalidArgumentException;

/** A complete, destination-independent block-theme materialization contract. */
final class WordPressSitePlan
{
    public const SCHEMA = 'blocks-engine/wordpress-site-plan/v2';
    public const TOKEN_PREFIX = '{{wordpress-site-plan:asset:';

    /** @return array<string,mixed> */
    public function fromResult(TransformerResult|array $result): array
    {
        $data = $result instanceof TransformerResult ? $result->toArray() : $result;
        TransformerResult::assertCanonicalEnvelope($data);
        $compiled = $data['source_reports']['compiled_site'] ?? null;
        $materialization = $data['source_reports']['materialization_plan'] ?? null;
        if ( ! is_array($compiled) || ! is_array($materialization) ) {
            throw new InvalidArgumentException('WordPress site plan requires compiled-site and materialization-plan reports.');
        }

        $assets = $this->assets($compiled['assets'] ?? null);
        $tokens = $this->tokens($assets);
        $pages = $this->documents($compiled['pages'] ?? null, false, $tokens);
        $parts = $this->documents($compiled['template_parts'] ?? null, true, $tokens);
        $templates = $this->templates($pages);
        $writes = array_merge($this->scaffoldWrites($assets, $templates, $parts), $this->assetWrites($assets, $tokens));
        $plan = array(
            'schema' => self::SCHEMA,
            'source' => array('schema' => $compiled['schema'] ?? null, 'source_hash' => $compiled['source_hash'] ?? null, 'entry_path' => $compiled['entry_path'] ?? null, 'provenance' => $data['provenance']),
            'pages' => $pages,
            'templates' => $templates,
            'template_parts' => $parts,
            'assets' => $assets,
            'reference_tokens' => $tokens,
            'writes' => $writes,
            'routes' => $materialization['routes'] ?? null,
            'navigation_links' => $materialization['navigation_links'] ?? null,
            'menus' => $materialization['menus'] ?? null,
            'theme' => array('stylesheet' => 'style.css', 'theme_json' => 'theme.json', 'bootstrap' => $this->needsBootstrap($assets) ? 'functions.php' : null),
            'visual_repair' => $compiled['visual_repair'] ?? array(),
            'diagnostics' => $data['diagnostics'],
            'quality' => array('status' => $data['status'], 'metrics' => array_diff_key($data['metrics'], array('transform_duration_ms' => true)), 'fallbacks' => $data['fallbacks']),
        );
        self::assertValid($plan);
        return $plan;
    }

    /** @param array<string,mixed> $plan */
    public static function assertValid(array $plan): void
    {
        if ( self::SCHEMA !== ($plan['schema'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan has an unsupported schema.');
        }
        foreach ( array('source', 'pages', 'templates', 'template_parts', 'assets', 'reference_tokens', 'writes', 'routes', 'navigation_links', 'menus', 'theme', 'visual_repair', 'diagnostics', 'quality') as $key ) {
            if ( ! is_array($plan[$key] ?? null) ) {
                throw new InvalidArgumentException(sprintf('WordPress site plan %s must be an array.', $key));
            }
        }
        self::assertSource($plan['source']);
        self::assertRows($plan['routes'], 'route', array('kind', 'source_path', 'target_path', 'target_slug', 'source_relation', 'order'));
        self::assertRows($plan['navigation_links'], 'navigation link', array('kind', 'source_path', 'source_relation', 'order'), array('target_path', 'target_slug'));
        self::assertRows($plan['menus'], 'menu', array('kind', 'source_path', 'target_slug', 'source_relation', 'order', 'items'));
        $assetTargets = array();
        $assetTokens = array();
        foreach ( $plan['assets'] as $asset ) {
            if ( ! is_array($asset) || ! self::safePath($asset['source_path'] ?? null) || ! self::safePath($asset['target_path'] ?? null) || ! is_string($asset['token'] ?? null) ) {
                throw new InvalidArgumentException('WordPress site plan asset is structurally invalid.');
            }
            self::unique($assetTargets, $asset['target_path'], 'asset target');
            $assetTokens[$asset['target_path']] = $asset['token'];
        }
        $tokens = array();
        foreach ( $plan['reference_tokens'] as $reference ) {
            if ( ! is_array($reference) || ! is_string($reference['token'] ?? null) || ! self::safePath($reference['target_path'] ?? null) || ! isset($assetTargets[$reference['target_path']]) || $assetTokens[$reference['target_path']] !== $reference['token'] || ! preg_match('/^asset-[a-f0-9]{16}$/', $reference['token']) ) {
                throw new InvalidArgumentException('WordPress site plan has an invalid reference token declaration.');
            }
            self::unique($tokens, $reference['token'], 'reference token');
        }
        if ( count($tokens) !== count($assetTargets) ) {
            throw new InvalidArgumentException('WordPress site plan must declare exactly one token for each asset.');
        }
        $partSlugs = array();
        foreach ( $plan['template_parts'] as $part ) {
            self::assertDocument($part, 'template part', true, $tokens);
            self::unique($partSlugs, $part['slug'], 'template part slug');
        }
        $pagePaths = array();
        foreach ( $plan['pages'] as $page ) {
            self::assertDocument($page, 'page', false, $tokens);
            self::unique($pagePaths, $page['source_path'], 'page source');
        }
        $templateTargets = array();
        foreach ( $plan['templates'] as $template ) {
            if ( ! is_array($template) || ! is_string($template['slug'] ?? null) || ! self::safePath($template['target_path'] ?? null) || ! is_string($template['canonical_block_markup'] ?? null) || '' === trim($template['canonical_block_markup']) ) {
                throw new InvalidArgumentException('WordPress site plan template is structurally invalid.');
            }
            self::unique($templateTargets, $template['target_path'], 'template target');
            self::assertTokens($template['canonical_block_markup'], $tokens);
        }
        $writeTargets = array();
        $writesByTarget = array();
        foreach ( $plan['writes'] as $write ) {
            self::assertWrite($write, $tokens);
            self::unique($writeTargets, $write['target_path'], 'write target');
            $writesByTarget[$write['target_path']] = $write;
        }
        foreach ( array('style.css', 'theme.json', 'templates/index.html') as $required ) {
            if ( ! isset($writeTargets[$required]) ) {
                throw new InvalidArgumentException(sprintf('WordPress site plan lacks required theme scaffold write %s.', $required));
            }
        }
        foreach ( $templateTargets as $target => $_ ) {
            if ( ! isset($writeTargets[$target]) ) {
                throw new InvalidArgumentException('WordPress site plan template lacks a write.');
            }
        }
        foreach ( $partSlugs as $slug => $_ ) {
            if ( ! isset($writeTargets['parts/' . $slug . '.html']) ) {
                throw new InvalidArgumentException('WordPress site plan template part lacks a write.');
            }
        }
        foreach ( $plan['assets'] as $asset ) {
            $target = $asset['target_path'];
            if ( ! isset($writesByTarget[$target]) || 'theme_asset' !== ($writesByTarget[$target]['kind'] ?? null) || $writesByTarget[$target]['source_path'] !== $asset['source_path'] ) {
                throw new InvalidArgumentException('WordPress site plan asset lacks a write.');
            }
        }
        if ( ! is_string($plan['theme']['stylesheet'] ?? null) || ! is_string($plan['theme']['theme_json'] ?? null) || (null !== ($plan['theme']['bootstrap'] ?? null) && ! is_string($plan['theme']['bootstrap'])) ) {
            throw new InvalidArgumentException('WordPress site plan theme is structurally invalid.');
        }
        if ( ! is_string($plan['quality']['status'] ?? null) || ! is_array($plan['quality']['metrics'] ?? null) || ! is_array($plan['quality']['fallbacks'] ?? null) ) {
            throw new InvalidArgumentException('WordPress site plan quality is structurally invalid.');
        }
    }

    /** @param mixed $documents @param array<int,array<string,string>> $tokens @return array<int,array<string,mixed>> */
    private function documents(mixed $documents, bool $part, array $tokens): array
    {
        if ( ! is_array($documents) ) {
            throw new InvalidArgumentException('Compiled site documents must be an array.');
        }
        $rows = array();
        foreach ( $documents as $document ) {
            if ( ! is_array($document) || ! self::safePath($document['source_path'] ?? null) || ! is_string($document['block_markup'] ?? null) || '' === trim($document['block_markup']) ) {
                throw new InvalidArgumentException('Compiled site document lacks a safe identity or block markup.');
            }
            $rows[] = array('source_path' => $document['source_path'], 'slug' => self::value($document, 'slug'), 'title' => self::value($document, 'title'), 'post_type' => self::value((array) ($document['metadata'] ?? array()), 'post_type', 'page'), 'parent_source_path' => self::value((array) ($document['metadata'] ?? array()), 'parent_source_path'), 'entrypoint' => ! empty($document['entrypoint']), 'area' => $part ? self::value($document, 'area', 'uncategorized') : null, 'canonical_block_markup' => $this->tokenize($document['block_markup'], $tokens), 'metadata' => is_array($document['metadata'] ?? null) ? $document['metadata'] : array(), 'provenance' => is_array($document['provenance'] ?? null) ? $document['provenance'] : array(), 'reconciliation_identity' => hash('sha256', $document['source_path'] . "\n" . $document['block_markup']));
        }
        return $rows;
    }

    /** @param mixed $assets @return array<int,array<string,mixed>> */
    private function assets(mixed $assets): array
    {
        if ( ! is_array($assets) ) throw new InvalidArgumentException('Compiled site assets must be an array.');
        $rows = array();
        foreach ( $assets as $asset ) {
            if ( ! is_array($asset) || ! self::safePath($asset['path'] ?? null) ) throw new InvalidArgumentException('Compiled site asset lacks a safe source identity.');
            // The compiler retains rejected source assets for diagnostics. They have no
            // payload and therefore are not materializable theme artifacts.
            if ( ! is_string($asset['content'] ?? null) && ! is_string($asset['content_base64'] ?? null) ) continue;
            $compiledTarget = $asset['target_path'] ?? $asset['path'];
            if ( ! self::safePath($compiledTarget) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $target = 'assets/' . str_replace('\\', '/', $compiledTarget);
            if ( ! self::safePath($target) ) throw new InvalidArgumentException('Compiled site asset lacks a safe target identity.');
            $rows[] = array('source_path' => $asset['path'], 'target_path' => $target, 'token' => 'asset-' . substr(hash('sha256', $target), 0, 16), 'source' => self::value($asset, 'source'), 'kind' => self::value($asset, 'kind'), 'role' => self::value($asset, 'role'), 'intent' => self::value($asset, 'intent'), 'mime_type' => self::value($asset, 'mime_type'), 'media' => self::value($asset, 'media'), 'hash' => self::value($asset, 'hash'), 'content' => $asset['content'] ?? null, 'content_base64' => $asset['content_base64'] ?? null, 'binary' => ! empty($asset['binary']));
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $assets @return array<int,array<string,string>> */
    private function tokens(array $assets): array { return array_map(static fn(array $asset): array => array('token' => $asset['token'], 'target_path' => $asset['target_path']), $assets); }

    /** @param array<int,array<string,mixed>> $pages @return array<int,array<string,string>> */
    private function templates(array $pages): array
    {
        $templates = array(array('slug' => 'index', 'target_path' => 'templates/index.html', 'canonical_block_markup' => '<!-- wp:post-content /-->'));
        if ( array() !== $pages ) $templates[] = array('slug' => 'page', 'target_path' => 'templates/page.html', 'canonical_block_markup' => '<!-- wp:post-content /-->');
        foreach ( $pages as $page ) if ( ! empty($page['entrypoint']) ) { $templates[] = array('slug' => 'front-page', 'target_path' => 'templates/front-page.html', 'canonical_block_markup' => '<!-- wp:post-content /-->'); break; }
        return $templates;
    }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $tokens @return array<int,array<string,mixed>> */
    private function assetWrites(array $assets, array $tokens): array
    {
        $writes = array();
        foreach ( $assets as $asset ) {
            $content = is_string($asset['content'] ?? null) ? $this->tokenize($asset['content'], $tokens) : null;
            $data = is_string($asset['content_base64'] ?? null) ? $asset['content_base64'] : (is_string($content) ? (! empty($asset['binary']) || 1 !== preg_match('//u', $content) ? base64_encode($content) : $content) : null);
            if ( ! is_string($data) ) throw new InvalidArgumentException(sprintf('Compiled site asset %s lacks a materializable payload.', $asset['source_path']));
            $writes[] = array('kind' => 'theme_asset', 'source_path' => $asset['source_path'], 'target_path' => $asset['target_path'], 'payload' => array('encoding' => is_string($asset['content_base64'] ?? null) || ! empty($asset['binary']) || 1 !== preg_match('//u', $data) ? 'base64' : 'utf8', 'data' => $data));
        }
        return $writes;
    }

    /** @param array<int,array<string,mixed>> $assets @param array<int,array<string,string>> $templates @param array<int,array<string,mixed>> $parts @return array<int,array<string,mixed>> */
    private function scaffoldWrites(array $assets, array $templates, array $parts): array
    {
        $writes = array($this->write('theme_scaffold', 'style.css', "/*\nTheme Name: Blocks Engine Site\nText Domain: blocks-engine-site\n*/\n"), $this->write('theme_scaffold', 'theme.json', "{\"version\":3,\"settings\":{},\"styles\":{}}\n"));
        if ( $this->needsBootstrap($assets) ) $writes[] = $this->write('theme_bootstrap', 'functions.php', $this->bootstrap($assets));
        foreach ( $templates as $template ) $writes[] = $this->write('theme_template', $template['target_path'], $template['canonical_block_markup']);
        foreach ( $parts as $part ) $writes[] = $this->write('theme_template_part', 'parts/' . $part['slug'] . '.html', $part['canonical_block_markup']);
        return $writes;
    }

    /** @param array<int,array<string,mixed>> $assets */
    private function needsBootstrap(array $assets): bool { foreach ($assets as $asset) if (in_array($asset['kind'], array('css', 'js'), true)) return true; return false; }
    /** @param array<int,array<string,mixed>> $assets */
    private function bootstrap(array $assets): string
    {
        $lines = array("<?php", "add_action( 'wp_enqueue_scripts', static function (): void {");
        foreach ($assets as $asset) {
            $handle = 'blocks-engine-' . substr(hash('sha256', $asset['target_path']), 0, 12);
            if ('css' === $asset['kind']) $lines[] = "    wp_enqueue_style( '{$handle}', get_theme_file_uri( '{$asset['target_path']}' ), array(), null );";
            if ('js' === $asset['kind']) $lines[] = "    wp_enqueue_script( '{$handle}', get_theme_file_uri( '{$asset['target_path']}' ), array(), null, true );";
        }
        $lines[] = "} );";
        return implode("\n", $lines) . "\n";
    }
    /** @return array<string,mixed> */
    private function write(string $kind, string $target, string $content): array { return array('kind' => $kind, 'source_path' => 'wordpress-site-plan/' . $target, 'target_path' => $target, 'payload' => array('encoding' => 'utf8', 'data' => $content)); }
    /** @param array<int,array<string,string>> $tokens */
    private function tokenize(string $content, array $tokens): string
    {
        foreach ($tokens as $reference) {
            $source = preg_replace('/^assets\//', '', $reference['target_path']);
            foreach (array($reference['target_path'], '../' . $source, './' . $source, $source) as $candidate) {
                // Do not rewrite an asset-looking substring inside another URL or path.
                $content = preg_replace('~(?<![A-Za-z0-9_.\/-])' . preg_quote($candidate, '~') . '(?![A-Za-z0-9_.\/-])~', self::TOKEN_PREFIX . $reference['token'] . '}}', $content) ?? $content;
            }
        }
        return $content;
    }
    /** @param array<string,string> $tokens */
    private static function assertDocument(mixed $document, string $kind, bool $part, array $tokens): void { if (!is_array($document) || !self::safePath($document['source_path'] ?? null) || !is_string($document['slug'] ?? null) || !is_string($document['title'] ?? null) || !is_string($document['post_type'] ?? null) || !is_string($document['parent_source_path'] ?? null) || !is_bool($document['entrypoint'] ?? null) || !is_string($document['canonical_block_markup'] ?? null) || '' === trim($document['canonical_block_markup']) || !is_array($document['metadata'] ?? null) || !is_array($document['provenance'] ?? null) || !is_string($document['reconciliation_identity'] ?? null) || ($part && (!is_string($document['area'] ?? null) || '' === $document['area'])) || (!$part && null !== ($document['area'] ?? null))) throw new InvalidArgumentException("WordPress site plan {$kind} is structurally invalid."); self::assertTokens($document['canonical_block_markup'], $tokens); }
    /** @param array<string,string> $tokens */
    private static function assertWrite(mixed $write, array $tokens): void { if (!is_array($write) || !is_string($write['kind'] ?? null) || !self::safePath($write['source_path'] ?? null) || !self::safePath($write['target_path'] ?? null) || !is_array($write['payload'] ?? null) || !in_array($write['payload']['encoding'] ?? null, array('utf8','base64'), true) || !is_string($write['payload']['data'] ?? null)) throw new InvalidArgumentException('WordPress site plan write is structurally invalid.'); if ('base64' === $write['payload']['encoding'] && false === base64_decode($write['payload']['data'], true)) throw new InvalidArgumentException('WordPress site plan write has invalid base64 payload.'); if ('utf8' === $write['payload']['encoding']) self::assertTokens($write['payload']['data'], $tokens); }
    /** @param array<string,string> $tokens */
    private static function assertTokens(string $content, array $tokens): void { if (preg_match_all('/\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $content, $matches)) foreach ($matches[1] as $token) if (!isset($tokens[$token])) throw new InvalidArgumentException('WordPress site plan contains an undeclared reference token.'); }
    /** @param array<string,bool> $values */
    private static function unique(array &$values, string $value, string $kind): void { if (isset($values[$value])) throw new InvalidArgumentException("WordPress site plan has colliding {$kind}s."); $values[$value] = true; }
    /** @param array<string,mixed> $source */
    private static function assertSource(array $source): void { if ('blocks-engine/php-transformer/compiled-site/v1' !== ($source['schema'] ?? null) || !is_string($source['source_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $source['source_hash']) || !is_string($source['entry_path'] ?? null) || !is_array($source['provenance'] ?? null)) throw new InvalidArgumentException('WordPress site plan source identity is invalid.'); }
    /** @param array<int,mixed> $rows @param array<int,string> $fields @param array<int,string> $optional */
    private static function assertRows(array $rows, string $kind, array $fields, array $optional = array()): void { foreach ($rows as $row) { if (!is_array($row)) throw new InvalidArgumentException("WordPress site plan {$kind} must be an array."); foreach ($fields as $field) if (!array_key_exists($field, $row) || (!is_string($row[$field]) && !is_int($row[$field]))) throw new InvalidArgumentException("WordPress site plan {$kind} lacks {$field}."); foreach ($optional as $field) if (array_key_exists($field, $row) && !is_string($row[$field])) throw new InvalidArgumentException("WordPress site plan {$kind} has invalid {$field}."); } }
    /** @param array<string,mixed> $data */
    private static function value(array $data, string $key, string $default = ''): string { return is_string($data[$key] ?? null) ? $data[$key] : $default; }
    private static function safePath(mixed $path): bool { if (!is_string($path) || '' === $path || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) return false; foreach (explode('/', str_replace('\\', '/', $path)) as $segment) if ('' === $segment || '.' === $segment || '..' === $segment) return false; return true; }
}
