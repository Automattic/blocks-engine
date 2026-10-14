<?php
declare(strict_types=1);

/**
 * Shared mechanics of the capture-evidence equivalence gate.
 *
 * The corpus holds every Blocks Engine entry-point call from the test suite
 * whose input carried the retired producer-named capture vocabulary, recorded
 * verbatim on origin/trunk before the rename. The baseline tool replays it
 * through a pre-rename checkout and stores a digest of each output after the
 * documented rename. The gate replays the renamed input through the current
 * checkout and requires the same digest. Both sides use this file, so the
 * rename rule and the output normalization cannot drift between them.
 *
 * Retired spellings are built from fragments so this file never spells them,
 * keeping the source guard's vocabulary out of every runtime surface.
 */
final class CaptureEvidenceEquivalence
{
    public const CORPUS = __DIR__ . '/../../fixtures/capture-evidence-equivalence/corpus.json.gz';
    public const EXPECTED = __DIR__ . '/../../fixtures/capture-evidence-equivalence/expected.json';
    public const SCHEMA = 'blocks-engine/php-transformer/capture-evidence-equivalence/v1';

    /**
     * The mechanical rule from the capture-evidence contract
     * (docs/contracts/capture-evidence.md), applied to raw text.
     */
    public static function rename(string $text): string
    {
        $short = 'd' . 'la';
        $long = 'data-' . 'liberation';
        $text = str_replace(array('data-' . $short . '-', $long . '-'), 'data-blocks-engine-capture-', $text);
        $text = str_replace($long, 'blocks-engine-capture', $text);

        // A bare token starts the text, follows a non-name character, or
        // follows the `--` of a variant id suffix (also JSON-escaped).
        return preg_replace('/(?:(?<![A-Za-z0-9_-])|(?<=--)|(?<=\\\\u002d))' . $short . '-/', 'data-blocks-engine-capture-', $text) ?? $text;
    }

    /** Whether text still carries a retired producer-named token. */
    public static function carriesRetiredVocabulary(string $text): bool
    {
        return $text !== self::rename($text);
    }

    /**
     * Apply the rename to a value the way a producer emitting the published
     * names would write it: every string and array key, the decoded bytes of
     * `content_base64` payloads and embedded JSON documents, and each declared
     * `htmlBytes` keeps its relation to its renamed `html`.
     */
    public static function renameValue(mixed $value): mixed
    {
        if ( is_string($value) ) {
            return self::renameText($value);
        }
        if ( ! is_array($value) ) {
            return $value;
        }
        $renamed = array();
        foreach ( $value as $key => $item ) {
            if ( 'content_base64' === $key && is_string($item) && false !== base64_decode($item, true) ) {
                $renamed[$key] = base64_encode(self::renameText((string) base64_decode($item, true)));
                continue;
            }
            $renamed[is_string($key) ? self::rename($key) : $key] = self::renameValue($item);
        }
        if ( is_string($value['html'] ?? null) && is_int($value['htmlBytes'] ?? null) ) {
            $renamed['htmlBytes'] = $value['htmlBytes'] + strlen($renamed['html']) - strlen($value['html']);
        }

        return $renamed;
    }

    /** Rename text, re-deriving declared byte counts inside an embedded JSON document. */
    private static function renameText(string $text): string
    {
        if ( str_contains($text, '"htmlBytes"') ) {
            $document = json_decode($text, true);
            if ( is_array($document) ) {
                return (string) json_encode(self::renameValue($document));
            }
        }

        return self::rename($text);
    }

    /** @return list<array{id:string,entry:string,ctor:array<string,mixed>,args:list<mixed>}> */
    public static function corpus(): array
    {
        $raw = gzdecode((string) file_get_contents(self::CORPUS));
        $corpus = is_string($raw) ? json_decode($raw, true) : null;
        if ( ! is_array($corpus) || self::SCHEMA !== ($corpus['schema'] ?? null) || ! is_array($corpus['cases'] ?? null) ) {
            throw new RuntimeException('Capture-evidence equivalence corpus is unreadable.');
        }

        return $corpus['cases'];
    }

    /**
     * Run one recorded entry-point call and return its observable output: the
     * compile result and its WordPress site plan, the projected files, or the
     * thrown exception.
     *
     * @param array{entry:string,ctor:array<string,mixed>,args:list<mixed>} $case
     */
    public static function replay(array $case): array
    {
        $ns = 'Automattic\\BlocksEngine\\PhpTransformer\\';
        $args = $case['args'];
        try {
            switch ( $case['entry'] ) {
                case 'HtmlTransformer::transform':
                    $class = $ns . 'HtmlToBlocks\\HtmlTransformer';
                    return self::resultOutput((new $class())->transform(...$args));
                case 'ArtifactCompiler::compile':
                case 'ArtifactCompiler::compileFragment':
                    $class = $ns . 'ArtifactCompiler\\ArtifactCompiler';
                    $compiler = new $class((bool) $case['ctor']['cacheHtmlAnalysis'], (int) $case['ctor']['stylesheetSelectorBudget']);
                    $method = substr($case['entry'], strlen('ArtifactCompiler::'));
                    return self::resultOutput($compiler->{$method}(...$args));
                case 'ArtifactNormalizer::normalize':
                case 'CapturedSelectableSetProjector::project':
                case 'CapturedCollectionProjector::project':
                case 'CapturedDialogProjector::project':
                case 'CapturedCollectionFilterProjector::project':
                case 'CapturedChoiceGroupProjector::project':
                    [$short, $method] = explode('::', $case['entry']);
                    $class = $ns . 'ArtifactCompiler\\' . $short;
                    return array('value' => (new $class())->{$method}(...$args));
            }
        } catch ( Throwable $error ) {
            return array('exception' => get_class($error), 'message' => $error->getMessage());
        }

        throw new RuntimeException('Unknown recorded entry point: ' . $case['entry']);
    }

    /**
     * Byte-exact digest of an output. Only the checkout path and wall-clock
     * timings are neutralized; every other byte is compared.
     */
    public static function digest(array $output, string $transformerRoot): string
    {
        return hash('sha256', serialize(self::normalized($output, $transformerRoot)));
    }

    /**
     * The compared form of an output; see digest(). With $lengthNeutral, the
     * values that a longer name changes by construction are also masked
     * (hex digests and anything derived from them, integer byte counts and
     * offsets, and the cut point of length-bounded excerpts). That form is
     * for the cross-name semantic report only, never for the gate.
     */
    public static function normalized(array $output, string $transformerRoot, bool $lengthNeutral = false): array
    {
        $roots = array_unique(array_filter(array(rtrim($transformerRoot, '/'), (string) realpath($transformerRoot))));

        return self::rootless($output, array_values($roots), $lengthNeutral);
    }

    /**
     * One spelling for HTML that libxml2 versions serialize differently.
     *
     * DOMDocument::saveHTML() output is not stable across libxml2 releases:
     * 2.14+ writes a quote-bearing attribute as "&quot;" where 2.9 writes it
     * single-quoted, writes a non-ASCII character literally where 2.9 writes a
     * named entity, drops the legacy closing tag of HTML5 void elements, closes
     * list items 2.9 left open, and keeps a stray `<?xml ...>` instruction as a
     * bogus comment. Each pair parses to the same document, so the gate
     * compares this form: attributes double-quoted with only `&` and `"`
     * escaped, text with only `&`, `<` and `>` escaped, no void-element or
     * `</li>` closers, and the instruction spelling. Non-markup text passes
     * through unchanged.
     */
    public static function canonicalHtml(string $text): string
    {
        if ( 1 !== preg_match('/<[a-zA-Z!?\/]/', $text) ) {
            return $text;
        }
        $text = preg_replace('/<!--([?\/]xml\b[^>]*?)-->/i', '<?$1>', $text) ?? $text;
        $text = preg_replace('/<\?\?xml\b/i', '<?xml', $text) ?? $text;
        $parts = preg_split('/(<!--.*?-->|<[?!][^>]*>|<\/?[a-zA-Z][a-zA-Z0-9:-]*(?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*))?)*\s*\/?>)/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ( false === $parts ) {
            return $text;
        }
        $out = '';
        foreach ( $parts as $index => $part ) {
            if ( 0 === $index % 2 ) {
                $out .= htmlspecialchars(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            if ( 1 === preg_match('/^<\/(?:area|base|br|col|embed|hr|img|input|link|meta|source|track|wbr|li)\s*>$/i', $part) ) {
                continue;
            }
            if ( 1 !== preg_match('/^<([a-zA-Z][a-zA-Z0-9:-]*)(.*?)(\/?)>$/s', $part, $tag) ) {
                $out .= $part;
                continue;
            }
            preg_match_all('/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]*)))?/', $tag[2], $attributes, PREG_SET_ORDER);
            $out .= '<' . $tag[1];
            foreach ( $attributes as $attribute ) {
                $out .= ' ' . $attribute[1];
                if ( isset($attribute[2]) ) {
                    $raw = $attribute[2] . ($attribute[3] ?? '') . ($attribute[4] ?? '');
                    $out .= '="' . htmlspecialchars(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8') . '"';
                }
            }
            $out .= $tag[3] . '>';
        }

        return $out;
    }

    private static function resultOutput(object $result): array
    {
        $output = array('result' => $result->toArray());
        $plan = 'Automattic\\BlocksEngine\\PhpTransformer\\WordPressSitePlan\\WordPressSitePlan';
        try {
            $output['site_plan'] = (new $plan())->fromResult($result);
        } catch ( Throwable $error ) {
            $output['site_plan'] = array('exception' => get_class($error), 'message' => $error->getMessage());
        }

        return $output;
    }

    /** @param list<string> $roots */
    private static function rootless(mixed $value, array $roots, bool $lengthNeutral): mixed
    {
        if ( is_string($value) ) {
            foreach ( $roots as $root ) {
                $value = str_replace(array($root, dirname($root)), array('<transformer-root>', '<repository-root>'), $value);
            }
            $value = self::canonicalHtml($value);
            if ( $lengthNeutral ) {
                $value = preg_replace(array('/(?<![0-9a-f])[0-9a-f]{12,}(?![0-9a-f])/', '/^(.*)\.\.\.$/s'), array('<hex>', '<excerpt>'), $value) ?? $value;
            }
            return $value;
        }
        if ( is_array($value) ) {
            $content = is_string($value['content'] ?? null) ? self::canonicalHtml($value['content']) : null;
            foreach ( $value as $key => $item ) {
                if ( 'bytes' === $key && is_int($item) && null !== $content ) {
                    // A payload's byte count follows its serialization.
                    $value[$key] = strlen($content);
                } elseif ( is_string($key) && str_ends_with($key, '_duration_ms') ) {
                    $value[$key] = 0;
                } elseif ( 'engine_version' === $key && is_string($item) ) {
                    $value[$key] = '<engine-version>';
                } elseif ( $lengthNeutral && is_string($key) && ( is_int($item) || is_bool($item) ) && 1 === preg_match('/bytes|length|size|offset|truncated/i', $key) ) {
                    $value[$key] = '<length>';
                } else {
                    $value[$key] = self::rootless($item, $roots, $lengthNeutral);
                }
            }
        }

        return $value;
    }
}
