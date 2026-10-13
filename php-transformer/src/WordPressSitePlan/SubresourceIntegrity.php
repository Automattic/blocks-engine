<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/**
 * Keeps declared Subresource Integrity metadata true for the bytes the theme
 * serves. A source digest describes the source bytes; once the plan rewrites a
 * local asset (asset URLs, scoped selectors), that digest makes the browser
 * block the whole stylesheet or script.
 */
final class SubresourceIntegrity
{
    private const ALGORITHMS = array('sha256', 'sha384', 'sha512');

    /** @var array<string,array<string,mixed>> */
    private array $writesByTarget;
    /** @var array<string,string> */
    private array $targetsByToken = array();
    /** @var array<string,string|false|null> */
    private array $served = array();

    /**
     * @param array<int,array<string,mixed>> $writes Final asset writes.
     * @param array<int,array<string,mixed>> $tokens Declared asset tokens.
     */
    public function __construct(array $writes, private readonly array $tokens)
    {
        $this->writesByTarget = array_column($writes, null, 'target_path');
        foreach ($tokens as $token) if (is_string($token['token'] ?? null) && is_string($token['target_path'] ?? null)) $this->targetsByToken[$token['token']] = $token['target_path'];
    }

    /**
     * @param array<int,array<string,mixed>> $documents
     * @return array<int,array<string,mixed>>
     */
    public function documents(array $documents): array
    {
        foreach ($documents as $index => $document) {
            foreach (array('links', 'scripts') as $kind) foreach ($document['document_metadata'][$kind] ?? array() as $row => $declaration) {
                if (!is_array($declaration) || !is_string($declaration['integrity'] ?? null)) continue;
                $integrity = $this->forReference($declaration['integrity'], $declaration['asset_reference'] ?? null);
                if (null === $integrity) unset($documents[$index]['document_metadata'][$kind][$row]['integrity']);
                else $documents[$index]['document_metadata'][$kind][$row]['integrity'] = $integrity;
            }
            if (is_array($document['document_metadata']['head'] ?? null)) $documents[$index]['document_metadata']['head'] = $this->head($document['document_metadata']['head']);
        }
        return $documents;
    }

    /**
     * Integrity applies to fetched links and scripts; an inline head script carries its own body.
     * @param array<string,mixed> $head
     * @return array<string,mixed>
     */
    public function head(array $head): array
    {
        foreach ($head['elements'] ?? array() as $index => $element) {
            if (!is_string($element['attributes']['integrity'] ?? null) || !('link' === ($element['tag'] ?? null) || ('script' === ($element['tag'] ?? null) && false === ($element['inline'] ?? null)))) continue;
            $integrity = $this->forReference($element['attributes']['integrity'], $element['asset_reference'] ?? null);
            if (null === $integrity) unset($head['elements'][$index]['attributes']['integrity']);
            else $head['elements'][$index]['attributes']['integrity'] = $integrity;
        }
        return $head;
    }

    /**
     * Recomputes each source algorithm's digest over the served bytes. When the
     * bytes are not known yet, the metadata is dropped instead of kept stale.
     * Tokens with algorithms browsers do not check stay as written.
     */
    public static function value(string $integrity, ?string $bytes): ?string
    {
        $values = array(); $seen = array(); $checked = false;
        foreach (preg_split('/[\t\n\f\r ]+/', trim($integrity), -1, PREG_SPLIT_NO_EMPTY) ?: array() as $token) {
            $algorithm = strtolower(explode('-', $token, 2)[0]);
            if (!in_array($algorithm, self::ALGORITHMS, true)) { $values[] = $token; continue; }
            $checked = true;
            if (null === $bytes || isset($seen[$algorithm])) continue;
            $seen[$algorithm] = true;
            $values[] = $algorithm . '-' . base64_encode(hash($algorithm, $bytes, true));
        }
        if (!$checked) return $integrity;
        return null === $bytes ? null : implode(' ', $values);
    }

    private function forReference(string $integrity, mixed $reference): ?string
    {
        if (!is_string($reference) || !preg_match('/^' . preg_quote(WordPressSitePlan::TOKEN_PREFIX, '/') . '(asset-[a-f0-9]{16})\}\}/', $reference, $match)) return $integrity;
        if (!array_key_exists($match[1], $this->served)) $this->served[$match[1]] = $this->servedBytes($match[1]);
        $bytes = $this->served[$match[1]];
        return false === $bytes ? $integrity : self::value($integrity, $bytes);
    }

    /**
     * Served bytes for a token. Null when they depend on the destination theme
     * URI; false when this plan has no inline payload for it (a reference write
     * publishes its referenced source bytes unchanged, so its digest stays).
     */
    private function servedBytes(string $token): string|false|null
    {
        $target = $this->targetsByToken[$token] ?? null;
        $payload = null === $target ? null : ($this->writesByTarget[$target]['payload'] ?? null);
        if (!is_array($payload) || !is_string($payload['data'] ?? null)) return false;
        if ('base64' === ($payload['encoding'] ?? null)) { $bytes = base64_decode($payload['data'], true); return false === $bytes ? null : $bytes; }
        if ('utf8' !== ($payload['encoding'] ?? null)) return false;
        return WordPressSitePlanResolver::destinationIndependentPayload($payload['data'], $this->tokens, $target);
    }
}
