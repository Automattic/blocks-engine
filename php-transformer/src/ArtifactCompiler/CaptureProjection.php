<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

/** The capture-time projections shared by inline and staged compilation. */
final class CaptureProjection
{
    /**
     * @param array<int,array<string,mixed>> $files
     * @return array{files:array<int,array<string,mixed>>,captured_dialogs:array<string,mixed>}
     */
    public function project(array $files): array
    {
        $runtimeOwned = (new RuntimeOwnedRegionProjector())->project($files);
        $dialogs = (new CapturedDialogProjector())->project($runtimeOwned['files']);
        $collections = (new CapturedCollectionProjector())->project($dialogs['files']);
        $selectableSets = (new CapturedSelectableSetProjector())->project($collections['files'], $collections['consumed_selectable_bindings'] ?? array());
        $choiceGroups = (new CapturedChoiceGroupProjector())->project($selectableSets['files']);
        $scrollStates = (new ScrollStateProjector())->project($choiceGroups['files']);
        $files = (new RouteStateProjector())->project($scrollStates['files'])['files'];
        // Keep evidence bounded: projected files belong only in the artifact.
        // Selectable-set and choice-group counts must not inflate dialog counts.
        $report = array(
            'diagnostics' => CapturedInteractionReport::mergeDiagnostics($runtimeOwned['diagnostics'], $dialogs['diagnostics'], $collections['diagnostics'], $selectableSets['diagnostics'], $choiceGroups['diagnostics'], $scrollStates['diagnostics']),
            'projected_count' => $dialogs['projected_count'] + $scrollStates['projected_count'],
            'native_runtime_replacements' => array_merge(
                $dialogs['native_runtime_replacements'] ?? array(),
                $selectableSets['native_runtime_replacements'] ?? array(),
                $collections['superseded_runtime_scripts'] ?? array()
            ),
        );
        if (0 < $selectableSets['projected_count']) $report['projected_selectable_set_count'] = $selectableSets['projected_count'];
        if (0 < $choiceGroups['projected_count']) $report['projected_choice_group_count'] = $choiceGroups['projected_count'];
        return array('files' => $files, 'captured_dialogs' => $report);
    }
}
