<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\Support\StyleTagScanner;

/** Composes source stylesheet payloads into cached presentation and selector analyses. */
final class StylesheetAnalysisComposer
{
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly HtmlTransformerAnalysisCache $analysisCache
    ) {}

    public function combinedAuthorStylesheet(string $html, string $staticCss): string
    {
        $cssParts = array();
        foreach ( StyleTagScanner::scan($html) as $style ) {
            $styleBlock = trim(html_entity_decode($style['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ( '' !== $styleBlock ) {
                $cssParts[] = $styleBlock;
            }
        }
        $staticCss = trim($staticCss);
        if ( '' !== $staticCss ) {
            $cssParts[] = $staticCss;
        }
        return trim(implode("\n\n", $cssParts));
    }

    /** @param array<string, mixed> $options @return list<string> */
    public function stylesheetPayloads(string $html, string $staticCss, array $options): array
    {
        $staticPayloads = $this->staticStylesheetPayloads($staticCss, $options);
        $inlinePayloads = $this->inlineStylesheetPayloads($html);
        $payloads = array_merge($staticPayloads, $inlinePayloads);
        if ( ! $this->hasSafeStylesheetBoundaries($payloads) ) {
            // Preserve the legacy parser's recovery across a concatenated stream.
            $combined = trim($staticCss . ('' === trim($staticCss) || array() === $inlinePayloads ? '' : "\n") . implode("\n", $inlinePayloads));
            return '' === $combined ? array() : array($combined);
        }

        return array_values(array_filter(array_map('trim', $payloads), static fn (string $payload): bool => '' !== $payload));
    }

    /** @return list<array{content: string, source_path: string, source_hash: string}> */
    public function authorStylesheetPayloads(string $html, string $staticCss, AuthorStyleAnalysis $authorStyles): array
    {
        if ( array() !== $authorStyles->stylesheetAssets() ) {
            $payloads = array_values(array_filter($authorStyles->stylesheetAssets(), static fn (array $asset): bool => '' !== trim($asset['content'])));
            if ( $this->hasSafeStylesheetBoundaries(array_column($payloads, 'content')) ) {
                return array_map(static fn (array $asset): array => array('content' => $asset['content'], 'source_path' => $asset['source_path'], 'source_hash' => $asset['source_hash']), $payloads);
            }
            $content = implode("\n\n", array_column($payloads, 'content'));
            return array(array('content' => $content, 'source_path' => 'combined-stylesheets', 'source_hash' => hash('sha256', $content)));
        }

        $payloads = array();
        // This order intentionally differs from presentation analysis and matches
        // combinedAuthorStylesheet(), which emits inline CSS first.
        foreach ( array_merge($this->inlineStylesheetPayloads($html), array($staticCss)) as $payload ) {
            $payload = trim(html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ( '' !== $payload ) {
                $payloads[] = $payload;
            }
        }

        if ( $this->hasSafeStylesheetBoundaries($payloads) ) {
            return array_map(static fn (string $content): array => array('content' => $content, 'source_path' => 'inline-style', 'source_hash' => hash('sha256', $content)), $payloads);
        }
        $content = implode("\n\n", $payloads);
        return array(array('content' => $content, 'source_path' => 'inline-style', 'source_hash' => hash('sha256', $content)));
    }

    /** @param list<string> $payloads @return array{static: array, conditional: array, navigation_state: array, image_shape: array, pseudo: array, custom_properties: array} */
    public function composedStyleAnalysis(array $payloads): array
    {
        $composed = array('static' => array(), 'conditional' => array(), 'navigation_state' => array(), 'image_shape' => array(), 'pseudo' => array(), 'custom_properties' => array('root' => array(), 'fallback' => array()));
        foreach ( $payloads as $payload ) {
            $key = hash('sha256', $payload);
            $analysis = $this->analysisCache->style($key);
            if ( null === $analysis ) {
                ++$this->analysisCache->styleBuilds;
                $ruleCss = trim($payload);
                $ruleCss = preg_replace('@/\*.*?\*/@s', '', $ruleCss) ?? $ruleCss;
                $style = $this->styleResolver->stylesheetAnalysis($ruleCss);
                $analysis = array(
                    'static' => $style['static'],
                    'conditional' => $style['conditional'],
                    'navigation_state' => $style['navigation_state'],
                    'image_shape' => $style['image_shape'],
                    'pseudo' => $style['pseudo'],
                    'custom_properties' => $this->cssCustomPropertyAnalysis($payload),
                );
                $this->analysisCache->rememberStyle($key, $analysis);
            } else {
                ++$this->analysisCache->styleHits;
            }
            foreach ( array('static', 'conditional', 'navigation_state', 'pseudo') as $part ) {
                $composed[$part] = array_merge($composed[$part], $analysis[$part]);
            }
            foreach ( $analysis['image_shape'] as $rule ) {
                $rule['order'] = count($composed['image_shape']);
                $composed['image_shape'][] = $rule;
            }
            $composed['custom_properties']['root'] = array_merge($composed['custom_properties']['root'], $analysis['custom_properties']['root']);
            $composed['custom_properties']['fallback'] = array_merge($composed['custom_properties']['fallback'], $analysis['custom_properties']['fallback']);
        }

        $composed['custom_properties'] = array() !== $composed['custom_properties']['root']
            ? $composed['custom_properties']['root']
            : $composed['custom_properties']['fallback'];

        return $composed;
    }

    /** @param list<array{content: string, source_path: string, source_hash: string}> $payloads @return array{source_tags: array<string, bool>, rules: list<array<string, mixed>>} */
    public function composedAuthorSelectorAnalysis(array $payloads): array
    {
        $composed = array('source_tags' => array(), 'rules' => array());
        foreach ( $payloads as $payload ) {
            $key = hash('sha256', $payload['content']);
            $analysis = $this->analysisCache->authorSelectors($key);
            if ( null === $analysis ) {
                ++$this->analysisCache->authorSelectorBuilds;
                $analysis = $this->authorSelectorAnalysis($payload['content']);
                ++$this->analysisCache->authorStyleRuleBuilds;
                $this->analysisCache->rememberAuthorSelectors($key, $analysis);
            } else {
                ++$this->analysisCache->authorSelectorHits;
            }
            $composed['source_tags'] += $analysis['source_tags'];
            foreach ( $analysis['rules'] as $rule ) {
                $rule['order'] = count($composed['rules']);
                $rule['source_path'] = $payload['source_path'];
                $rule['source_hash'] = $payload['source_hash'];
                $composed['rules'][] = $rule;
            }
        }

        return $composed;
    }

    /** @param array<string, mixed> $options @return list<array{path: string, source_path: string, content: string, source_hash: string, media: string}> */
    public function authorStylesheetAssetsFromOptions(array $options): array
    {
        if ( ! is_array($options['author_stylesheet_assets'] ?? null) ) {
            return array();
        }
        $assets = array();
        foreach ( $options['author_stylesheet_assets'] as $asset ) {
            if ( ! is_array($asset) || ! is_string($asset['path'] ?? null) || '' === $asset['path'] || ! is_string($asset['content'] ?? null) ) {
                continue;
            }
            $assets[] = array( 'path' => $asset['path'], 'source_path' => is_string($asset['source_path'] ?? null) ? $asset['source_path'] : $asset['path'], 'content' => $asset['content'], 'source_hash' => is_string($asset['source_hash'] ?? null) ? $asset['source_hash'] : hash('sha256', $asset['content']), 'media' => is_string($asset['media'] ?? null) ? $asset['media'] : '' );
        }
        return $assets;
    }

    /** @param array<string, mixed> $options @return list<string> */
    private function staticStylesheetPayloads(string $staticCss, array $options): array
    {
        if ( ! is_array($options['stylesheet_payloads'] ?? null) ) {
            return array($staticCss);
        }
        $payloads = array();
        foreach ( $options['stylesheet_payloads'] as $payload ) {
            if ( is_array($payload) && is_string($payload['content'] ?? null) ) {
                $payloads[] = $payload['content'];
            }
        }

        return array() === $payloads ? array($staticCss) : $payloads;
    }

    /** @return list<string> */
    private function inlineStylesheetPayloads(string $html): array
    {
        return array_map(static fn (array $style): string => trim($style['content']), StyleTagScanner::scan($html));
    }

    /** @param list<string> $payloads */
    private function hasSafeStylesheetBoundaries(array $payloads): bool
    {
        foreach ( $payloads as $payload ) {
            $depth = 0;
            $quote = '';
            $comment = false;
            for ( $index = 0, $length = strlen($payload); $index < $length; ++$index ) {
                $character = $payload[$index];
                $next = $index + 1 < $length ? $payload[$index + 1] : '';
                if ( $comment ) {
                    if ( '*' === $character && '/' === $next ) {
                        $comment = false;
                        ++$index;
                    }
                    continue;
                }
                if ( '' !== $quote ) {
                    if ( '\\' === $character ) {
                        ++$index;
                    } elseif ( $quote === $character ) {
                        $quote = '';
                    }
                    continue;
                }
                if ( '/' === $character && '*' === $next ) {
                    $comment = true;
                    ++$index;
                } elseif ( '"' === $character || "'" === $character ) {
                    $quote = $character;
                } elseif ( '{' === $character ) {
                    ++$depth;
                } elseif ( '}' === $character ) {
                    --$depth;
                    if ( $depth < 0 ) {
                        return false;
                    }
                }
            }
            if ( $comment || '' !== $quote || 0 !== $depth ) {
                return false;
            }
        }

        return true;
    }

    /** @return array{root: array<string, string>, fallback: array<string, string>} */
    private function cssCustomPropertyAnalysis(string $css): array
    {
        $root = array();
        (new CssStylesheetTransformer())->transform($css, static function (string $prelude, string $body) use (&$root): string {
            $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
            if ( null === $selectors || ! array_filter($selectors, static function (string $selector): bool {
                $selector = preg_replace('/\/\*.*?\*\//s', '', $selector) ?? $selector;
                return in_array(strtolower(trim($selector)), array(':root', 'html'), true);
            }) ) {
                return $prelude;
            }
            if ( preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $body, $matches, PREG_SET_ORDER) ) {
                foreach ( $matches as $match ) {
                    $root[(string) $match[1]] = trim((string) $match[2]);
                }
            }

            return $prelude;
        });
        $fallback = array();
        if ( preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $css, $matches, PREG_SET_ORDER) ) {
            foreach ( $matches as $match ) {
                $fallback[(string) $match[1]] = trim((string) $match[2]);
            }
        }

        return array('root' => $root, 'fallback' => $fallback);
    }

    /** @return array{source_tags: array<string, bool>, rules: list<array<string, mixed>>} */
    private function authorSelectorAnalysis(string $css): array
    {
        $sourceTags = array();
        $rules = array();
        (new CssStylesheetTransformer())->transform($css, function (string $prelude, string $body) use (&$sourceTags, &$rules): string {
            $ruleSelectors = array();
            foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
                $parsed = CssSelectorMatcher::parse($selector);
                $directSelector = preg_replace('/::[a-z-]+(?:\([^)]*\))?$/i', '', trim($selector)) ?? $selector;
                $ruleSelectors[] = array('selector' => $selector, 'parsed' => $parsed, 'direct_child_parsed' => CssSelectorMatcher::parse($directSelector));
                foreach ( $parsed['type_spans'] ?? array() as $typeSpan ) {
                    $tagName = strtolower($typeSpan['name']);
                    if ( in_array($tagName, array('div', 'li', 'nav', 'p'), true) ) {
                        $sourceTags[$tagName] = true;
                    }
                }
            }
            if ( array() !== $ruleSelectors ) {
                $rules[] = array('order' => count($rules), 'declarations' => $this->styleResolver->cssDeclarations($body), 'selectors' => $ruleSelectors);
            }

            return $prelude;
        });

        return array('source_tags' => $sourceTags, 'rules' => $rules);
    }
}
