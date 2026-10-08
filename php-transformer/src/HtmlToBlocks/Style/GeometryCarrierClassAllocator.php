<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** @internal Deterministic, collision-safe carrier class allocation. */
final class GeometryCarrierClassAllocator
{
    /**
     * Hex digits kept from the signature digest. The class is visible to site
     * owners in the block's Additional CSS classes field; 64 bits keeps it
     * short while collisions within a site stay negligible, and any collision
     * is still resolved below.
     */
    public const DIGEST_LENGTH = 16;

    /** @var callable(string): string */
    private $digest;

    /** @var array<string, string> */
    private array $classBySignature = array();

    /** @var array<string, string> */
    private array $signatureByClass = array();

    /** @param callable(string): string|null $digest */
    public function __construct(?callable $digest = null)
    {
        $this->digest = $digest ?? static fn (string $value): string => substr(hash('sha256', $value), 0, self::DIGEST_LENGTH);
    }

    public function allocate(string $signature): string
    {
        if (isset($this->classBySignature[$signature])) {
            return $this->classBySignature[$signature];
        }

        $base = 'be-inline-geometry-' . ($this->digest)($signature);
        $className = $base;
        $attempt = 0;
        while (isset($this->signatureByClass[$className]) && $this->signatureByClass[$className] !== $signature) {
            ++$attempt;
            $className = $base . '-' . substr(hash('sha256', $signature . ':' . $attempt), 0, self::DIGEST_LENGTH);
        }

        $this->classBySignature[$signature] = $className;
        $this->signatureByClass[$className] = $signature;
        return $className;
    }
}
