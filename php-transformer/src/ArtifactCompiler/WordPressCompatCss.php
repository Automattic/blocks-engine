<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\MenuVocabulary;

/**
 * WordPress runtime compatibility CSS projected from authored stylesheets.
 */
final class WordPressCompatCss
{
    /**
     * Template classes WordPress adds to `<body>` via `body_class()`. A source
     * stylesheet that styles an element of the same name collides with them.
     *
     * @var array<int, string>
     */
    private const WORDPRESS_BODY_CLASSES = array(
        'archive', 'attachment', 'author', 'blog', 'category', 'date', 'error404',
        'home', 'page', 'paged', 'privacy-policy', 'search', 'single', 'tag',
    );

    /** @var array<string, string> */
    private array $cssCache = array();

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, string> $scriptContents
     */
    public function css(string $authoredCss, array $files, array $scriptContents): string
    {
        $cacheKey = hash('sha256', $authoredCss);
        if ( array_key_exists($cacheKey, $this->cssCache) ) {
            return $this->cssCache[$cacheKey];
        }
        return $this->cssCache[$cacheKey] = $this->navigationContainerCompatCss($authoredCss)
            . $this->navigationStructureCompatCss($authoredCss)
            . $this->navigationAnchorCompatCss($authoredCss)
            . $this->rootStartupClassCompatCss($authoredCss, $scriptContents)
            . $this->responsiveRootCompatCss($authoredCss)
            . $this->bodyClassCollisionCompatCss($authoredCss)
            . $this->coreRuntimeCompatCss($authoredCss, $files);
    }

    /**
     * WordPress stamps template classes such as `page`, `home`, and `search`
     * onto `<body>`. A source class rule of the same name then applies to the
     * document body as well as its own element, so a centered page frame pays
     * its max-width and gutters twice: the inline axis narrows every line box,
     * and the block axis adds the frame's leading and trailing space again
     * outside the content.
     *
     * Neutralize only the frame properties, only on `body`, and only for the
     * reserved names WordPress owns.
     */
    private function bodyClassCollisionCompatCss(string $css): string
    {
        $classes = array();
        foreach ( $this->bodyClassCollisionRules($css) as $class ) {
            $classes[$class] = true;
        }
        if ( array() === $classes ) {
            return '';
        }

        $selectors = array();
        foreach ( array_keys($classes) as $class ) {
            $selectors[] = 'body.' . $class;
        }

        return "\n\n/* wp-compat: WordPress body template classes must not inherit source frame rules. */\n"
            . implode(",\n", $selectors)
            . ' { max-width:none!important;width:auto!important;padding:0!important;margin:0!important }';
    }

    /** @return array<int, string> */
    private function bodyClassCollisionRules(string $css): array
    {
        $classes = array();
        foreach ( $this->topLevelCssRules($css, true) as $rule ) {
            if ( str_starts_with(trim($rule['selector']), '@') ) {
                foreach ( $this->bodyClassCollisionRules($rule['body']) as $nested ) {
                    $classes[$nested] = true;
                }
                continue;
            }
            if ( ! preg_match('/(?:^|;)\s*(?:max-width|width|padding|padding-inline|padding-block|padding-left|padding-right|padding-top|padding-bottom|margin|margin-inline|margin-block)\s*:/i', $rule['body']) ) {
                continue;
            }
            foreach ( $this->splitSelectorList($rule['selector']) as $selector ) {
                // The rule scanner keeps preceding comments on the selector.
                $selector = trim(preg_replace('#/\*.*?\*/#s', '', $selector) ?? $selector);
                if ( ! preg_match('/^\.([A-Za-z_][A-Za-z0-9_-]*)$/', $selector, $match) ) {
                    continue;
                }
                if ( in_array(strtolower($match[1]), self::WORDPRESS_BODY_CLASSES, true) ) {
                    $classes[$match[1]] = true;
                }
            }
        }

        return array_keys($classes);
    }

    /**
     * WordPress owns the body element, so a source body's responsive class is
     * not available to disable a captured desktop-only root minimum width.
     */
    private function responsiveRootCompatCss(string $css): string
    {
        $rules = $this->responsiveRootCompatCssRules($css);
        if ( array() === $rules ) {
            return '';
        }

        return "\n\n/* wp-compat: WordPress body does not retain the source responsive root class. */\n"
            . implode("\n", $rules);
    }

    /** @return array<int, string> */
    private function responsiveRootCompatCssRules(string $css): array
    {
        $selectors = array();
        $rules = array();
        foreach ( $this->topLevelCssRules($css, true) as $rule ) {
            if ( str_starts_with($rule['selector'], '@') ) {
                if ( preg_match('/^@(media|supports|container|layer|scope)\b/i', $rule['selector']) ) {
                    $nested = $this->responsiveRootCompatCssRules($rule['body']);
                    if ( array() !== $nested ) {
                        $rules[] = $rule['selector'] . ' {' . implode('', $nested) . '}';
                    }
                }
                continue;
            }
            if ( ! preg_match('/(?:^|;)\s*min-width\s*:\s*(?!0(?:[a-z%]+)?\s*(?:!important)?\s*(?:;|$))/i', $rule['body']) ) {
                continue;
            }
            foreach ( $this->splitSelectorList($rule['selector']) as $selector ) {
                if ( preg_match('/\bbody\s*:not\(\s*\.responsive\s*\)/i', $selector) ) {
                    $selectors[trim($selector)] = true;
                }
            }
        }

        if ( array() !== $selectors ) {
            $rules[] = implode(",\n", array_keys($selectors)) . ' { min-width:0!important }';
        }

        return $rules;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, string> $scriptContents
     * @return array<string, mixed>|null
     */
    public function asset(array $files, string $staticCss, array $scriptContents): ?array
    {
        $css = trim($this->css($staticCss, $files, $scriptContents));
        if ( '' === $css ) {
            return null;
        }

        $hash = hash('sha256', $css);
        $path = 'assets/css/wordpress-compat-' . substr($hash, 0, 16) . '.css';
        return array(
            'source'      => 'wordpress-compat',
            'path'        => $path,
            'target_path' => $path,
            'kind'        => 'css',
            'role'        => 'stylesheet',
            'intent'      => 'style',
            'media_type'  => 'text/css',
            'mime_type'   => 'text/css',
            'bytes'       => strlen($css),
            'binary'      => false,
            'content'     => $css,
            'hash'        => $hash,
        );
    }

    private function navigationAnchorCompatCss(string $css): string
    {
        $rules = $this->navigationAnchorCompatRules($css);
        if ( array() === $rules ) {
            return '';
        }

        return "\n\n/* wp-compat: replay source nav anchor selectors against core/navigation wrapper markup */\n" . implode("\n", $rules);
    }

    /**
     * A responsive menu states its compact anchor box inside a breakpoint, so
     * the replay has to follow the source into its conditional groups.
     *
     * A selector that names the source list is the structure pass's to map:
     * that pass rewrites `li` and `a` against core's container. Mapping it here
     * as a bare anchor would fuse `.wp-block-navigation` onto the list item
     * instead of the menu.
     *
     * @return array<int, string>
     */
    private function navigationAnchorCompatRules(string $css): array
    {
        $rules = array();
        foreach ( $this->topLevelCssRules($css, true) as $rule ) {
            // The rule scanner keeps preceding comments on the selector, so an
            // at-rule introduced by a section banner still has to be recognized
            // as one.
            $selectorList = trim($this->selectorWithoutComments($rule['selector']));
            $body = $rule['body'];
            if ( '' === $body ) {
                continue;
            }

            if ( str_starts_with($selectorList, '@') ) {
                if ( ! preg_match('/^@(media|supports|container|layer)\b/i', $selectorList) ) {
                    continue;
                }
                $nested = $this->navigationAnchorCompatRules($body);
                if ( array() !== $nested ) {
                    $rules[] = $selectorList . ' {' . implode('', $nested) . '}';
                }
                continue;
            }
            if ( str_contains(strtolower($body), 'url(') ) {
                continue;
            }

            $mappedSelectors = array();
            foreach ( $this->splitSelectorList($selectorList) as $selector ) {
                if ( array() !== $this->mapNavigationStructureSelector($selector, $body) ) {
                    continue;
                }
                foreach ( $this->mapNavigationAnchorSelector($selector) as $mappedSelector ) {
                    $mappedSelectors[$mappedSelector] = true;
                }
            }

            if ( array() !== $mappedSelectors ) {
                $rules[] = implode(', ', array_keys($mappedSelectors)) . ' { ' . $body . ' }';
            }
        }

        return $rules;
    }

    private function navigationStructureCompatCss(string $css): string
    {
        $rules = $this->navigationStructureCompatRules($css);
        if ( array() === $rules ) {
            return '';
        }

        return "\n\n/* wp-compat: project source list navigation structure onto core/navigation markup */\n" . implode("\n", $rules);
    }

    /**
     * @return array<int, string>
     */
    private function navigationStructureCompatRules(string $css): array
    {
        $rules = array();
        foreach ( $this->topLevelCssRules($css, true) as $rule ) {
            $selectorList = trim($rule['selector']);
            $body = $rule['body'];
            if ( '' === $body ) {
                continue;
            }

            if ( str_starts_with($selectorList, '@') ) {
                if ( ! preg_match('/^@(media|supports|container|layer)\b/i', $selectorList) ) {
                    continue;
                }
                $nestedRules = $this->navigationStructureCompatRules($body);
                if ( array() !== $nestedRules ) {
                    $rules[] = $selectorList . ' {' . implode('', $nestedRules) . '}';
                }
                continue;
            }
            if ( str_contains(strtolower($body), 'url(') ) {
                continue;
            }

            $mappedSelectors = array();
            foreach ( $this->splitSelectorList($selectorList) as $selector ) {
                foreach ( $this->mapNavigationStructureSelector($selector, $body) as $mappedSelector ) {
                    $mappedSelectors[$mappedSelector] = true;
                }
            }

            if ( array() !== $mappedSelectors ) {
                $rules[] = implode(', ', array_keys($mappedSelectors)) . ' { ' . $body . ' }';
            }
        }

        return $rules;
    }

    private function navigationContainerCompatCss(string $css): string
    {
        $rules = array();
        foreach ( $this->topLevelCssRules($css) as $rule ) {
            $body = $rule['body'];
            if ( '' === $body || str_contains(strtolower($body), 'url(') || ! preg_match('/(?:^|;)\s*display\s*:/i', $body) ) {
                continue;
            }

            $mappedSelectors = array();
            foreach ( $this->splitSelectorList($rule['selector']) as $selector ) {
                $mapped = $this->mapNavigationContainerSelector($selector);
                if ( null !== $mapped ) {
                    $mappedSelectors[$mapped] = true;
                }
            }

            if ( array() !== $mappedSelectors ) {
                $rules[] = implode(', ', array_keys($mappedSelectors)) . ' { ' . $body . ' }';
            }
        }

        if ( array() === $rules ) {
            return '';
        }

        return "\n\n/* wp-compat: preserve source navigation container cascade against core/navigation */\n" . implode("\n", $rules);
    }

    private function mapNavigationContainerSelector(string $selector): ?string
    {
        if ( str_contains($selector, '.wp-block-navigation') || ! preg_match('/([^\s>+~]+)\s*$/', trim($selector), $match, PREG_OFFSET_CAPTURE) ) {
            return null;
        }

        $compound = (string) ($match[1][0] ?? '');
        if ( ! preg_match('/(?:^|[.#_-])(?:' . MenuVocabulary::unconditionalMenuTokenRegexFragment() . ')(?:$|[.#_:-])/i', $compound)
            || ! preg_match('/(?:^|[.#_-])(?:collapsed|mobile|drawer|overlay|offcanvas|responsive)(?:$|[.#_:-])/i', $compound) ) {
            return null;
        }

        $pseudoOffset = false;
        if ( preg_match('/:{1,2}/', $compound, $pseudoMatch, PREG_OFFSET_CAPTURE) ) {
            $pseudoOffset = (int) $pseudoMatch[0][1];
        }
        $mappedCompound = false === $pseudoOffset
            ? $compound . '.wp-block-navigation'
            : substr($compound, 0, $pseudoOffset) . '.wp-block-navigation' . substr($compound, $pseudoOffset);

        return substr($selector, 0, (int) $match[1][1]) . $mappedCompound;
    }

    /** @param array<int, string> $scriptContents */
    private function rootStartupClassCompatCss(string $css, array $scriptContents): string
    {
        $classes = $this->rootStartupClassNames($scriptContents);
        if ( array() === $classes ) {
            return '';
        }

        $rules = array();
        foreach ( $this->topLevelCssRules($css) as $rule ) {
            $body = $rule['body'];
            if ( '' === $body || str_contains(strtolower($body), 'url(') ) {
                continue;
            }

            $mappedSelectors = array();
            foreach ( $this->splitSelectorList($rule['selector']) as $selector ) {
                foreach ( $classes as $class ) {
                    $mapped = preg_replace('/\b(body|html)\.' . preg_quote($class, '/') . '\b/', '$1', $selector, 1, $count);
                    if ( 1 === $count && is_string($mapped) ) {
                        $mappedSelectors[trim($mapped)] = true;
                    }
                }
            }

            if ( array() !== $mappedSelectors ) {
                $rules[] = implode(', ', array_keys($mappedSelectors)) . ' { ' . $body . ' }';
            }
        }

        if ( array() === $rules ) {
            return '';
        }

        return "\n\n/* wp-compat: materialize stable source startup root classes */\n" . implode("\n", $rules);
    }

    /**
     * @param array<int, string> $scriptContents
     * @return array<int, string>
     */
    private function rootStartupClassNames(array $scriptContents): array
    {
        $added = array();
        $removed = array();
        foreach ( $scriptContents as $script ) {
            if ( preg_match_all('/\$\(\s*(["\'])(?:body|html)\1\s*\)\s*\.\s*addClass\s*\(\s*(["\'])([^"\']+)\2\s*\)/', $script, $matches) ) {
                foreach ( $matches[3] as $classList ) {
                    foreach ( preg_split('/\s+/', trim((string) $classList)) ?: array() as $class ) {
                        if ( preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $class) ) {
                            $added[$class] = true;
                        }
                    }
                }
            }
            if ( preg_match_all('/document\s*\.\s*(?:body|documentElement)\s*\.\s*classList\s*\.\s*add\s*\(\s*(["\'])([A-Za-z_][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
                foreach ( $matches[2] as $class ) {
                    $added[(string) $class] = true;
                }
            }
            if ( preg_match_all('/(?:removeClass|toggleClass|classList\s*\.\s*(?:remove|toggle))\s*\([^)]*(["\'])([A-Za-z_][A-Za-z0-9_-]*)\1/', $script, $matches) ) {
                foreach ( $matches[2] as $class ) {
                    $removed[(string) $class] = true;
                }
            }
        }

        return array_values(array_diff(array_keys($added), array_keys($removed)));
    }

    /** @param array<int, array<string, mixed>> $files */
    private function coreRuntimeCompatCss(string $css, array $files): string
    {
        $rules = array();
        foreach ( $files as $file ) {
            if ( 'html' !== ($file['kind'] ?? '') || ! is_string($file['content'] ?? null) ) {
                continue;
            }
            if ( preg_match('/\baria-current\s*=|\b(?:id|class)\s*=\s*(?:"[^"]*(?:active|current|selected)[^"]*"|\'[^\']*(?:active|current|selected)[^\']*\'|[^\s>]*(?:active|current|selected)[^\s>]*)/i', $file['content']) ) {
                $rules['current-navigation'] = '.blocks-engine-current-navigation-underline>.wp-block-navigation-item__content { text-decoration:underline }';
                break;
            }
        }

        foreach ( $this->topLevelCssRules($css, true) as $rule ) {
            if ( str_starts_with(trim($rule['selector']), '@') ) {
                if ( '' !== $this->coreRuntimeCompatCss($rule['body'], array()) ) {
                    $rules['search-icon'] = '.wp-block-search.wp-block-search__icon-button .wp-block-search__button.has-icon>.search-icon { display:block!important;height:1.25em!important }';
                    break;
                }
                continue;
            }
            if ( str_contains($rule['selector'], '.search-icon')
                && ! str_contains($rule['selector'], '.wp-block-search')
                && preg_match('/(?:^|;)\s*display\s*:\s*none\b/i', $rule['body']) ) {
                $rules['search-icon'] = '.wp-block-search.wp-block-search__icon-button .wp-block-search__button.has-icon>.search-icon { display:block!important;height:1.25em!important }';
                break;
            }
        }

        return array() === $rules
            ? ''
            : "\n\n/* wp-compat: protect core block runtime semantics from source selector collisions */\n" . implode("\n", $rules);
    }

    /**
     * Top-level qualified rules, with nesting and lexical context honoured.
     *
     * This walked the stylesheet byte by byte with its own comment and quote
     * handling, and like every other local copy it did not read escapes — so an
     * escaped brace in a selector desynchronised the block depth and dropped
     * every rule after it. CssSyntaxScanner owns that lexing.
     *
     * @return array<int, array{selector:string,body:string}>
     */
    private function topLevelCssRules(string $css, bool $includeConditionalRules = false): array
    {
        $rules = array();
        $length = strlen($css);
        $state = CssSyntaxScanner::state();
        $start = 0;
        $cursor = 0;

        while ( $cursor < $length ) {
            if ( ! CssSyntaxScanner::isTopLevel($state) || '{' !== $css[ $cursor ] ) {
                $isStatementEnd = CssSyntaxScanner::isTopLevel($state) && ';' === $css[ $cursor ];
                $cursor = CssSyntaxScanner::consume($css, $cursor, $state) ?? ( $cursor + 1 );
                if ( $isStatementEnd ) {
                    $start = $cursor;
                }
                continue;
            }

            $closing = CssSyntaxScanner::matchingBrace($css, $cursor);
            if ( null === $closing ) {
                break;
            }

            $selector = trim(substr($css, $start, $cursor - $start));
            if ( '' !== $selector && ( $includeConditionalRules || ! str_starts_with($selector, '@') ) ) {
                $rules[] = array(
                    'selector' => $selector,
                    'body'     => trim(substr($css, $cursor + 1, $closing - $cursor - 1)),
                );
            }

            $start = $cursor = $closing + 1;
        }

        return $rules;
    }

    /**
     * Split a selector list on top-level commas.
     *
     * Delegates to the shared transformer rather than repeating the scan:
     * escapes, comments, quotes, parentheses and brackets all have to be
     * honoured, and this class had its own ~55-line copy of that logic.
     *
     * @return array<int, string>
     */
    private function splitSelectorList(string $selectorList): array
    {
        $selectors = array();
        foreach ( CssStylesheetTransformer::splitSelectorList($selectorList) ?? array() as $selector ) {
            $selector = trim($selector);
            if ( '' !== $selector ) {
                $selectors[] = $selector;
            }
        }

        return $selectors;
    }

    /**
     * @return array<int, string>
     */
    private function mapNavigationAnchorSelector(string $selector): array
    {
        if ( ! preg_match('/(^|[\s>+~])a(?=$|[\s:.#\[])/', $selector, $anchorMatch, PREG_OFFSET_CAPTURE) ) {
            return array();
        }

        $separator = (string) ($anchorMatch[1][0] ?? '');
        $anchorStart = (int) $anchorMatch[0][1] + strlen($separator);
        $prefix = substr($selector, 0, $anchorStart);
        if ( 1 !== preg_match('/[.#\[]/', $prefix) ) {
            return array();
        }

        $mapped = preg_replace('/(\s*[>+~]?\s*)a:first-child\b/', '$1.wp-block-navigation-item:first-child > .wp-block-navigation-item__content', $selector);
        $mapped = preg_replace('/(\s*[>+~]?\s*)a:last-child\b/', '$1.wp-block-navigation-item:last-child > .wp-block-navigation-item__content', (string) $mapped);
        $mapped = preg_replace('/(\s*[>+~]?\s*)a:nth-child\(([^)]*)\)/', '$1.wp-block-navigation-item:nth-child($2) > .wp-block-navigation-item__content', (string) $mapped);
        $mapped = preg_replace('/(\s*[>+~]?\s*)a(?![A-Za-z0-9_-])/', '$1.wp-block-navigation-item__content', (string) $mapped);
        $mapped = (string) $mapped;

        $selectors = array();
        $directWrapper = $this->addNavigationClassToLastPrefixCompound($mapped, $anchorStart);
        if ( null !== $directWrapper ) {
            $selectors[$directWrapper] = true;
        }
        $descendantWrapper = $this->insertNavigationDescendantWrapper($mapped, $prefix);
        if ( null !== $descendantWrapper ) {
            $selectors[$descendantWrapper] = true;
        }

        return array_keys($selectors);
    }

    /**
     * @return array<int, string>
     */
    private function mapNavigationStructureSelector(string $selector, string $body): array
    {
        $selector = $this->selectorWithoutComments($selector);
        if ( str_contains($selector, '.wp-block-navigation') ) {
            return array();
        }

        $hasListMatch = preg_match('/(^|\s*[>+~]?\s*)(?:ul|ol)((?:[.#][A-Za-z_][A-Za-z0-9_-]*)+)(?=$|[\s>+~:])/', $selector, $listMatch, PREG_OFFSET_CAPTURE);
        if ( 1 !== $hasListMatch ) {
            $hasListMatch = preg_match('/(^|\s*[>+~]?\s*)((?:[.#][A-Za-z_][A-Za-z0-9_-]*)+)(?=\s+[^,{]*blocks-engine-source-li-)/', $selector, $listMatch, PREG_OFFSET_CAPTURE);
        }
        if ( 1 !== $hasListMatch ) {
            return array();
        }

        $listClasses = (string) ($listMatch[2][0] ?? '');
        if ( ! preg_match('/(?:nav|menu)/i', $listClasses) ) {
            return array();
        }

        $matchStart = (int) ($listMatch[0][1] ?? 0);
        $matchLength = strlen((string) ($listMatch[0][0] ?? ''));
        $prefix = rtrim(substr($selector, 0, $matchStart));
        $tail = substr($selector, $matchStart + $matchLength);
        if ( '' === trim($tail) ) {
            if ( ! preg_match('/(?:^|;)\s*(?:visibility\s*:\s*visible\b|opacity\s*:\s*1(?:\.0+)?\b|display\s*:\s*(?!none\b)[^;}]+)/i', $body)
                || ! preg_match('/\.(?:is-)?(?:visible|shown|open|opened|active|ready|loaded|expanded)\b/i', $listClasses) ) {
                return array();
            }
            $stableListClasses = preg_replace('/\.(?:is-)?(?:visible|shown|open|opened|active|ready|loaded|expanded)\b/i', '', $listClasses);
            if ( ! is_string($stableListClasses) || $stableListClasses === $listClasses || ! preg_match('/(?:nav|menu)/i', $stableListClasses) ) {
                return array();
            }
            $listClasses = $stableListClasses;
        }
        $tail = preg_replace(
            '/:where\(\.blocks-engine-source-li-[A-Za-z0-9_-]+\):not\(blocks-engine-specificity-[A-Za-z0-9_-]+\)/',
            '.wp-block-navigation-item',
            $tail
        ) ?? $tail;
        $tail = preg_replace('/(^|[\s>+~])li(?=$|[\s>+~:.#\[])/', '$1.wp-block-navigation-item', $tail) ?? $tail;
        $tail = preg_replace('/(^|[\s>+~])a(?=$|[\s>+~:.#\[])/', '$1.wp-block-navigation-item__content', $tail) ?? $tail;
        $runtimeTail = ' .wp-block-navigation__container' . $tail;
        $scope = $listClasses . '.wp-block-navigation';

        $selectors = array();
        if ( '' === $prefix ) {
            $selectors[$scope . $runtimeTail] = true;
            return array_keys($selectors);
        }

        $selectors[$prefix . ' ' . $scope . $runtimeTail] = true;
        if ( preg_match('/([^\s>+~]+)$/', $prefix, $prefixMatch, PREG_OFFSET_CAPTURE) ) {
            $compound = (string) ($prefixMatch[1][0] ?? '');
            $offset = (int) ($prefixMatch[1][1] ?? 0);
            $pseudoOffset = strpos($compound, ':');
            $fused = false === $pseudoOffset
                ? $compound . $listClasses . '.wp-block-navigation'
                : substr($compound, 0, $pseudoOffset) . $listClasses . '.wp-block-navigation' . substr($compound, $pseudoOffset);
            $selectors[substr($prefix, 0, $offset) . $fused . $runtimeTail] = true;
        }

        return array_keys($selectors);
    }

    private function selectorWithoutComments(string $selector): string
    {
        $result = '';
        $length = strlen($selector);
        for ( $index = 0; $index < $length; $index++ ) {
            $char = $selector[$index];
            if ( '\\' === $char && $index + 1 < $length ) {
                $result .= $char . $selector[++$index];
                continue;
            }
            if ( in_array($char, array( '"', "'" ), true) ) {
                $quote = $char;
                $result .= $char;
                while ( ++$index < $length ) {
                    $result .= $selector[$index];
                    if ( '\\' === $selector[$index] && $index + 1 < $length ) {
                        $result .= $selector[++$index];
                        continue;
                    }
                    if ( $quote === $selector[$index] ) {
                        break;
                    }
                }
                continue;
            }
            if ( '/' === $char && '*' === ($selector[$index + 1] ?? '') ) {
                $end = strpos($selector, '*/', $index + 2);
                if ( false === $end ) {
                    break;
                }
                $index = $end + 1;
                continue;
            }
            $result .= $char;
        }

        return trim($result);
    }

    private function addNavigationClassToLastPrefixCompound(string $selector, int $anchorStart): ?string
    {
        $prefix = substr($selector, 0, $anchorStart);
        if ( ! preg_match('/([^\s>+~]+)(\s*[>+~]?\s*)$/', $prefix, $match, PREG_OFFSET_CAPTURE) ) {
            return null;
        }

        $compound = (string) ($match[1][0] ?? '');
        if ( str_contains($compound, '.wp-block-navigation') ) {
            return $selector;
        }

        $pseudoOffset = false;
        if ( preg_match('/:{1,2}/', $compound, $pseudoMatch, PREG_OFFSET_CAPTURE) ) {
            $pseudoOffset = (int) $pseudoMatch[0][1];
        }

        $mappedCompound = false === $pseudoOffset
            ? $compound . '.wp-block-navigation'
            : substr($compound, 0, $pseudoOffset) . '.wp-block-navigation' . substr($compound, $pseudoOffset);
        $mappedPrefix = substr($prefix, 0, (int) $match[1][1]) . $mappedCompound . (string) ($match[2][0] ?? '');

        return $mappedPrefix . substr($selector, $anchorStart);
    }

    private function insertNavigationDescendantWrapper(string $selector, string $prefix): ?string
    {
        $parentPrefix = rtrim((string) preg_replace('/[\s>+~]+$/', '', $prefix));
        if ( '' === $parentPrefix || str_contains($parentPrefix, '.wp-block-navigation') ) {
            return null;
        }

        $tail = ltrim((string) preg_replace('/^[\s>+~]+/', '', substr($selector, strlen($prefix))));
        if ( '' === $tail ) {
            return null;
        }

        return $parentPrefix . ' .wp-block-navigation ' . $tail;
    }
}
