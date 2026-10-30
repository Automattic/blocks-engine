<?php
declare(strict_types=1);

/** Build state-owned probe evidence for focused projection tests. */
function captured_components(array $evidence): array
{
    return array_map(static function (array $probe): array {
        $kind = match ($probe['kind'] ?? 'dialog') {
            'gallery' => 'carousel',
            'selectable-set' => 'tabs',
            'typed-search' => 'filter',
            'choice-group' => 'choice-group',
            'disclosure' => 'disclosure',
            default => in_array($probe['dialog']['role'] ?? '', array('listbox', 'menu'), true) ? $probe['dialog']['role'] : 'dialog',
        };
        $selector = $probe['gallery']['inline']['selector'] ?? $probe['set']['selector'] ?? $probe['choiceGroup']['group']['selector'] ?? $probe['trigger']['selector'] ?? '#surface';
        return array(
            'selector' => $selector,
            'kind' => $kind,
            'initial' => 'closed',
            'states' => array(
                array('id' => 'closed', 'html' => ''),
                array('id' => 'open', 'html' => $probe['dialog']['html'] ?? '', 'evidence' => $probe),
            ),
            'transitions' => array(array('from' => 'closed', 'to' => 'open', 'trigger' => array('selector' => $probe['trigger']['selector'] ?? $selector, 'action' => 'click'))),
            'status' => 'captured',
        );
    }, $evidence);
}
