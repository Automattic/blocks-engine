<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;

/**
 * Staged prepare/compilePage/compose transport extracted from ArtifactCompiler.
 *
 * ArtifactCompiler remains the public facade and owns canonical compile()
 * plus envelope finalize. Site-plan production is derived by
 * WordPressSitePlanComposer. No Context bag: trait methods use the compiler
 * host `$this` for document compilation.
 */
trait StagedTransport
{
    /**
     * Prepare the immutable, serializable shared portion of an artifact.
     *
     * File ownership is declared with `metadata.compilation`: `{scope:
     * "shared"}` or `{scope: "page", id: "..."}`. Unannotated HTML files
     * are page-owned by their normalized path; all other files are shared.
     *
     * @param array<string,mixed> $artifact
     * @return array<string,mixed>
     */
    public function prepareShared(array $artifact, ?PayloadReader $payloadReader = null): array
    {
        $artifact = (new ResponsiveDocumentVariants())->compose($artifact);
        if (null !== $payloadReader && $this->containsPayloadReferences($artifact)) {
            return $this->prepareReferencedStage($artifact, 'shared', '', $payloadReader, null);
        }
        $partition = $this->stagePartition($artifact, 'shared');
        $entryPath = (string) ($partition['entrypoints'][0] ?? '');
        $sharedArtifact = $this->artifactEnvelope($partition, $partition['shared']);
        $normalized = (new ArtifactNormalizer())->normalize($sharedArtifact);
        foreach ($normalized['files'] as &$file) if (isset($partition['canonical_provenance_hashes'][$file['path']])) $file['provenance']['hash'] = $partition['canonical_provenance_hashes'][$file['path']];
        unset($file);
        $sharedArtifact['files'] = $normalized['files'];

        $plan = array(
            'schema' => self::SHARED_PLAN_SCHEMA,
            'artifact' => $sharedArtifact,
            'limits' => $normalized['limits'],
            'diagnostics' => $normalized['diagnostics'],
            'summary' => array('file_count' => count($normalized['files']), 'bytes' => $normalized['bytes'], 'rejected_count' => $normalized['rejected_count']),
            'analysis' => array_merge($this->sharedAnalysis($normalized, array_keys($partition['pages'])), array(
                'entry_path' => $entryPath,
                'generated_asset_root' => '.' === dirname($entryPath) ? '' : trim(dirname($entryPath), '/'),
                'block_namespace' => (new CompanionPluginPayload())->blockNamespace($artifact),
                'source_paths' => $partition['source_paths'],
                // Preserve whole-artifact semantics before synthetic stylesheet
                // occurrence records are introduced by page workers.
                'canonical_source_hash' => $partition['canonical_source_hash'],
                'canonical_bytes' => $partition['canonical_bytes'],
                'canonical_diagnostics' => $partition['canonical_diagnostics'],
                'canonical_rejected_count' => $partition['canonical_rejected_count'],
                'captured_dialogs' => $partition['captured_dialogs'],
            )),
            'compiler_options' => $this->receiptCompilerOptions(),
        );
        $plan['shared_reduction'] = array(
            'files_source' => 'artifact',
            'component_facts' => $this->collectComponentFacts($sharedArtifact['files']),
            'inline_shell_compilation' => $this->compileSharedInlineShellReduction($partition, $artifact),
        );
        $plan['shared_reduction_digest'] = $this->planDigest($plan['shared_reduction']);
        $plan['digest'] = $this->planDigest($this->sharedPlanDigestInput($plan));
        return $plan;
    }

    /**
     * Prepare one page-owned artifact portion against an immutable shared plan.
     *
     * @param array<string,mixed> $artifact
     * @param array<string,mixed> $sharedPlan
     * @return array<string,mixed>
     */
    public function preparePage(array $artifact, array $sharedPlan, string $pageId, ?PayloadReader $payloadReader = null): array
    {
        $this->assertSharedPlan($sharedPlan);
        $artifact = (new ResponsiveDocumentVariants())->compose($artifact);
        if (null !== $payloadReader && $this->containsPayloadReferences($artifact)) {
            return $this->prepareReferencedStage($artifact, 'page', $pageId, $payloadReader, (string) $sharedPlan['digest']);
        }
        $partition = $this->stagePartition($artifact, 'page', $pageId);
        if (!isset($partition['pages'][$pageId])) {
            throw new \InvalidArgumentException('The requested page ownership id is not present in the artifact.');
        }
        return $this->pagePlanFromPartition($partition, $sharedPlan, $pageId);
    }

    /**
     * Prepare every page plan after one whole-artifact normalization and
     * ownership partition. The returned plans remain independently
     * serializable and compilable by separate workers.
     *
     * @param array<string,mixed> $artifact
     * @param array<string,mixed> $sharedPlan
     * @return array<string,array<string,mixed>>
     */
    public function preparePages(array $artifact, array $sharedPlan, ?PayloadReader $payloadReader = null): array
    {
        $this->assertSharedPlan($sharedPlan);
        $artifact = (new ResponsiveDocumentVariants())->compose($artifact);
        if (null !== $payloadReader && $this->containsPayloadReferences($artifact)) {
            $plans = array();
            foreach ($sharedPlan['analysis']['page_ids'] ?? array() as $pageId) {
                $plans[$pageId] = $this->prepareReferencedStage($artifact, 'page', (string) $pageId, $payloadReader, (string) $sharedPlan['digest']);
            }
            return $plans;
        }

        $partition = $this->stagePartition($artifact, 'pages');
        $plans = array();
        foreach (array_keys($partition['pages']) as $pageId) {
            $plans[$pageId] = $this->pagePlanFromPartition($partition, $sharedPlan, $pageId);
        }
        return $plans;
    }

    /**
     * @param array<string,mixed> $partition
     * @param array<string,mixed> $sharedPlan
     * @return array<string,mixed>
     */
    private function pagePlanFromPartition(array $partition, array $sharedPlan, string $pageId): array
    {
        $pageArtifact = $this->artifactEnvelope($partition, $partition['pages'][$pageId]);
        $normalized = (new ArtifactNormalizer())->normalize($pageArtifact);
        foreach ($normalized['files'] as &$file) if (isset($partition['canonical_provenance_hashes'][$file['path']])) $file['provenance']['hash'] = $partition['canonical_provenance_hashes'][$file['path']];
        unset($file);
        $pageArtifact['files'] = $normalized['files'];

        $plan = array(
            'schema' => self::PAGE_PLAN_SCHEMA,
            'shared_digest' => $sharedPlan['digest'],
            'page_id' => $pageId,
            'artifact' => $pageArtifact,
            'limits' => $normalized['limits'],
            'diagnostics' => $normalized['diagnostics'],
            'summary' => array('file_count' => count($normalized['files']), 'bytes' => $normalized['bytes'], 'rejected_count' => $normalized['rejected_count']),
            'compiler_options' => $this->receiptCompilerOptions(),
            'output_schema' => TransformerResult::SCHEMA,
            'layout_geometry_proof' => $this->pageLayoutGeometryProof($partition['layout_geometry_proof'], $pageArtifact['files']),
        );
        $plan['digest'] = $this->planDigest($this->pagePlanDigestInput($plan));
        return $plan;
    }

    /**
     * Compile the HTML owned by one page against an immutable shared plan.
     * The returned receipt is serializable and may be retained across an
     * interrupted fan-out before terminal composition.
     *
     * @param array<string,mixed> $artifact
     * @param array<string,mixed> $sharedPlan
     * @return array<string,mixed>
     */
    public function compilePage(array $artifact, array $sharedPlan, string $pageId, ?PayloadReader $payloadReader = null): array
    {
        $pagePlan = $this->preparePage($artifact, $sharedPlan, $pageId, $payloadReader);
        return $this->compilePreparedPage($sharedPlan, $pagePlan, $payloadReader);
    }

    /** Compile one serialized page plan without receiving the source artifact. */
    public function compilePreparedPage(array $sharedPlan, array $pagePlan, ?PayloadReader $payloadReader = null): array
    {
        return $this->compilePreparedPageWithCompiler($sharedPlan, $pagePlan, $this->preparedPageCompiler(), $payloadReader);
    }

    /**
     * Compile multiple independently serializable page plans while retaining
     * bounded immutable analysis within this worker batch.
     *
     * @param array<string,mixed> $sharedPlan
     * @param array<int|string,array<string,mixed>> $pagePlans
     * @return array<string,array<string,mixed>>
     */
    public function compilePreparedPages(array $sharedPlan, array $pagePlans, ?PayloadReader $payloadReader = null): array
    {
        $this->assertSharedPlan($sharedPlan);
        $stageCompiler = $this->preparedPageCompiler();
        $receipts = array();
        foreach ($pagePlans as $pagePlan) {
            if (!is_array($pagePlan) || !is_string($pagePlan['page_id'] ?? null) || isset($receipts[$pagePlan['page_id']])) {
                throw new \InvalidArgumentException('Prepared page batches require unique page plans with string page ids.');
            }
            $receipts[$pagePlan['page_id']] = $this->compilePreparedPageWithCompiler($sharedPlan, $pagePlan, $stageCompiler, $payloadReader, true);
        }
        $this->htmlTransformerAnalysisCache = $stageCompiler->htmlTransformerAnalysisCache;
        $this->stylesheetAssetDiscoveryCount = $stageCompiler->stylesheetAssetDiscoveryCount;
        return $receipts;
    }

    private function preparedPageCompiler(): self
    {
        $compiler = new self();
        $compiler->htmlTransformerAnalysisCache = $this->htmlTransformerAnalysisCache ?? new HtmlTransformerAnalysisCache();
        return $compiler;
    }

    /**
     * A shared plan is immutable for the whole batch, and verifying its
     * reduction digest rehashes every shared fact. Callers that already
     * verified this exact plan declare it so one batch validates once.
     *
     * @param array<string,mixed> $sharedPlan @param array<string,mixed> $pagePlan @return array<string,mixed>
     */
    private function compilePreparedPageWithCompiler(array $sharedPlan, array $pagePlan, self $stageCompiler, ?PayloadReader $payloadReader, bool $sharedPlanVerified = false): array
    {
        $startedAt = hrtime(true);
        $initialTransformCount = $stageCompiler->htmlDocumentTransformCount;
        if (!$sharedPlanVerified) $this->assertSharedPlan($sharedPlan);
        $this->assertPagePlan($pagePlan, $sharedPlan);
        $sharedArtifact = isset($sharedPlan['shared_reduction'])
            ? array_merge($sharedPlan['artifact'], array('files' => $this->sharedReductionFiles($sharedPlan, $payloadReader)))
            : $this->materializePlanArtifact($sharedPlan['artifact'], $payloadReader);
        $pageArtifact = $this->materializePlanArtifact($pagePlan['artifact'], $payloadReader);
        $pageLayoutGeometryProof = is_array($pagePlan['layout_geometry_proof'] ?? null) ? $pagePlan['layout_geometry_proof'] : array();
        foreach ($pageArtifact['files'] as &$pageFile) {
            if ('html' === ($pageFile['kind'] ?? null)) $pageFile['layout_geometry_proof'] = $pageLayoutGeometryProof;
        }
        unset($pageFile);
        $files = self::sortedByPath(array_merge($sharedArtifact['files'], $pageArtifact['files']));

        $entryPath = (string) ($sharedPlan['analysis']['entry_path'] ?? '');
        $stageCompiler->generatedAssetRoot = (string) ($sharedPlan['analysis']['generated_asset_root'] ?? '');
        $hasSharedStylesheetOccurrences = false;
        foreach ($sharedArtifact['files'] as $file) {
            if (isset($file['stylesheet_occurrence'])) {
                $hasSharedStylesheetOccurrences = true;
                break;
            }
        }

        $compiledDocuments = array();
        foreach ($pageArtifact['files'] as $file) {
            if ('html' !== ($file['kind'] ?? null) || $stageCompiler->isTemplatePartFile($file)) {
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            if ($pagePlan['page_id'] !== $stageCompiler->fileOwnership($file)['id']) {
                continue;
            }
            // Stylesheet occurrence records are local conversion inputs. They
            // are rebuilt from the owned source so reference-backed shared
            // plans remain portable without hydrating a page at preparation.
            $documentFiles = $hasSharedStylesheetOccurrences
                ? $files
                : $stageCompiler->withStylesheetOccurrenceAssets((string) ($file['content'] ?? ''), $path, $files);
            if (!$hasSharedStylesheetOccurrences) {
                foreach ($documentFiles as &$documentFile) {
                    if (isset($documentFile['stylesheet_occurrence']) && 'page' === $stageCompiler->fileOwnership($documentFile)['scope']) unset($documentFile['stylesheet_occurrence']);
                }
                unset($documentFile);
            }
            $stageCompiler->indexFiles($documentFiles);
            $compiledDocuments[$path] = $stageCompiler->compileHtmlDocumentBlocks(
                (string) ($file['content'] ?? ''),
                $path,
                $documentFiles,
                $path === $entryPath ? 'artifact-entry' : 'artifact-document',
                (string) ($sharedPlan['analysis']['block_namespace'] ?? ''),
                true
            );
        }
        ksort($compiledDocuments, SORT_STRING);
        $pageDocuments = $stageCompiler->compileSourceDocuments($pageArtifact);
        $entryBlocks = null;
        foreach ($pageArtifact['files'] as $file) {
            if (($file['path'] ?? null) === $entryPath && 'html' === ($file['kind'] ?? null)) {
                $entryBlocks = $compiledDocuments[$entryPath] ?? null;
                break;
            }
        }
        // A receipt owns every page-derived input required by final reduction.
        // Text is hydrated here; binary references deliberately stay portable.
        $pagePlan['receipt_schema'] = isset($sharedPlan['shared_reduction'])
            ? ($pagePlan['compiler_options']['compiled_page_schema'] ?? self::COMPACT_RECEIPT_SCHEMA)
            : self::PAGE_RECEIPT_SCHEMA;
        if (self::COMPACT_RECEIPT_SCHEMA === $pagePlan['receipt_schema']) $pagePlan['artifact'] = $pageArtifact;
        $pagePlan['compiled_documents'] = $compiledDocuments;
        $pagePlan['owned_document_paths'] = array_keys($compiledDocuments);
        if (!isset($sharedPlan['shared_reduction'])) {
            $pagePlan['work'] = array(
                'compiled_document_count' => count($compiledDocuments),
                'html_document_transform_count' => $stageCompiler->htmlDocumentTransformCount - $initialTransformCount,
                'normalization_count' => 0,
                'analysis_count' => 0,
                'compile_duration_ms' => (hrtime(true) - $startedAt) / 1000000,
            );
            $pagePlan['digest'] = $this->planDigest($this->pagePlanDigestInput($pagePlan));
            return $pagePlan;
        }
        $pagePlan['shared_reduction_digest'] = $sharedPlan['shared_reduction_digest'];
        $pagePlan['terminal_reduction'] = $stageCompiler->collectPageReduction(
            $pagePlan,
            $pageArtifact,
            $pageDocuments,
            $compiledDocuments,
            $entryBlocks,
            $files,
            $entryPath
        );
        if (self::COMPACT_RECEIPT_SCHEMA === $pagePlan['receipt_schema']) {
            unset($pagePlan['terminal_reduction']['files'], $pagePlan['terminal_reduction']['entry_blocks']);
        }
        /*
         * Observational work data is deliberately excluded from the receipt
         * digest so independently resumed work has stable canonical identity.
         */
        $pagePlan['work'] = array(
            'compiled_document_count' => count($compiledDocuments),
            'html_document_transform_count' => $stageCompiler->htmlDocumentTransformCount - $initialTransformCount,
            'normalization_count' => 0,
            'analysis_count' => 3,
            'compile_duration_ms' => (hrtime(true) - $startedAt) / 1000000,
        );
        $pagePlan['digest'] = $this->planDigest($this->pagePlanDigestInput($pagePlan));

        return $pagePlan;
    }

    /**
     * Collect the uncapped, serializable facts produced by exactly one page
     * worker. No terminal ordering or result-level caps are applied here.
     *
     * @param array<string,mixed> $pagePlan
     * @param array<string,mixed> $pageArtifact
     * @param array<string,mixed> $pageDocuments
     * @param array<string,array<string,mixed>> $compiledDocuments
     * @param array<string,mixed>|null $entryBlocks
     * @param array<int,array<string,mixed>> $files
     * @return array<string,mixed>
     */
    private function collectPageReduction(array $pagePlan, array $pageArtifact, array $pageDocuments, array $compiledDocuments, ?array $entryBlocks, array $files, string $entryPath): array
    {
        $stylesheetOccurrenceFiles = array();
        if (is_array($entryBlocks)) {
            $pageFilesByPath = array_column($pageArtifact['files'], null, 'path');
            foreach ($this->withStylesheetOccurrenceAssets((string) ($pageFilesByPath[$entryPath]['content'] ?? ''), $entryPath, $files) as $file) {
                if (isset($file['stylesheet_occurrence'])) $stylesheetOccurrenceFiles[] = $file;
            }
        }
        return array(
            'files' => $pageArtifact['files'],
            'normalization' => array(
                'diagnostics' => $pagePlan['diagnostics'] ?? array(),
                'rejected_count' => (int) ($pagePlan['summary']['rejected_count'] ?? 0),
                'bytes' => (int) ($pagePlan['summary']['bytes'] ?? 0),
            ),
            'source_documents' => $pageDocuments,
            'owned_transformable_paths' => $this->ownedTransformablePaths($pageArtifact['files'], (string) $pagePlan['page_id']),
            'entry_blocks' => $entryBlocks,
            'stylesheet_occurrence_files' => $stylesheetOccurrenceFiles,
            'component_facts' => $this->collectComponentFacts($pageArtifact['files'], $pageDocuments['components']),
            'block_types' => $this->detectBlockTypes($files, $pageDocuments['diagnostics']),
        );
    }

    /**
     * Compose independently prepared plans in canonical page-id and path order.
     *
     * @param array<string,mixed> $sharedPlan
     * @param array<int,array<string,mixed>> $pagePlans
     */
    public function compose(array $sharedPlan, array $pagePlans, ?PayloadReader $payloadReader = null): TransformerResult
    {
        // A compiler instance may have performed page work previously; terminal
        // receipt metrics describe this invocation only.
        $this->htmlDocumentTransformCount = 0;
        $this->assertSharedPlan($sharedPlan);
        $hasReceipts = false;
        foreach ($pagePlans as $candidate) if ($this->isTerminalReceiptSchema($candidate['receipt_schema'] ?? null)) { $hasReceipts = true; break; }
        $sharedArtifact = $hasReceipts
            ? array_merge($sharedPlan['artifact'], array('files' => $this->sharedReductionFiles($sharedPlan, $payloadReader)))
            : $this->materializePlanArtifact($sharedPlan['artifact'], $payloadReader);
        $files = $sharedArtifact['files'];
        $seen = array();
        usort($pagePlans, static fn(array $left, array $right): int => strcmp((string) ($left['page_id'] ?? ''), (string) ($right['page_id'] ?? '')));
        $compiledDocuments = array();
        $reductions = array();
        foreach ($pagePlans as $pagePlan) {
            $this->assertPagePlan($pagePlan, $sharedPlan);
            if (isset($seen[$pagePlan['page_id']])) {
                throw new \InvalidArgumentException(sprintf('Composition received more than one staged page plan for page id "%s".', $pagePlan['page_id']));
            }
            $seen[$pagePlan['page_id']] = true;
            $isReceipt = $this->isTerminalReceiptSchema($pagePlan['receipt_schema'] ?? null);
            if (!$isReceipt) {
                if ($hasReceipts) throw new \InvalidArgumentException('Composition requires a compiled receipt for every page plan.');
                $pageArtifact = $this->materializePlanArtifact($pagePlan['artifact'], $payloadReader);
                $files = array_merge($files, $pageArtifact['files']);
                continue;
            }
            if (!isset($sharedPlan['shared_reduction'])) throw new \InvalidArgumentException('Compiled terminal receipts require the digest-bound shared reduction supplied by their shared plan.');
            $reduction = $pagePlan['terminal_reduction'] ?? null;
            $isCompactReceipt = self::COMPACT_RECEIPT_SCHEMA === ($pagePlan['receipt_schema'] ?? null);
            $pageFiles = $isCompactReceipt ? ($pagePlan['artifact']['files'] ?? null) : ($reduction['files'] ?? null);
            if (!is_array($reduction) || !is_array($pageFiles) || !is_array($reduction['source_documents'] ?? null) || !is_array($reduction['component_facts'] ?? null)) throw new \InvalidArgumentException('A compiled page receipt requires a complete terminal reduction.');
            if (($pagePlan['shared_reduction_digest'] ?? null) !== ($sharedPlan['shared_reduction_digest'] ?? null)) throw new \InvalidArgumentException('A compiled page receipt is bound to another shared reduction.');
            $pageArtifact = array('files' => $pageFiles);
            $files = array_merge($files, $pageFiles);
            $expected = $this->ownedHtmlPaths($pageArtifact['files'], (string) $pagePlan['page_id']);
            if ($expected !== array_keys($pagePlan['compiled_documents']) || $expected !== ($pagePlan['owned_document_paths'] ?? null)) throw new \InvalidArgumentException('A compiled page receipt does not exactly cover its owned HTML documents.');
            $expectedTransformable = $this->ownedTransformablePaths($pageArtifact['files'], (string) $pagePlan['page_id']);
            $receivedTransformable = $reduction['owned_transformable_paths'] ?? null;
            $receivedSourcePaths = array_map(static fn(array $document): string => (string) ($document['source_path'] ?? ''), $reduction['source_documents']['documents']);
            sort($receivedSourcePaths, SORT_STRING);
            $expectedSourcePaths = array_values(array_filter($expectedTransformable, static fn(string $path): bool => !isset($pagePlan['compiled_documents'][$path])));
            if ($expectedTransformable !== $receivedTransformable || $expectedSourcePaths !== $receivedSourcePaths) throw new \InvalidArgumentException('A compiled page receipt does not exactly cover its owned transformable sources.');
            foreach ($pagePlan['compiled_documents'] as $path => $document) {
                if (!is_string($path) || !is_array($document) || isset($compiledDocuments[$path])) {
                    throw new \InvalidArgumentException('A compiled page plan contains invalid or duplicate document output.');
                }
                $compiledDocuments[$path] = $document;
            }
            if ($isCompactReceipt) {
                $reduction['files'] = $pageFiles;
                $entryPath = (string) ($sharedPlan['analysis']['entry_path'] ?? '');
                $reduction['entry_blocks'] = $pagePlan['compiled_documents'][$entryPath] ?? null;
            }
            $reductions[] = $reduction;
        }
        $this->assertUniqueComposedPaths($files);
        $expectedPageIds = is_array($sharedPlan['analysis']['page_ids'] ?? null) ? $sharedPlan['analysis']['page_ids'] : array();
        if ($hasReceipts && array() !== $expectedPageIds && array_values($expectedPageIds) !== array_keys($seen)) {
            throw new \InvalidArgumentException('Composition requires exactly one compiled page plan for every page declared by the shared plan.');
        }
        $artifact = $sharedArtifact;
        $artifact['files'] = self::sortedBySourcePaths(
            $files,
            is_array($sharedPlan['analysis']['source_paths'] ?? null) ? $sharedPlan['analysis']['source_paths'] : array()
        );
        if (!$hasReceipts) {
            // Legacy prepared envelopes intentionally retain their existing
            // fallback semantics; v2 receipts always use bounded assembly.
            return $this->compileArtifact($artifact);
        }
        $terminalReduction = $this->reduceCompiledReceipts($sharedPlan, $sharedArtifact, $reductions, $compiledDocuments);
        return $this->finalizeArtifact($terminalReduction['artifact'], $terminalReduction);
    }

    /**
     * Terminal assembly for v2 receipts. It deliberately accepts reductions,
     * not page envelopes or a PayloadReader: all page payload access and page
     * transforms have completed in compilePreparedPage().
     *
     * @param array<int,array<string,mixed>> $reductions
     * @param array<string,array<string,mixed>> $compiledDocuments
     */
    private function reduceCompiledReceipts(array $sharedPlan, array $sharedArtifact, array $reductions, array $compiledDocuments): array
    {
        $sharedReduction = $sharedPlan['shared_reduction'];
        $files = $sharedArtifact['files'];
        $sharedArtifact['files'] = $files;
        $documents = array('documents' => array(), 'components' => array(), 'diagnostics' => array());
        $componentFacts = array($sharedReduction['component_facts']);
        $blockTypes = array();
        $entryBlocks = null;
        $stylesheetOccurrenceFiles = array();
        foreach ($reductions as $reduction) {
            $files = array_merge($files, $reduction['files']);
            foreach ($reduction['source_documents']['documents'] as $document) $documents['documents'][] = $document;
            $documents['components'] = array_merge($documents['components'], $reduction['source_documents']['components']);
            $documents['diagnostics'] = array_merge($documents['diagnostics'], $reduction['source_documents']['diagnostics']);
            $componentFacts[] = $reduction['component_facts'];
            $blockTypes = array_merge($blockTypes, $reduction['block_types'] ?? array());
            if (is_array($reduction['entry_blocks'] ?? null)) $entryBlocks = $reduction['entry_blocks'];
            $stylesheetOccurrenceFiles = array_merge($stylesheetOccurrenceFiles, $reduction['stylesheet_occurrence_files'] ?? array());
        }
        $sourcePaths = is_array($sharedPlan['analysis']['source_paths'] ?? null) ? $sharedPlan['analysis']['source_paths'] : array();
        $files = self::sortedBySourcePaths($files, $sourcePaths);
        $hasSharedStylesheetOccurrences = false;
        foreach ($sharedArtifact['files'] as $file) {
            if (isset($file['stylesheet_occurrence'])) {
                $hasSharedStylesheetOccurrences = true;
                break;
            }
        }
        if (!$hasSharedStylesheetOccurrences && array() !== $stylesheetOccurrenceFiles) {
            $occurrencePaths = array_fill_keys(array_column($stylesheetOccurrenceFiles, 'path'), true);
            $files = array_values(array_filter($files, static fn(array $file): bool => !isset($occurrencePaths[$file['path'] ?? ''])));
            $files = self::sortedBySourcePaths(array_merge($files, $stylesheetOccurrenceFiles), $sourcePaths);
        }
        $documents['documents'] = self::sortedBySourcePaths($documents['documents'], $sourcePaths, 'source_path');
        $documents['diagnostics'] = $this->dedupeDiagnostics(array_merge(...array_map(
            static fn(array $document): array => is_array($document['diagnostics'] ?? null) ? $document['diagnostics'] : array(),
            $documents['documents']
        )));
        $compiledDocuments = self::orderedMapBySourcePaths($compiledDocuments, $sourcePaths);
        // Shared preparation already supplied this bounded normalization. Page
        // normalizations were performed by their individual receipt workers.
        $sharedNormalized = array(
            'diagnostics' => $sharedPlan['analysis']['canonical_diagnostics'] ?? ($sharedPlan['diagnostics'] ?? array()),
            'rejected_count' => $sharedPlan['analysis']['canonical_rejected_count'] ?? ($sharedPlan['summary']['rejected_count'] ?? 0),
            'limits' => $sharedPlan['limits'],
            'entrypoints' => $sharedArtifact['entrypoints'],
            'runtime_declarations' => $sharedArtifact['runtime_declarations'],
            'truncation_impact' => null,
        );
        $bytes = array_sum(array_map(static fn(array $file): int => (int) ($file['bytes'] ?? 0), $files));
        $diagnostics = $sharedNormalized['diagnostics'];
        $rejected = $sharedNormalized['rejected_count'];
        $normalized = array_merge($sharedNormalized, array(
            'files' => $files,
            'entrypoints' => $sharedArtifact['entrypoints'],
            'bytes' => $sharedPlan['analysis']['canonical_bytes'] ?? $bytes,
            'diagnostics' => $this->dedupeDiagnostics($diagnostics),
            'rejected_count' => $rejected,
            'source_hash' => $sharedPlan['analysis']['canonical_source_hash'] ?? $this->normalizedSourceHash($files, $sharedArtifact['runtime_declarations']),
        ));
        $artifact = $sharedArtifact;
        $artifact['files'] = $files;
        return array(
            'artifact' => $artifact,
            'normalized' => $normalized,
            'source_documents' => $documents,
            'components' => $this->finalizeComponentFacts($this->mergeComponentFacts($componentFacts), (string) ($sharedPlan['analysis']['entry_path'] ?? '')),
            'block_types' => $this->dedupeRows($blockTypes),
            'entry_blocks' => $entryBlocks,
            'compiled_documents' => array_filter($compiledDocuments, fn(string $path): bool => $path !== ($sharedPlan['analysis']['entry_path'] ?? ''), ARRAY_FILTER_USE_KEY),
            'inline_shell_compilation' => $sharedReduction['inline_shell_compilation'] ?? array('artifacts' => array(), 'assets' => array(), 'author_stylesheet_projections' => array(), 'runtime_script_projections' => array()),
            'captured_dialogs' => $sharedPlan['analysis']['captured_dialogs'] ?? array('diagnostics' => array(), 'projected_count' => 0),
        );
    }

    /**
     * Partition an envelope before normalization so preparing one stage never
     * parses, expands, or transforms payloads owned by another stage.
     *
     * @return array{shared:array<int,array<string,mixed>>,pages:array<string,array<int,array<string,mixed>>>,entrypoints:array<int,string>,limits:array<string,int>,runtime_declarations:array<int,array<string,mixed>>,layout_geometry_proof:array<string,mixed>,schema:string,input_keys:array<int,string>,identity:array<string,string>,source_paths:array<int,string>}
     */
    private function stagePartition(array $artifact, string $scope, string $pageId = ''): array
    {
        // Normalize the complete input once so file-level entrypoint flags,
        // roles, rejection diagnostics, and source order have exactly the same
        // meaning as inline compilation before ownership partitions are made.
        $normalized = (new ArtifactNormalizer())->normalize($artifact);
        $capturedDialogsProjection = (new CapturedDialogProjector())->project($normalized['files']);
        $scrollStatesProjection = (new ScrollStateProjector())->project($capturedDialogsProjection['files']);
        $capturedDialogs = array(
            'diagnostics' => array_merge($capturedDialogsProjection['diagnostics'], $scrollStatesProjection['diagnostics']),
            'projected_count' => $capturedDialogsProjection['projected_count'] + $scrollStatesProjection['projected_count'],
        );
        $rawFiles = $scrollStatesProjection['files'];
        // A later partition-envelope normalization must not lose the implicit
        // page ownership of already-expanded inline assets.
        foreach ($rawFiles as &$file) {
            $inlineSource = ArtifactNormalizer::inlineExpansionSourcePath($file);
            if ('' !== $inlineSource && !isset($file['metadata']['compilation'])) {
                $file['metadata']['compilation'] = array('scope' => 'page', 'id' => $inlineSource);
            }
        }
        unset($file);
        $shared = array();
        $pages = array();
        $sourcePaths = array();
        $canonicalProvenanceHashes = array();
        foreach ($rawFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            $safePath = ArtifactPath::safeRelativePath($path);
            if ('' !== $safePath && !in_array($safePath, $sourcePaths, true)) $sourcePaths[] = $safePath;
            if ('' !== $safePath && is_string($file['provenance']['hash'] ?? null)) $canonicalProvenanceHashes[$safePath] = $file['provenance']['hash'];
            $ownership = $file['metadata']['compilation'] ?? null;
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $fileScope = is_array($ownership) && is_string($ownership['scope'] ?? null)
                ? $ownership['scope']
                : (in_array($extension, array('html', 'htm', 'md', 'markdown', 'mdx'), true) ? 'page' : 'shared');
            $filePageId = is_array($ownership) && is_string($ownership['id'] ?? null) ? $ownership['id'] : $path;
            if ('page' === $fileScope) $pages[$filePageId][] = $file;
            else $shared[] = $file;
        }
        ksort($pages, SORT_STRING);
        $identity = array();
        foreach (array('site_slug', 'site_name', 'block_namespace') as $key) if (is_string($artifact[$key] ?? null)) $identity[$key] = $artifact[$key];
        return array(
            'shared' => 'shared' === $scope ? $shared : array(),
            'pages' => 'page' === $scope ? (isset($pages[$pageId]) ? array($pageId => $pages[$pageId]) : array()) : $pages,
            'entrypoints' => $normalized['entrypoints'],
            'limits' => $normalized['limits'],
            'runtime_declarations' => $normalized['runtime_declarations'],
            'layout_geometry_proof' => $normalized['layout_geometry_proof'],
            'schema' => is_string($artifact['schema'] ?? null) ? $artifact['schema'] : '',
            'input_keys' => array_values(array_filter(array_keys($artifact), 'is_string')),
            'identity' => $identity,
            'source_paths' => $sourcePaths,
            'canonical_source_hash' => $normalized['source_hash'],
            'canonical_bytes' => $normalized['bytes'],
            'canonical_provenance_hashes' => $canonicalProvenanceHashes,
            'canonical_diagnostics' => array_merge($normalized['diagnostics'], $capturedDialogs['diagnostics']),
            'canonical_rejected_count' => $normalized['rejected_count'],
            'captured_dialogs' => array(
                'diagnostics' => $capturedDialogs['diagnostics'],
                'projected_count' => $capturedDialogs['projected_count'],
            ),
        );
    }

    /**
     * @param array{entrypoints:array<int,string>,limits:array<string,int>,runtime_declarations:array<int,array<string,mixed>>,layout_geometry_proof:array<string,mixed>,schema:string,input_keys:array<int,string>} $partition
     * @param array<int,array<string,mixed>> $files
     * @return array<string,mixed>
     */
    private function artifactEnvelope(array $partition, array $files): array
    {
        $artifact = array(
            'files' => $files,
            'entrypoints' => $partition['entrypoints'],
            'compiler_limits' => $partition['limits'],
            'runtime_declarations' => $partition['runtime_declarations'],
            // Preserve the original generic source-operation identity across
            // serialized staged transport without exposing a consumer identity.
            'source_operation' => array('schema' => 'blocks-engine/php-transformer/source-operation/v1', 'input_keys' => $partition['input_keys']),
        );
        if ('' !== $partition['schema']) {
            $artifact['schema'] = $partition['schema'];
        }
        foreach (is_array($partition['identity'] ?? null) ? $partition['identity'] : array() as $key => $value) $artifact[$key] = $value;
        return $artifact;
    }

    /** @param array<string,mixed> $proof @param array<int,array<string,mixed>> $files @return array<string,mixed> */
    private function pageLayoutGeometryProof(array $proof, array $files): array
    {
        $paths = array_fill_keys(array_map(
            static fn(array $file): string => (string) ($file['path'] ?? ''),
            array_filter($files, static fn(array $file): bool => 'html' === ($file['kind'] ?? null))
        ), true);
        $reductions = array_values(array_filter(
            is_array($proof['reductions'] ?? null) ? $proof['reductions'] : array(),
            static fn(array $reduction): bool => isset($paths[(string) ($reduction['source_path'] ?? '')])
        ));
        return array() === $reductions ? array() : array('schema' => LayoutGeometryProof::SCHEMA, 'reductions' => $reductions);
    }

    /** @param array<string,mixed> $artifact */
    private function containsPayloadReferences(array $artifact): bool
    {
        foreach (is_array($artifact['files'] ?? null) ? $artifact['files'] : array() as $file) {
            if (is_array($file) && isset($file['payload_reference'])) return true;
        }
        return false;
    }

    /**
     * Resolve only one ownership partition, then replace the prepared payloads
     * with their portable references before returning the serializable plan.
     *
     * @return array<string,mixed>
     */
    private function prepareReferencedStage(array $artifact, string $scope, string $pageId, PayloadReader $payloadReader, ?string $sharedDigest): array
    {
        $this->assertReferenceLimits($artifact);
        $references = array();
        $hydratedArtifact = $artifact;
        $hydratedArtifact['files'] = array();
        foreach (is_array($artifact['files'] ?? null) ? $artifact['files'] : array() as $key => $file) {
            if (!is_array($file)) continue;
            $path = is_string($file['path'] ?? null) ? $file['path'] : (is_string($key) ? $key : '');
            $ownership = $file['metadata']['compilation'] ?? null;
            $fileScope = is_array($ownership) && is_string($ownership['scope'] ?? null) ? $ownership['scope'] : (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), array('html', 'htm', 'md', 'markdown', 'mdx'), true) ? 'page' : 'shared');
            $filePageId = is_array($ownership) && is_string($ownership['id'] ?? null) ? $ownership['id'] : $path;
            // Shared preparation establishes the digest-bound canonical source
            // catalog. Page workers subsequently hydrate only their page plus
            // the shared inputs they need.
            $required = 'shared' === $scope || 'shared' === $fileScope || ('page' === $scope && $pageId === $filePageId);
            if (isset($file['payload_reference'])) {
                $reference = $this->payloadReference($file['payload_reference']);
                $references[$path] = $reference;
                if ($required && !$this->isReferenceBackedBinary($file)) {
                    $content = $this->readPayload($reference, $payloadReader);
                    unset($file['payload_reference']);
                    $file['content'] = $content;
                }
            }
            $hydratedArtifact['files'][] = $file;
        }
        // Reference-backed callers receive the same whole-artifact
        // normalization and captured-dialog projection as inline callers.
        $partition = $this->stagePartition($hydratedArtifact, $scope, $pageId);
        $stageFiles = 'shared' === $scope ? $partition['shared'] : ($partition['pages'][$pageId] ?? array());
        $planArtifact = $this->artifactEnvelope($partition, $stageFiles);
        $normalized = (new ArtifactNormalizer())->normalize($planArtifact);
        foreach ($normalized['files'] as &$file) if (isset($partition['canonical_provenance_hashes'][$file['path']])) $file['provenance']['hash'] = $partition['canonical_provenance_hashes'][$file['path']];
        unset($file);
        $planArtifact['files'] = $normalized['files'];
        $plan = array(
            'schema' => 'shared' === $scope ? self::SHARED_PLAN_SCHEMA : self::PAGE_PLAN_SCHEMA,
            'artifact' => $planArtifact,
            'limits' => $normalized['limits'],
            'diagnostics' => $normalized['diagnostics'],
            'summary' => array('file_count' => count($normalized['files']), 'bytes' => $normalized['bytes'], 'rejected_count' => $normalized['rejected_count']),
            'compiler_options' => $this->receiptCompilerOptions(),
        );
        if ('shared' === $scope) {
            $entryPath = (string) ($partition['entrypoints'][0] ?? '');
            $plan['analysis'] = array_merge($this->sharedAnalysis($normalized, array_keys($partition['pages'])), array(
                'entry_path' => $entryPath,
                'generated_asset_root' => '.' === dirname($entryPath) ? '' : trim(dirname($entryPath), '/'),
                'block_namespace' => (new CompanionPluginPayload())->blockNamespace($hydratedArtifact),
                'source_paths' => $partition['source_paths'],
                'canonical_source_hash' => $partition['canonical_source_hash'],
                'canonical_bytes' => $partition['canonical_bytes'],
                'canonical_diagnostics' => $partition['canonical_diagnostics'],
                'canonical_rejected_count' => $partition['canonical_rejected_count'],
                'captured_dialogs' => $partition['captured_dialogs'],
            ));
            $plan['shared_reduction'] = array(
                'files' => $planArtifact['files'],
                'component_facts' => $this->collectComponentFacts($planArtifact['files']),
                'inline_shell_compilation' => $this->compileSharedInlineShellReduction($partition, $hydratedArtifact),
            );
            $plan['shared_reduction_digest'] = $this->planDigest($plan['shared_reduction']);
        }
        if ('page' === $scope) {
            $plan['shared_digest'] = $sharedDigest;
            $plan['page_id'] = $pageId;
            $plan['output_schema'] = TransformerResult::SCHEMA;
            $plan['layout_geometry_proof'] = $this->pageLayoutGeometryProof($partition['layout_geometry_proof'], $planArtifact['files']);
        }
        foreach ($plan['artifact']['files'] as &$file) {
            if (!isset($references[$file['path']])) continue;
            // Projection changes page HTML. Only retain a portable reference
            // when its canonical bytes still match the referenced payload.
            if (!hash_equals($references[$file['path']]['sha256'], hash('sha256', (string) ($file['content'] ?? '')))) continue;
            unset($file['content'], $file['content_base64']);
            $file['payload_reference'] = $references[$file['path']];
        }
        unset($file);
        if ('shared' === $scope) {
            foreach ($plan['shared_reduction']['files'] as &$file) {
                if (!isset($references[$file['path']]) || !isset($file['payload_reference'])) continue;
                // Binary reductions retain their portable publication reference;
                // text reductions retain the hydrated bytes needed by workers.
                $file['payload_reference'] = $references[$file['path']];
            }
            unset($file);
            $plan['shared_reduction_digest'] = $this->planDigest($plan['shared_reduction']);
        }
        $plan['digest'] = $this->planDigest('shared' === $scope ? $this->sharedPlanDigestInput($plan) : $this->pagePlanDigestInput($plan));
        return $plan;
    }

    /** Reject unbounded reference declarations before a reader can allocate. */
    private function assertReferenceLimits(array $artifact): void
    {
        $requested = is_array($artifact['compiler_limits'] ?? null) ? $artifact['compiler_limits'] : array();
        $maxFile = min(ArtifactNormalizer::MAX_FILE_BYTES, max(1, (int) ($requested['max_file_bytes'] ?? ArtifactNormalizer::DEFAULT_MAX_FILE_BYTES)));
        $maxTotal = min(ArtifactNormalizer::MAX_TOTAL_BYTES, max(1, (int) ($requested['max_total_bytes'] ?? ArtifactNormalizer::DEFAULT_MAX_TOTAL_BYTES)));
        $total = 0;
        foreach (is_array($artifact['files'] ?? null) ? $artifact['files'] : array() as $file) {
            if (!is_array($file) || !isset($file['payload_reference'])) continue;
            $reference = $this->payloadReference($file['payload_reference']);
            if ($reference['bytes'] > $maxFile) throw new \InvalidArgumentException('A payload reference exceeds the compiler per-file byte limit.');
            $total += $reference['bytes'];
            if ($total > $maxTotal) throw new \InvalidArgumentException('Payload references exceed the compiler aggregate byte limit.');
        }
    }

    /** @param mixed $reference @return array{schema:string,id:string,bytes:int,sha256:string} */
    private function payloadReference(mixed $reference): array
    {
        if (!is_array($reference) || 'blocks-engine/payload-reference/v1' !== ($reference['schema'] ?? null) || !is_string($reference['id'] ?? null) || '' === $reference['id'] || !is_int($reference['bytes'] ?? null) || $reference['bytes'] < 0 || !is_string($reference['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $reference['sha256'])) {
            throw new \InvalidArgumentException('A payload reference requires a schema, id, byte count, and sha256 hex digest.');
        }
        return array('schema' => $reference['schema'], 'id' => $reference['id'], 'bytes' => $reference['bytes'], 'sha256' => $reference['sha256']);
    }

    /** @param array{schema:string,id:string,bytes:int,sha256:string} $reference */
    private function readPayload(array $reference, PayloadReader $payloadReader): string
    {
        $content = $payloadReader->read($reference);
        if (strlen($content) !== $reference['bytes'] || !hash_equals($reference['sha256'], hash('sha256', $content))) {
            throw new \InvalidArgumentException('The payload reader returned bytes that do not match the payload reference.');
        }
        return $content;
    }

    /** @param array<string,mixed> $artifact @return array<string,mixed> */
    private function materializePlanArtifact(array $artifact, ?PayloadReader $payloadReader): array
    {
        foreach ($artifact['files'] as &$file) {
            if (!isset($file['payload_reference'])) continue;
            if (null === $payloadReader) throw new \InvalidArgumentException('Composition requires a payload reader for referenced staged payloads.');
            $reference = $this->payloadReference($file['payload_reference']);
            if ($this->isReferenceBackedBinary($file)) continue;
            $content = $this->readPayload($reference, $payloadReader);
            unset($file['payload_reference']);
            if (!empty($file['binary'])) {
                $file['content'] = '';
                $file['content_base64'] = base64_encode($content);
            } else {
                $file['content'] = $content;
            }
        }
        unset($file);
        return $artifact;
    }

    /** @return array<int,array<string,mixed>> */
    private function sharedReductionFiles(array $sharedPlan, ?PayloadReader $payloadReader): array
    {
        if (is_array($sharedPlan['shared_reduction']['files'] ?? null)) {
            return $sharedPlan['shared_reduction']['files'];
        }
        return $this->materializePlanArtifact($sharedPlan['artifact'], $payloadReader)['files'];
    }

    /** @param array<string,mixed> $file */
    private function isReferenceBackedBinary(array $file): bool
    {
        if (!isset($file['payload_reference'])) return false;
        $mime = strtolower((string) ($file['mime_type'] ?? $file['type'] ?? ''));
        if ('image/svg+xml' === $mime || str_ends_with(strtolower((string) ($file['path'] ?? '')), '.svg')) return false;
        $extension = strtolower(pathinfo((string) ($file['path'] ?? ''), PATHINFO_EXTENSION));
        return !str_starts_with($mime, 'text/') && !in_array($mime, array('application/javascript', 'application/json', 'application/ecmascript'), true) && !in_array($extension, array('css', 'html', 'htm', 'js', 'mjs', 'json', 'md', 'markdown', 'mdx', 'svg'), true);
    }

    /** @param array<string,mixed> $hashInput */
    private function planDigest(array $hashInput): string
    {
        return RuntimeDeclarations::hash($hashInput, self::MAX_PLAN_DIGEST_DEPTH);
    }

    /** @param array<string,mixed> $sharedPlan */
    private function assertSharedPlan(array $sharedPlan): void
    {
        if (($sharedPlan['schema'] ?? null) !== self::SHARED_PLAN_SCHEMA) {
            throw new \InvalidArgumentException('A staged shared plan must declare the staged shared plan schema.');
        }
        if (!is_array($sharedPlan['artifact'] ?? null) || !is_array($sharedPlan['artifact']['files'] ?? null)) {
            throw new \InvalidArgumentException('A staged shared plan requires its serialized artifact payload.');
        }
        if (isset($sharedPlan['shared_reduction'])) {
            $filesSource = $sharedPlan['shared_reduction']['files_source'] ?? null;
            $hasFiles = is_array($sharedPlan['shared_reduction']['files'] ?? null) || 'artifact' === $filesSource;
            if (!$hasFiles || !is_array($sharedPlan['shared_reduction']['component_facts'] ?? null) || !is_string($sharedPlan['shared_reduction_digest'] ?? null) || !hash_equals($this->planDigest($sharedPlan['shared_reduction']), $sharedPlan['shared_reduction_digest'])) {
                throw new \InvalidArgumentException('A staged shared plan contains an invalid shared reduction digest.');
            }
        }
        if (!$this->compatibleReceiptOptions($sharedPlan['compiler_options'] ?? null)) {
            throw new \InvalidArgumentException('A staged shared plan was prepared with incompatible compiler options.');
        }
        $this->assertPlanDigest(
            $this->sharedPlanDigestInput($sharedPlan),
            $sharedPlan['digest'] ?? null,
            'shared'
        );
    }

    /**
     * Per-plan validity only; cross-plan invariants (page-id uniqueness,
     * path collisions) are enforced by compose().
     *
     * @param array<string,mixed> $pagePlan
     * @param array<string,mixed> $sharedPlan
     */
    private function assertPagePlan(array $pagePlan, array $sharedPlan): void
    {
        if (($pagePlan['schema'] ?? null) !== self::PAGE_PLAN_SCHEMA) {
            throw new \InvalidArgumentException('A staged page plan must declare the staged page plan schema.');
        }
        if (!is_string($pagePlan['page_id'] ?? null) || '' === $pagePlan['page_id']) {
            throw new \InvalidArgumentException('A staged page plan requires a nonblank page id.');
        }
        if (($pagePlan['shared_digest'] ?? null) !== $sharedPlan['digest']) {
            throw new \InvalidArgumentException('A staged page plan must be bound to the supplied shared plan digest.');
        }
        if (!is_array($pagePlan['artifact']['files'] ?? null)) {
            throw new \InvalidArgumentException('A staged page plan requires its serialized artifact payload.');
        }
        if (!$this->compatibleReceiptOptions($pagePlan['compiler_options'] ?? null) || ($pagePlan['output_schema'] ?? null) !== TransformerResult::SCHEMA) {
            throw new \InvalidArgumentException('A staged page plan was prepared with incompatible compiler options or output schema.');
        }
        if (isset($pagePlan['compiled_documents']) && !in_array(($pagePlan['receipt_schema'] ?? null), array(self::PAGE_RECEIPT_SCHEMA, self::COMPILED_RECEIPT_SCHEMA, self::COMPACT_RECEIPT_SCHEMA), true)) {
            throw new \InvalidArgumentException('A compiled page plan requires the compiled page receipt schema.');
        }
        $this->assertPlanDigest(
            $this->pagePlanDigestInput($pagePlan),
            $pagePlan['digest'] ?? null,
            'page'
        );
    }

    /** @param array<string,mixed> $pagePlan @return array<string,mixed> */
    private function pagePlanDigestInput(array $pagePlan): array
    {
        $input = array('shared_digest' => $pagePlan['shared_digest'], 'page_id' => $pagePlan['page_id'], 'artifact' => $pagePlan['artifact']);
        if (array_key_exists('layout_geometry_proof', $pagePlan)) $input['layout_geometry_proof'] = $pagePlan['layout_geometry_proof'];
        if (isset($pagePlan['compiled_documents'])) {
            $input['receipt_schema'] = $pagePlan['receipt_schema'] ?? null;
            $input['compiled_documents'] = $pagePlan['compiled_documents'];
            $input['owned_document_paths'] = $pagePlan['owned_document_paths'] ?? null;
            $input['shared_reduction_digest'] = $pagePlan['shared_reduction_digest'] ?? null;
            if ($this->isTerminalReceiptSchema($pagePlan['receipt_schema'] ?? null)) $input['terminal_reduction'] = $pagePlan['terminal_reduction'] ?? null;
        }
        $input['compiler_options'] = $pagePlan['compiler_options'] ?? null;
        $input['output_schema'] = $pagePlan['output_schema'] ?? null;
        return $input;
    }

    /** @param array<string,mixed> $sharedPlan @return array<string,mixed> */
    private function sharedPlanDigestInput(array $sharedPlan): array
    {
        $input = array('artifact' => $sharedPlan['artifact']);
        $input['analysis'] = $sharedPlan['analysis'] ?? null;
        if (isset($sharedPlan['shared_reduction'])) {
            $input['shared_reduction'] = $sharedPlan['shared_reduction'];
            $input['shared_reduction_digest'] = $sharedPlan['shared_reduction_digest'] ?? null;
        }
        $input['compiler_options'] = $sharedPlan['compiler_options'] ?? null;
        return $input;
    }

    /** @return array<string,string> */
    private function receiptCompilerOptions(): array
    {
        return array(
            'compiled_page_schema' => self::COMPACT_RECEIPT_SCHEMA,
            'output_schema' => TransformerResult::SCHEMA,
        );
    }

    /** @param mixed $options */
    private function compatibleReceiptOptions(mixed $options): bool
    {
        return $options === $this->receiptCompilerOptions()
            || $options === array('compiled_page_schema' => self::COMPILED_RECEIPT_SCHEMA, 'output_schema' => TransformerResult::SCHEMA)
            || $options === array('compiled_page_schema' => self::PAGE_RECEIPT_SCHEMA, 'output_schema' => TransformerResult::SCHEMA);
    }

    private function isTerminalReceiptSchema(mixed $schema): bool
    {
        return in_array($schema, array(self::COMPILED_RECEIPT_SCHEMA, self::COMPACT_RECEIPT_SCHEMA), true);
    }

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private function sharedAnalysis(array $normalized, array $pageIds = array()): array
    {
        $stylesheets = array();
        $sources = array();
        foreach ($normalized['files'] as $file) {
            $path = (string) ($file['path'] ?? '');
            if ('' === $path) continue;
            $sources[] = array('path' => $path, 'kind' => (string) ($file['kind'] ?? ''), 'hash' => (string) ($file['provenance']['hash'] ?? ''));
            if ('css' === ($file['kind'] ?? null)) {
                $stylesheets[] = array('path' => $path, 'media' => (string) ($file['media'] ?? ''), 'hash' => (string) ($file['provenance']['hash'] ?? ''));
            }
        }
        sort($pageIds, SORT_STRING);
        return array('stylesheets' => $stylesheets, 'sources' => $sources, 'page_ids' => $pageIds);
    }

    /** @param array<string,mixed> $hashInput */
    private function assertPlanDigest(array $hashInput, mixed $digest, string $label): void
    {
        if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/', $digest)) {
            throw new \InvalidArgumentException(sprintf('A staged %s plan requires a sha256 hex digest.', $label));
        }
        if (!hash_equals($this->planDigest($hashInput), $digest)) {
            throw new \InvalidArgumentException(sprintf('The staged %s plan digest does not match its serialized artifact payload.', $label));
        }
    }

    /**
     * Composed plans must not collide on artifact paths: a silent
     * dedupe-rename during the final compile would ship files under an
     * identity no plan's digest ever covered. Uniqueness is checked on the
     * canonical path identity the final compile will use.
     *
     * @param array<int,array<string,mixed>> $files
     */
    private function assertUniqueComposedPaths(array $files): void
    {
        $seenPaths = array();
        foreach ($files as $file) {
            $path = ArtifactPath::safeRelativePath((string) ($file['path'] ?? ''));
            if ('' !== $path && isset($seenPaths[$path])) {
                throw new \InvalidArgumentException(sprintf('Composed staged plans collide on artifact path "%s".', $path));
            }
            $seenPaths[$path] = true;
        }
    }

    /** @param array<int,array<string,mixed>> $files @return array<int,string> */
    private function ownedHtmlPaths(array $files, string $pageId): array
    {
        $paths = array();
        foreach ($files as $file) {
            if ('html' === ($file['kind'] ?? null) && !$this->isTemplatePartFile($file) && $pageId === $this->fileOwnership($file)['id']) $paths[] = (string) $file['path'];
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @param array<int,array<string,mixed>> $files @return array<int,string> */
    private function ownedTransformablePaths(array $files, string $pageId): array
    {
        $paths = array();
        foreach ($files as $file) {
            if (in_array($file['kind'] ?? null, array('html', 'markdown', 'mdx'), true) && !$this->isTemplatePartFile($file) && $pageId === $this->fileOwnership($file)['id']) $paths[] = (string) $file['path'];
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @param array<int,array<string,mixed>> $rows @param array<int,string> $sourcePaths @return array<int,array<string,mixed>> */
    private static function sortedBySourcePaths(array $rows, array $sourcePaths, string $pathField = 'path'): array
    {
        if (array() === $sourcePaths) return 'path' === $pathField ? self::sortedByPath($rows) : array_values($rows);
        $order = array_flip($sourcePaths);
        $fallback = count($order) * 2;
        $decorated = array();
        foreach (array_values($rows) as $index => $row) {
            $path = (string) ($row[$pathField] ?? '');
            if (isset($order[$path])) {
                $rank = $order[$path] * 2;
            } else {
                $expansionSource = 'path' === $pathField ? ArtifactNormalizer::inlineExpansionSourcePath($row) : '';
                $rank = isset($order[$expansionSource]) ? $order[$expansionSource] * 2 + 1 : $fallback + $index;
            }
            $decorated[] = array('rank' => $rank, 'index' => $index, 'row' => $row);
        }
        usort($decorated, static fn(array $left, array $right): int => $left['rank'] <=> $right['rank'] ?: $left['index'] <=> $right['index']);
        return array_column($decorated, 'row');
    }

    /** @param array<string,array<string,mixed>> $rows @param array<int,string> $sourcePaths @return array<string,array<string,mixed>> */
    private static function orderedMapBySourcePaths(array $rows, array $sourcePaths): array
    {
        $ordered = array();
        foreach ($sourcePaths as $path) if (isset($rows[$path])) $ordered[$path] = $rows[$path];
        foreach ($rows as $path => $row) if (!isset($ordered[$path])) $ordered[$path] = $row;
        return $ordered;
    }

    /** @param array<string,mixed> $partition @param array<string,mixed> $artifact */
    private function compileSharedInlineShellReduction(array $partition, array $artifact): array
    {
        $files = array_merge($partition['shared'], ...array_values($partition['pages']));
        $files = self::sortedBySourcePaths($files, $partition['source_paths']);
        // Match whole compilation's linked-stylesheet preparation before
        // shared shell layout and presentation are classified, including its
        // selected fallback HTML when no requested entrypoint exists.
        $entry = $this->entryFile($files, $partition['entrypoints']);
        $entryPath = (string) ($entry['path'] ?? '');
        $files = $this->withStylesheetOccurrenceAssets((string) ($entry['content'] ?? ''), $entryPath, $files);
        $this->generatedAssetRoot = '.' === dirname($entryPath) ? '' : trim(dirname($entryPath), '/');
        $this->indexFiles($files);
        return $this->compileSharedInlineShells($files, $entryPath, (new CompanionPluginPayload())->blockNamespace($artifact));
    }
}
