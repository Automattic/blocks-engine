<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\Utf8Text;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Source → blocks content conservation: rendered source text must reach the
 * document's own emitted block markup.
 *
 * {@see ContentRoundTripReporter} runs output ⊆ source to catch invented copy,
 * and the site plan's content conservation only proves that text already in a
 * document's block markup reaches the plan. Neither notices text a converter
 * or pattern dropped before serialization: a slideshow match that replaced a
 * layout wrapper discarded the product title, description and purchase form,
 * and the import still reported zero content loss.
 *
 * One DOM pass collects the rendered, authored text runs of the source body
 * (skipping script/style/template, `hidden`/`inert` subtrees and inline
 * `display:none`). Each run is looked up in the document's own output: inner
 * HTML text, attribute values, and block-comment attribute text such as a
 * generated block's `content`. Exact runs resolve through a hash index; only
 * misses fall back to a substring search. Headings and submit forms are also
 * conserved as landmarks, because the same words elsewhere on the page (a
 * breadcrumb, a review widget) would otherwise mask their loss.
 *
 * Deduplicated responsive counterparts carry the same text as the kept copy,
 * so they never produce a finding; nothing is tolerated without that evidence.
 * Accepted removals are element-scoped and evidence-backed: text that is
 * aria-hidden or visually hidden (sr-only classes, clip), stylesheet
 * `display:none` at every viewport, fallbacks that already name a loss,
 * navigation toggles the session superseded, disclosure controls a native
 * core/navigation re-renders, and WordPress login/comment forms a dynamic
 * block renders with its own labels.
 * Lost runs are grouped by the source element whose conversion emitted the
 * nearest block, so one dropped subtree yields one `html_content_dropped`
 * finding.
 */
final class SourceContentConservationReporter
{
    public const CODE = 'html_content_dropped';

    private const MAX_LOST_TEXT = 5;

    private const MAX_SNIPPET = 200;

    /** Subtrees whose text never renders as page copy. */
    private const NON_RENDERED_TAGS = array(
        'script' => true, 'style' => true, 'template' => true, 'noscript' => true,
        'head' => true, 'title' => true, 'meta' => true, 'link' => true,
        'svg' => true, 'math' => true, 'iframe' => true, 'object' => true,
        'embed' => true, 'canvas' => true, 'video' => true, 'audio' => true,
    );

    /** Fallback loss classes that keep their content, so they name no loss. */
    private const PRESERVING_LOSS_CLASSES = array('', 'native_conversion', 'preserved_runtime_island', 'native_block_gap_preserved', 'editable_approximation');

    /** @var list<array{node: DOMElement, parent: int, selector: string, tag: string, class: string, kept: int, lost: int}> */
    private array $elements = array();

    /** @var list<array{element: int, text: string, key: string}> */
    private array $runs = array();

    private string $outputMarkup = '';

    /** @var list<array{element: int, key: string, first: int, last: int}> */
    private array $headings = array();

    /** @var list<array{element: int, first: int, last: int, evidence: list<string>, label: string}> */
    private array $forms = array();

    /**
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @param array<int, string> $outputMarkups The document's own emitted markup:
     *        serialized blocks plus the shell artifacts extracted from it.
     * @param array<int, array<string, mixed>> $fallbacks Fallbacks already
     *        reporting a loss name their element; content below it is not silent.
     * @param (Closure(DOMElement): bool)|null $acceptedRemoval Existing conversion
     *        evidence that an element was removed on purpose (a navigation
     *        toggle superseded by the native overlay, a suppressed panel).
     * @return list<array<string, mixed>>
     */
    public function evaluate(DOMElement $body, array $outputMarkups, array $sourceProvenance, array $fallbacks = array(), string $sourcePath = '', ?Closure $acceptedRemoval = null): array
    {
        $this->elements = array();
        $this->runs = array();
        $this->headings = array();
        $this->forms = array();
        $this->outputMarkup = implode("\n", $outputMarkups);
        $output = $this->outputIndex($this->outputMarkup);
        $this->walk($body, -1, '');
        if ( array() === $this->runs && array() === $this->forms ) {
            return array();
        }

        $lost = array();
        foreach ( $this->runs as $index => $run ) {
            if ( ! isset($output['runs'][$run['key']]) && ! str_contains($output['text'], $run['key']) && ! str_contains($output['blocks'], $run['key']) && ! str_contains($output['attributes'], $run['key']) ) {
                $lost[$index] = true;
            }
        }
        $this->lostHeadings($output, $lost);
        $this->lostForms($output, $lost);
        $this->withoutAcceptedRemovals($lost, $fallbacks, $acceptedRemoval);
        if ( array() === $lost ) {
            return array();
        }

        return $this->findings($lost, $this->provenanceOwners($sourceProvenance), $sourcePath);
    }

    /**
     * Normalized lookup surfaces for the emitted markup.
     *
     * @return array{runs: array<string, true>, text: string, blocks: string, attributes: string, headings: array<string, true>, forms: int}
     */
    private function outputIndex(string $markup): array
    {
        $blockText = array();
        // Block-comment attribute JSON carries text a generated or dynamic
        // block renders from its attributes (content, label, text).
        if ( preg_match_all('/<!--\s+wp:[a-z0-9\/_-]+\s+(\{.*?\})\s+\/?-->/s', $markup, $comments) ) {
            foreach ( $comments[1] as $json ) {
                $decoded = json_decode($json, true);
                if ( is_array($decoded) ) {
                    array_walk_recursive($decoded, static function (mixed $value) use (&$blockText): void {
                        if ( is_string($value) && '' !== $value ) {
                            $blockText[] = $value;
                        }
                    });
                }
            }
        }
        $attributeText = array();
        $formCount = preg_match_all('/<!--\s+wp:search\b/', $markup);
        $markup = (string) preg_replace('/<!--.*?-->/s', '', $markup);
        $formCount += preg_match_all('/<form\b/i', $markup);
        if ( preg_match_all('/<[a-zA-Z][^>]*>/', $markup, $tags) ) {
            foreach ( $tags[0] as $tag ) {
                if ( preg_match_all('/\s[a-zA-Z_:][-\w:.]*\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $tag, $values) ) {
                    foreach ( $values[1] as $position => $value ) {
                        $attributeText[] = $value . $values[2][$position];
                    }
                }
            }
        }
        $headings = array();
        if ( preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1\s*>/is', $markup, $matches) ) {
            foreach ( $matches[2] as $inner ) {
                $headings[$this->key(strip_tags($inner))] = true;
            }
        }
        $markup = (string) preg_replace('#<(script|style|template)\b[^>]*>.*?</\1\s*>#is', '', $markup);
        $runs = array();
        foreach ( preg_split('/<[^>]*>/', $markup) ?: array() as $run ) {
            $key = $this->key($run);
            if ( '' !== $key ) {
                $runs[$key] = true;
            }
        }

        return array(
            'runs'       => $runs,
            'text'       => $this->key(strip_tags($markup)),
            'blocks'     => implode("\x1F", array_map(fn (string $value): string => $this->key($value), $blockText)),
            'attributes' => implode("\x1F", array_map(fn (string $value): string => $this->key($value), $attributeText)),
            'headings'   => $headings,
            'forms'      => (int) $formCount,
        );
    }

    /**
     * Single pre-order pass over rendered source elements.
     */
    private function walk(DOMElement $element, int $parent, string $selector): void
    {
        $index = count($this->elements);
        $tag = strtolower($element->tagName);
        $this->elements[] = array(
            'node'     => $element,
            'parent'   => $parent,
            'selector' => $selector,
            'tag'      => $tag,
            'class'    => trim($element->getAttribute('class')),
            'kept'     => 0,
            'lost'     => 0,
        );
        $firstRun = count($this->runs);
        $heading = 1 === preg_match('/^h[1-6]$/', $tag);
        $form = 'form' === $tag ? array('evidence' => array(), 'label' => '', 'submit' => false) : null;

        $ordinals = array();
        for ( $child = $element->firstChild; $child instanceof DOMNode; $child = $child->nextSibling ) {
            if ( $child instanceof DOMText ) {
                $this->addRun($index, $child->data);
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $childTag = strtolower($child->tagName);
            $ordinals[$childTag] = ( $ordinals[$childTag] ?? 0 ) + 1;
            if ( ! $this->isRendered($child, $childTag) ) {
                continue;
            }
            if ( 'img' === $childTag && $this->imageRetained($child) ) {
                // Retained media is kept content: it marks the element whose
                // conversion survived in part, which is where a drop happened.
                ++$this->elements[$index]['kept'];
            }
            $childSelector = ( '' === $selector ? '' : $selector . ' > ' ) . $childTag . ':nth-of-type(' . $ordinals[$childTag] . ')';
            $this->walk($child, $index, $childSelector);
        }

        $lastRun = count($this->runs) - 1;
        if ( $heading && $lastRun >= $firstRun ) {
            $key = $this->sourceKey((string) $element->textContent);
            if ( '' !== $key ) {
                $this->headings[] = array('element' => $index, 'key' => $key, 'first' => $firstRun, 'last' => $lastRun);
            }
        }
        if ( null !== $form ) {
            $this->recordForm($element, $index, $firstRun, $lastRun);
        }
    }

    private function isRendered(DOMElement $element, string $tag): bool
    {
        // aria-hidden marks authored presentation (decorative duplicates,
        // ghost copies) that carries no page content of its own.
        if ( isset(self::NON_RENDERED_TAGS[$tag]) || $element->hasAttribute('hidden') || $element->hasAttribute('inert') || SourceDom::isAriaHidden($element) ) {
            return false;
        }
        // Visually-hidden utility classes clip their text out of the page; it
        // is an accessible name, never rendered copy.
        if ( 1 === preg_match('/(?:^|[\s_-])(?:sr-only|screen-reader-(?:text|only)|visually-?hidden)(?:\s|$)/i', $element->getAttribute('class')) ) {
            return false;
        }
        $style = $element->getAttribute('style');
        if ( 1 === preg_match('/position\s*:\s*absolute/i', $style) && 1 === preg_match('/clip\s*:\s*rect\(\s*0[^)]*\)|clip-path\s*:\s*inset\(\s*50%/i', $style) ) {
            return false;
        }

        return '' === $style || 1 !== preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\s*(?:!important\s*)?(?:;|$)/i', $style);
    }

    private function imageRetained(DOMElement $image): bool
    {
        $candidates = array($image->getAttribute('src'));
        foreach ( explode(',', $image->getAttribute('srcset')) as $candidate ) {
            $candidates[] = (string) preg_replace('/\s.*$/s', '', trim($candidate));
        }
        foreach ( $candidates as $url ) {
            $name = basename((string) preg_replace('/[?#].*$/', '', trim($url)));
            if ( '' !== $name && str_contains($name, '.') && ! str_starts_with(strtolower(trim($url)), 'data:') && str_contains($this->outputMarkup, $name) ) {
                return true;
            }
        }

        return false;
    }

    private function addRun(int $element, string $text): void
    {
        $text = trim((string) preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $text));
        if ( '' === $text || 1 !== preg_match('/[\p{L}\p{N}].*[\p{L}\p{N}]/us', $text) ) {
            return;
        }
        $this->runs[] = array('element' => $element, 'text' => $text, 'key' => $this->sourceKey($text));
    }

    private function recordForm(DOMElement $form, int $index, int $firstRun, int $lastRun): void
    {
        $evidence = array();
        $label = '';
        $submit = false;
        foreach ( array('button', 'input') as $controlTag ) {
            foreach ( $form->getElementsByTagName($controlTag) as $control ) {
                $type = strtolower(trim($control->getAttribute('type')));
                $isSubmit = 'button' === $controlTag ? in_array($type, array('', 'submit'), true) : in_array($type, array('submit', 'image'), true);
                $name = $this->sourceKey($control->getAttribute('name'));
                if ( '' !== $name ) {
                    $evidence[] = $name;
                }
                if ( ! $isSubmit ) {
                    continue;
                }
                $submit = true;
                $text = 'button' === $controlTag ? (string) $control->textContent : $control->getAttribute('value');
                $text = trim((string) preg_replace('/\s+/u', ' ', $text));
                if ( '' !== $this->sourceKey($text) ) {
                    $evidence[] = $this->sourceKey($text);
                    $label = '' === $label ? $text : $label;
                }
            }
        }
        foreach ( array('select', 'textarea') as $fieldTag ) {
            foreach ( $form->getElementsByTagName($fieldTag) as $field ) {
                $name = $this->sourceKey($field->getAttribute('name'));
                if ( '' !== $name ) {
                    $evidence[] = $name;
                }
            }
        }
        $action = $this->sourceKey($form->getAttribute('action'));
        if ( '' !== $action ) {
            $evidence[] = $action;
        }
        if ( $submit && array() !== $evidence ) {
            $this->forms[] = array('element' => $index, 'first' => $firstRun, 'last' => $lastRun, 'evidence' => array_values(array_unique($evidence)), 'label' => $label);
        }
    }

    /**
     * A heading whose words survive only elsewhere is still lost: its text is
     * in no emitted heading and the output carries fewer copies than the source.
     *
     * @param array{runs: array<string, true>, text: string, blocks: string, attributes: string, headings: array<string, true>, forms: int} $output
     * @param array<int, true> $lost
     */
    private function lostHeadings(array $output, array &$lost): void
    {
        if ( array() === $this->headings ) {
            return;
        }
        $sourceText = implode('', array_column($this->runs, 'key'));
        foreach ( $this->headings as $heading ) {
            if ( isset($output['headings'][$heading['key']]) ) {
                continue;
            }
            if ( substr_count($output['text'], $heading['key']) + substr_count($output['blocks'], $heading['key']) >= substr_count($sourceText, $heading['key']) ) {
                continue;
            }
            for ( $run = $heading['first']; $run <= $heading['last']; ++$run ) {
                $lost[$run] = true;
            }
        }
    }

    /**
     * Submit forms are conserved by count, and a missing form is identified by
     * the absence of every name, action and submit label it carried.
     *
     * @param array{runs: array<string, true>, text: string, blocks: string, attributes: string, headings: array<string, true>, forms: int} $output
     * @param array<int, true> $lost
     */
    private function lostForms(array $output, array &$lost): void
    {
        if ( count($this->forms) <= $output['forms'] ) {
            return;
        }
        foreach ( $this->forms as $form ) {
            foreach ( $form['evidence'] as $evidence ) {
                if ( isset($output['runs'][$evidence]) || str_contains($output['text'], $evidence) || str_contains($output['blocks'], $evidence) || str_contains($output['attributes'], $evidence) ) {
                    continue 2;
                }
            }
            for ( $run = $form['first']; $run <= $form['last']; ++$run ) {
                $lost[$run] = true;
            }
            if ( $form['last'] < $form['first'] ) {
                $this->runs[] = array('element' => $form['element'], 'text' => '' !== $form['label'] ? $form['label'] : 'form', 'key' => '');
                $lost[count($this->runs) - 1] = true;
            }
        }
    }

    /**
     * Content below an element a fallback already reports as lost is named
     * loss, and content below an element removed with existing evidence is an
     * accepted removal. Neither is a silent drop, so both stay out.
     *
     * @param array<int, true> $lost
     * @param array<int, array<string, mixed>> $fallbacks
     * @param (Closure(DOMElement): bool)|null $acceptedRemoval
     */
    private function withoutAcceptedRemovals(array &$lost, array $fallbacks, ?Closure $acceptedRemoval): void
    {
        $reported = array();
        foreach ( $fallbacks as $fallback ) {
            $selector = is_array($fallback) && is_string($fallback['selector'] ?? null) ? $fallback['selector'] : '';
            if ( '' !== $selector && ! in_array($fallback['loss_class'] ?? '', self::PRESERVING_LOSS_CLASSES, true) ) {
                $reported[$selector] = true;
            }
        }
        $accepted = array();
        foreach ( array_keys($lost) as $run ) {
            for ( $element = $this->runs[$run]['element']; $element > 0; $element = $this->elements[$element]['parent'] ) {
                $accepted[$element] ??= isset($reported[$this->elements[$element]['selector']])
                    || $this->isServerRendered($this->elements[$element]['node'])
                    || $this->isNativeNavigationControl($this->elements[$element]['node'])
                    || ( null !== $acceptedRemoval && true === $acceptedRemoval($this->elements[$element]['node']) );
                if ( $accepted[$element] ) {
                    unset($lost[$run]);
                    break;
                }
            }
        }
    }

    /**
     * A WordPress form the output replaces with the dynamic block that renders
     * its own labels and title: a login form (password field) as core/loginout,
     * a comment form or its respond container as core/post-comments-form.
     */
    private function isServerRendered(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        $comments = str_contains($this->outputMarkup, '<!-- wp:post-comments-form');
        if ( 'form' !== $tag ) {
            return $comments && ( 'respond' === $element->getAttribute('id') || 1 === preg_match('/(?:^|\s)comment-respond(?:\s|$)/', $element->getAttribute('class')) );
        }
        if ( $comments && ( str_ends_with(strtolower((string) parse_url($element->getAttribute('action'), PHP_URL_PATH)), 'wp-comments-post.php') || 'commentform' === $element->getAttribute('id') ) ) {
            return true;
        }
        if ( ! str_contains($this->outputMarkup, '<!-- wp:loginout') ) {
            return false;
        }
        foreach ( $element->getElementsByTagName('input') as $input ) {
            if ( 'password' === strtolower(trim($input->getAttribute('type'))) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A disclosure control (an expanding button or link, or a checkbox toggle label)
     * inside site navigation chrome is re-rendered by core/navigation's own
     * opener and submenu toggles once the output carries a navigation block.
     */
    private function isNativeNavigationControl(DOMElement $element): bool
    {
        if ( ! str_contains($this->outputMarkup, '<!-- wp:navigation') ) {
            return false;
        }
        $tag = strtolower($element->tagName);
        $disclosure = ( 'button' === $tag || 'a' === $tag || 'button' === strtolower($element->getAttribute('role')) )
            && ( $element->hasAttribute('aria-expanded') || $element->hasAttribute('aria-controls') );
        if ( ! $disclosure && 'label' === $tag ) {
            $for = $element->getAttribute('for');
            $control = '' !== $for ? $element->ownerDocument?->getElementById($for) : null;
            foreach ( $control instanceof DOMElement ? array($control) : $element->getElementsByTagName('input') as $input ) {
                $disclosure = $disclosure || ( 'input' === strtolower($input->tagName) && 'checkbox' === strtolower(trim($input->getAttribute('type'))) );
            }
        }
        if ( ! $disclosure ) {
            return false;
        }
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode ) {
            if ( in_array(strtolower($ancestor->tagName), array('nav', 'header', 'dialog'), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<string, string> Source selector => emitted block name.
     */
    private function provenanceOwners(array $sourceProvenance): array
    {
        $owners = array();
        foreach ( $sourceProvenance as $entry ) {
            $selector = is_string($entry['selector'] ?? null) ? $entry['selector'] : '';
            if ( '' !== $selector && ! isset($owners[$selector]) ) {
                $owners[$selector] = (string) ( $entry['block_name'] ?? '' );
            }
        }

        return $owners;
    }

    /**
     * @param array<int, true> $lost
     * @param array<string, string> $owners
     * @return list<array<string, mixed>>
     */
    private function findings(array $lost, array $owners, string $sourcePath): array
    {
        // Children are recorded after their parents, so one reverse sweep
        // totals every subtree's kept and lost runs.
        foreach ( $this->runs as $index => $run ) {
            ++$this->elements[$run['element']][isset($lost[$index]) ? 'lost' : 'kept'];
        }
        for ( $index = count($this->elements) - 1; $index > 0; --$index ) {
            $parent = $this->elements[$index]['parent'];
            if ( $parent >= 0 ) {
                $this->elements[$parent]['kept'] += $this->elements[$index]['kept'];
                $this->elements[$parent]['lost'] += $this->elements[$index]['lost'];
            }
        }

        $groups = array();
        ksort($lost);
        foreach ( array_keys($lost) as $runIndex ) {
            $run = $this->runs[$runIndex];
            $chain = array();
            for ( $element = $run['element']; $element >= 0; $element = $this->elements[$element]['parent'] ) {
                $chain[] = $element;
            }
            // The container is the nearest ancestor that kept some content:
            // the element whose conversion survived only in part. All lost
            // runs below one container are one dropped subtree.
            $container = 0;
            foreach ( $chain as $element ) {
                if ( 0 < $this->elements[$element]['kept'] ) {
                    $container = $element;
                    break;
                }
            }
            $groups[$container]['chains'][] = array_reverse($chain);
            $groups[$container]['text'][] = $run['text'];
        }

        $findings = array();
        foreach ( $groups as $container => $group ) {
            $dropped = $this->commonAncestor($group['chains']) ?? 0;
            // A document that kept nothing names the dropped subtree itself.
            $element = $this->elements[0 === $container ? $dropped : $container];
            $lostText = array_values(array_unique($group['text']));
            $findings[] = FallbackDiagnostic::build(array_filter(array(
                'code'             => self::CODE,
                'diagnostic_code'  => self::CODE,
                'severity'         => 'warning',
                'source'           => \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer::class,
                'source_path'      => $sourcePath,
                'selector'         => $element['selector'],
                'tag'              => $element['tag'],
                'source_class'     => $element['class'],
                'block_name'       => $owners[$element['selector']] ?? '',
                'dropped_selector' => $this->elements[$dropped]['selector'],
                'lost_text_count'  => count($lostText),
                'lost_text'        => array_map(static fn (string $text): string => strlen($text) > self::MAX_SNIPPET ? rtrim(Utf8Text::prefix($text, self::MAX_SNIPPET)) . '…' : $text, array_slice($lostText, 0, self::MAX_LOST_TEXT)),
                'message'          => 'Rendered source content is missing from the emitted blocks.',
                'summary'          => 'Rendered source content is missing from the emitted blocks.',
            ), static fn (mixed $value): bool => '' !== $value));
        }

        return $findings;
    }

    /** @param list<list<int>> $chains Root-first ancestor chains. */
    private function commonAncestor(array $chains): ?int
    {
        $common = $chains[0];
        foreach ( $chains as $chain ) {
            $length = 0;
            while ( $length < count($common) && $length < count($chain) && $common[$length] === $chain[$length] ) {
                ++$length;
            }
            $common = array_slice($common, 0, $length);
        }

        return array() === $common ? null : $common[count($common) - 1];
    }

    /** Lookup key for emitted markup text, whose entities are still encoded. */
    private function key(string $text): string
    {
        return $this->sourceKey(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Lookup key for source DOM text, which the parser already decoded. */
    private function sourceKey(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[\s\x{00A0}\x{2007}\x{200B}-\x{200D}\x{202F}\x{2060}\x{FEFF}]+/u', '', $text), 'UTF-8');
    }
}
