<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * Recovers media references that name a local file the artifact never packaged.
 *
 * A captured site routinely references an image its capture missed. That is a
 * recoverable quality defect, not a reason to reject every page: the document,
 * its alternative text and its authored layout are all still materializable.
 * This recovery declares one placeholder image asset, points the missing media
 * references at its token so the plan stays self-contained on the declared-token
 * path, and reports each distinct miss as a warning. Strict rejection remains
 * available as an explicit opt-in.
 *
 * Navigation links are not media: `a`/`area` href and `form` action keep their
 * own resolve-or-neutralize recovery. Percent-encoded separators stay
 * unrecovered so source intake protections keep rejecting them.
 */
final class MissingMediaRecovery
{
    public const DIAGNOSTIC_CODE = 'wordpress_site_plan_missing_local_media';
    public const SOURCE_PATH = '_missing-media/placeholder.svg';
    public const TARGET_PATH = 'assets/' . self::SOURCE_PATH;
    private const MAX_DIAGNOSTICS = 50;

    /** Elements whose reference names a rendered media payload. */
    private const MEDIA_ELEMENTS = array('audio', 'image', 'img', 'input', 'source', 'track', 'use', 'video');

    /** Attributes that name media outside element context: CSS urls and block JSON media fields. */
    private const MEDIA_ATTRIBUTES = array('css:url', 'json:poster', 'json:src', 'json:srcset', 'json:url', 'poster', 'src', 'srcset');

    /**
     * An inert, self-describing stand-in. It declares no external reference, so
     * it survives the same browser-reference validation as any authored asset.
     */
    private const PLACEHOLDER_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 90" width="160" height="90" role="img" aria-label="Missing image" preserveAspectRatio="xMidYMid slice"><rect width="160" height="90" fill="#e9e9e9"/><path d="M0 74 46 36l26 20 24-18 64 36z" fill="#c8c8c8"/><circle cx="120" cy="26" r="11" fill="#c8c8c8"/><rect x="0.5" y="0.5" width="159" height="89" fill="none" stroke="#bcbcbc"/></svg>';

    /** @var array<string,array<string,mixed>> */
    private array $diagnostics = array();

    private int $omitted = 0;

    private bool $recovered = false;

    /** @var array<string,true> */
    private array $declaredTargets;

    /** @param array<int,string> $declaredTargets */
    public function __construct(private readonly bool $strict = false, array $declaredTargets = array())
    {
        $this->declaredTargets = array_fill_keys($declaredTargets, true);
    }

    public static function placeholderToken(): string
    {
        return 'asset-' . substr(hash('sha256', self::TARGET_PATH), 0, 16);
    }

    public static function placeholderReference(): string
    {
        return WordPressSitePlan::TOKEN_PREFIX . self::placeholderToken() . '}}';
    }

    /**
     * Resolves one reference the declared-token map could not satisfy. Returns
     * null whenever the reference is not recoverable missing local media, which
     * leaves the original value for the caller and its existing validation.
     */
    public function recover(string $reference, string $origin, string $element, string $attribute): ?string
    {
        if ($this->strict || isset($this->declaredTargets[self::TARGET_PATH]) || !self::isMediaContext($element, $attribute)) {
            return null;
        }
        $url = trim(html_entity_decode(str_ireplace('\\u0026', '&', $reference), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Canonicalization is idempotent: an already declared token is resolved,
        // not missing.
        if ('' === $url || str_starts_with($url, WordPressSitePlan::TOKEN_PREFIX) || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $url)) {
            return null;
        }
        preg_match('/^([^?#]*)/s', $url, $parts);
        $path = trim($parts[1] ?? '');
        // Encoded separators are an intake protection, not a missing file.
        if ('' === $path || preg_match('~%2f|%5c|%2e~i', $path)) {
            return null;
        }
        $this->record($path, $origin, $element, $attribute);
        $this->recovered = true;
        return self::placeholderReference();
    }

    /**
     * The compiled-asset row backing recovered references, in the shape the plan
     * asset normalizer consumes. Empty until a reference is actually recovered.
     *
     * @return array<int,array<string,mixed>>
     */
    public function assets(): array
    {
        if (!$this->recovered) {
            return array();
        }
        return array(array(
            'path' => self::SOURCE_PATH,
            'target_path' => self::SOURCE_PATH,
            'source' => 'generated',
            'source_role' => 'missing_media_placeholder',
            'kind' => 'asset',
            'role' => 'image',
            'mime_type' => 'image/svg+xml',
            'bytes' => strlen(self::PLACEHOLDER_SVG),
            'hash' => hash('sha256', self::PLACEHOLDER_SVG),
            'binary' => false,
            'content' => self::PLACEHOLDER_SVG,
        ));
    }

    /** @return array<int,array<string,mixed>> */
    public function diagnostics(): array
    {
        $diagnostics = array_values($this->diagnostics);
        if ($this->omitted > 0) {
            $diagnostics[] = array(
                'code' => self::DIAGNOSTIC_CODE,
                'severity' => 'warning',
                'message' => sprintf('%d more missing local media references were replaced with the placeholder image; omitted from this diagnostic list.', $this->omitted),
                'reason' => 'truncated',
                'omitted_count' => $this->omitted,
            );
        }
        return $diagnostics;
    }

    private function record(string $path, string $origin, string $element, string $attribute): void
    {
        $key = $origin . "\0" . $path;
        if (isset($this->diagnostics[$key])) {
            return;
        }
        if (count($this->diagnostics) >= self::MAX_DIAGNOSTICS) {
            $this->omitted++;
            return;
        }
        $this->diagnostics[$key] = array_filter(array(
            'code' => self::DIAGNOSTIC_CODE,
            'severity' => 'warning',
            'message' => substr(sprintf('Replaced %s with a placeholder image because the artifact does not contain that file.', $path), 0, 256),
            'source_path' => substr($origin, 0, 256),
            'value' => substr($path, 0, 256),
            'element' => '' === $element ? null : $element,
            'attribute' => substr($attribute, 0, 64),
            'resolution' => 'placeholder_media',
            'reason_code' => 'missing_local_media',
        ), static fn(mixed $field): bool => null !== $field);
    }

    private static function isMediaContext(string $element, string $attribute): bool
    {
        $attribute = strtolower($attribute);
        if (in_array($element, self::MEDIA_ELEMENTS, true)) {
            return in_array($attribute, array('href', 'poster', 'src', 'srcset', 'xlink:href'), true);
        }
        // An escaped-JSON or stylesheet reference carries no element context.
        return '' === $element && in_array($attribute, self::MEDIA_ATTRIBUTES, true);
    }
}
