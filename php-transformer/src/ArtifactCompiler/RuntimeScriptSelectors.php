<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CaptureReplayScripts;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;
use Automattic\BlocksEngine\PhpTransformer\Support\RuntimeSelectorVocabulary;
use DOMElement;

/**
 * Selectors a document's runtime scripts read or write: DOM targets, canvas
 * surfaces and form controls that conversion must keep addressable.
 */
final class RuntimeScriptSelectors
{
    public function __construct(
        private readonly RuntimeScriptEvidenceAnalyzer $evidence,
        private readonly ArtifactFileIndex $fileIndex
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    public function runtimeDomSelectors(string $html, string $sourcePath, array $files): array
    {
        $hasDeclaredScriptFiles = false;
        foreach ( $files as $file ) {
            if ( is_array($file) && 'inline-script' !== ($file['source'] ?? '') && ArtifactFileIndex::isMaterializedScriptAsset($file) ) {
                $hasDeclaredScriptFiles = true;
                break;
            }
        }
        $documentScripts = $this->documentScriptContents($html, $sourcePath, $files);
        if ( ! $hasDeclaredScriptFiles && array() === $documentScripts ) {
            return array();
        }

        $selectors = array();
        $controlSelectors = $this->formControlSelectors($html);
        $statusFeedbackSelectors = $this->formStatusFeedbackSelectors($html);
        foreach ( $documentScripts as $script ) {
            foreach ( $this->evidence->analyze($script)['dependencies'] as $dependency ) {
                $selector = (string) $dependency['selector'];
                if ( ! $hasDeclaredScriptFiles && ! str_contains($selector, '[data-')
                    && ! $this->evidence->hasTextOnlyMutations($script, $selector) ) {
                    continue;
                }
                if ( true === $dependency['presentation_only'] ) {
                    continue;
                }
                if ( isset($controlSelectors[$selector]) && true !== $dependency['control_runtime'] ) {
                    continue;
                }
                $selectors[$selector] = true;
            }
        }

        foreach ( $this->fileIndex->allScriptContents($files) as $script ) {
            foreach ( $this->evidence->analyze($script)['dependencies'] as $dependency ) {
                $selector = (string) $dependency['selector'];
                if ( true === $dependency['presentation_only'] ) {
                    continue;
                }
                if ( isset($statusFeedbackSelectors[$selector]) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    /**
     * Presentation-only scripts still need stable DOM identities when editable
     * block serialization cannot retain their source data attributes.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    public function runtimeProjectionSelectors(string $html, string $sourcePath, array $files): array
    {
        $selectors = array();
        foreach ( $this->documentScriptContents($html, $sourcePath, $files) as $script ) {
            foreach ( $this->evidence->analyze($script)['dependencies'] as $dependency ) {
                $selector = (string) $dependency['selector'];
                if ( str_contains($selector, '[data-') && true === $dependency['presentation_only'] ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<string, bool>
     */
    private function formStatusFeedbackSelectors(string $html): array
    {
        $selectors = array();
        $document = HtmlSerialization::parseFragment('<body>' . $html . '</body>');

        foreach ( $document->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || ! $this->isFormStatusFeedbackElement($element) ) {
                continue;
            }

            $id = trim($element->hasAttribute('id') ? $element->getAttribute('id') : '');
            if ( '' !== $id ) {
                $selectors['#' . $id] = true;
            }
            foreach ( SourceDom::tokens($element->hasAttribute('class') ? $element->getAttribute('class') : '') as $class ) {
                if ( '' !== $class && ! $this->isBehaviorHookClassName($class) ) {
                    $selectors['.' . $class] = true;
                }
            }
        }

        return $selectors;
    }

    private function isFormStatusFeedbackElement(DOMElement $element): bool
    {
        if ( in_array(strtolower($element->tagName), array('button', 'input', 'select', 'textarea', 'form', 'script', 'style'), true) ) {
            return false;
        }

        $tokens = strtolower(trim(implode(' ', array(
            $element->hasAttribute('id') ? $element->getAttribute('id') : '',
            $element->hasAttribute('class') ? $element->getAttribute('class') : '',
            $element->hasAttribute('role') ? $element->getAttribute('role') : '',
            $element->hasAttribute('aria-live') ? 'aria-live' : '',
        ))));

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:form|contact|newsletter|signup|subscribe|submission|submit|message|status|feedback|alert|notice|response|success|error|warning|confirmation|thanks?)(?:[^a-z0-9]|$)/', $tokens)
            && (bool) preg_match('/(?:^|[^a-z0-9])(?:success|error|message|status|feedback|alert|notice|response|warning|confirmation|thanks?|aria-live)(?:[^a-z0-9]|$)/', $tokens);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    public function runtimeCanvasSelectors(string $html, string $sourcePath, array $files): array
    {
        $canvasSelectors = $this->canvasSelectors($html);
        if ( array() === $canvasSelectors ) {
            return array();
        }

        $selectors = array();
        $scripts = $this->documentScriptContents($html, $sourcePath, $files);
        foreach ( $scripts as $script ) {
            foreach ( $this->evidence->analyze($script)['canvas_selectors'] as $selector ) {
                if ( isset($canvasSelectors[$selector]) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        $combinedScripts = implode("\n", $scripts);
        if ( 1 === preg_match('/\.\s*getContext\s*\(/', $combinedScripts) ) {
            foreach ( $this->scriptCanvasArgumentSelectors($combinedScripts) as $selector ) {
                if ( isset($canvasSelectors[$selector]) ) {
                    $selectors[$selector] = true;
                }
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<int, string>
     */
    private function scriptCanvasArgumentSelectors(string $script): array
    {
        $selectors = array();
        if ( preg_match_all('/\b[A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*\s*\([^;)]*document\s*\.\s*getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\1\s*\)/', $script, $matches) ) {
            foreach ( $matches[2] as $id ) {
                $selectors['#' . (string) $id] = true;
            }
        }

        if ( preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\s*\.\s*(?:getElementById\s*\(\s*(["\'])([A-Za-z][A-Za-z0-9_-]*)\2\s*\)|querySelector\s*\(\s*(["\'])(' . RuntimeSelectorVocabulary::scriptSelectorPattern() . ')\4\s*\))/', $script, $assignments, PREG_SET_ORDER) ) {
            foreach ( $assignments as $assignment ) {
                $variable = (string) $assignment[1];
                if ( ! preg_match('/(?:\bnew\s+)?\b[A-Za-z_$][A-Za-z0-9_$]*(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*\s*\([^;)]*\b' . preg_quote($variable, '/') . '\b/', $script) ) {
                    continue;
                }
                $selectors['' !== (string) ($assignment[3] ?? '') ? '#' . (string) $assignment[3] : (string) $assignment[5]] = true;
            }
        }

        return array_keys($selectors);
    }

    /**
     * @return array<string, bool>
     */
    private function canvasSelectors(string $html): array
    {
        $selectors = array();
        $document = HtmlSerialization::parseFragment('<body>' . $html . '</body>');

        foreach ( $document->getElementsByTagName('canvas') as $canvas ) {
            if ( ! $canvas instanceof DOMElement ) {
                continue;
            }
            $id = trim($canvas->hasAttribute('id') ? $canvas->getAttribute('id') : '');
            if ( '' !== $id ) {
                $selectors['#' . $id] = true;
            }
            foreach ( SourceDom::tokens($canvas->hasAttribute('class') ? $canvas->getAttribute('class') : '') as $class ) {
                if ( '' !== $class ) {
                    $selectors['.' . $class] = true;
                    $selectors['canvas.' . $class] = true;
                }
            }
            $selectors['canvas'] = true;
        }

        return $selectors;
    }

    /**
     * @return array<string, bool>
     */
    private function formControlSelectors(string $html): array
    {
        $selectors = array();
        $document = HtmlSerialization::parseFragment('<body>' . $html . '</body>');

        foreach ( $document->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement || ! in_array(strtolower($element->tagName), array('button', 'input', 'select', 'textarea'), true) ) {
                continue;
            }

            $id = trim($element->hasAttribute('id') ? $element->getAttribute('id') : '');
            if ( '' !== $id ) {
                $selectors['#' . $id] = true;
            }
            foreach ( SourceDom::tokens($element->hasAttribute('class') ? $element->getAttribute('class') : '') as $class ) {
                if ( '' !== $class ) {
                    $selectors['.' . $class] = true;
                }
            }
        }

        return $selectors;
    }

    private function isBehaviorHookClassName(string $className): bool
    {
        return 1 === preg_match('/^js(?:$|[-_:]|[A-Z])/', $className);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, string>
     */
    private function documentScriptContents(string $html, string $sourcePath, array $files): array
    {
        $scripts = array();
        foreach ( HtmlTagScanner::scan($html, 'script') as $script ) {
            // Capture replay interpreters address captured evidence, not source
            // behavior; treating their queries as dependencies would preserve
            // every captured target as a runtime island.
            if (CaptureReplayScripts::isReplayScript($script['tag'])) continue;
            // Device overlays replay capture presentation, not author behavior.
            // Exclude the marked script before selector evidence is extracted;
            // an author script using the same selectors must still count.
            if (array_key_exists(CaptureEvidence::DEVICE_BODY, HtmlTagScanner::attributes($script['tag']))) continue;
            $src = HtmlTagScanner::attribute($script['tag'], 'src');
            if ( '' === $src ) {
                $scripts[] = $script['content'];
                continue;
            }

            $asset = $this->fileIndex->findAssetByHtmlReference($src, $sourcePath, $files);
            if ( is_array($asset) && ArtifactFileIndex::isMaterializedScriptAsset($asset) && is_string($asset['content'] ?? null) ) {
                $scripts[] = (string) $asset['content'];
            }
        }

        return $scripts;
    }
}
