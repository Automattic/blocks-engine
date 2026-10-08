<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\NativeControlState;

/** Shared property codec/view asset for existing authored controls, not a registry. */
final class AuthoredControlState
{
    public const ATTRIBUTE = 'data-blocks-engine-control-state';

    /** @param array<string,mixed> $attrs */
    public static function markup(string $kind, array $attrs): string
    {
        $state = self::state($kind, $attrs);
        return null === $state ? '' : ' ' . self::ATTRIBUTE . '="' . htmlspecialchars(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }

    /** @param array<string,mixed> $attrs */
    public static function dataMarkup(array $attrs): string
    {
        $data = $attrs['dataAttributes'] ?? array(); ksort($data); $html = '';
        foreach ($data as $name => $value) if (preg_match('/^data-(?!wp-)[a-z0-9_.:-]+$/', $name)
            && !in_array($name, array(NativeControlState::ATTRIBUTE, self::ATTRIBUTE), true)) {
            $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    /** @param array<string,mixed> $attrs @return array<string,mixed>|null */
    public static function state(string $kind, array $attrs): ?array
    {
        if ('select' === $kind) {
            $options = $attrs['options'] ?? array();
            if (!array_filter($options, static fn (array $option): bool => array_key_exists('initialSelected', $option))) return null;
            return array('version' => 1, 'kind' => 'select', 'options' => array_map(static fn (array $option): array => array(
                'selected' => $option['initialSelected'] ?? !empty($option['selected']), 'defaultSelected' => !empty($option['selected'])), $options));
        }
        if (!array_key_exists('initialValue', $attrs) && !array_key_exists('initialChecked', $attrs) && empty($attrs['indeterminate'])) return null;
        if ('textarea' === $kind) return array('version' => 1, 'kind' => 'textarea', 'value' => $attrs['initialValue'] ?? ($attrs['value'] ?? ''), 'defaultValue' => $attrs['value'] ?? '');
        $type = NativeControlState::inputType($attrs['type'] ?? 'text');
        if ('file' === $type) return array('version' => 1, 'kind' => 'file');
        return array('version' => 1, 'kind' => 'input', 'type' => $type, 'value' => $attrs['initialValue'] ?? ($attrs['value'] ?? ''),
            'defaultValue' => $attrs['value'] ?? '', 'checked' => $attrs['initialChecked'] ?? !empty($attrs['checked']),
            'defaultChecked' => !empty($attrs['checked']), 'indeterminate' => !empty($attrs['indeterminate']));
    }

    public static function viewScript(): string
    {
        return <<<'JS'
(function(){
    var key=Symbol.for('blocks-engine/authored-control-state/v1');
    if(window[key]){window[key]();return;}
    var mounted=new WeakSet();
    function keys(state,names){return state&&typeof state==='object'&&!Array.isArray(state)&&Object.keys(state).length===names.length&&names.every(function(name){return Object.prototype.hasOwnProperty.call(state,name);});}
    function mount(){
        var inputs=[];
        document.querySelectorAll('input[data-blocks-engine-control-state],select[data-blocks-engine-control-state],textarea[data-blocks-engine-control-state]').forEach(function(control){
            if(mounted.has(control))return;
            var state;try{state=JSON.parse(control.getAttribute('data-blocks-engine-control-state'));}catch(error){return;}
            if(!state||state.version!==1)return;
            if(control instanceof HTMLInputElement&&control.type!=='file'&&state.kind==='input'&&state.type===control.type&&keys(state,['version','kind','type','value','defaultValue','checked','defaultChecked','indeterminate'])&&typeof state.value==='string'&&typeof state.defaultValue==='string'&&typeof state.checked==='boolean'&&typeof state.defaultChecked==='boolean'&&typeof state.indeterminate==='boolean'){
                if(control.defaultValue!==state.defaultValue)control.defaultValue=state.defaultValue;
                if(control.defaultChecked!==state.defaultChecked)control.defaultChecked=state.defaultChecked;
                if(control.value!==state.value)control.value=state.value;
                inputs.push({control:control,state:state});
            }else if(control instanceof HTMLTextAreaElement&&state.kind==='textarea'&&keys(state,['version','kind','value','defaultValue'])&&typeof state.value==='string'&&typeof state.defaultValue==='string'){
                if(control.defaultValue!==state.defaultValue)control.defaultValue=state.defaultValue;
                control.value=state.value;
            }else if(control instanceof HTMLSelectElement&&state.kind==='select'&&keys(state,['version','kind','options'])&&Array.isArray(state.options)&&state.options.length===control.options.length&&state.options.every(function(option){return keys(option,['selected','defaultSelected'])&&typeof option.selected==='boolean'&&typeof option.defaultSelected==='boolean';})&&(control.multiple||state.options.filter(function(option){return option.selected;}).length<=1)){
                state.options.forEach(function(option,index){if(control.options[index].defaultSelected!==option.defaultSelected)control.options[index].defaultSelected=option.defaultSelected;});
                control.selectedIndex=-1;
                state.options.forEach(function(option,index){control.options[index].selected=option.selected;});
            }else if(!(control instanceof HTMLInputElement&&control.type==='file'&&state.kind==='file'&&keys(state,['version','kind'])))return;
            mounted.add(control);
        });
        inputs.forEach(function(item){item.control.checked=false;});
        inputs.forEach(function(item){item.control.checked=item.state.checked;item.control.indeterminate=item.state.indeterminate;});
    }
    window[key]=mount;
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();
JS;
    }

    /** Shared serialization helper in the three registered editors. */
    public static function editorHelpers(): string
    {
        return <<<'JS'
    function initialValue(attrs){return Object.prototype.hasOwnProperty.call(attrs,'initialValue')?attrs.initialValue:(attrs.value||'');}
    function initialChecked(attrs){return Object.prototype.hasOwnProperty.call(attrs,'initialChecked')?attrs.initialChecked:!!attrs.checked;}
    function controlState(kind,attrs){
        if(kind==='select'){
            if(!(attrs.options||[]).some(function(option){return Object.prototype.hasOwnProperty.call(option,'initialSelected');}))return '';
            return stateAttribute({version:1,kind:kind,options:(attrs.options||[]).map(function(option){return {selected:Object.prototype.hasOwnProperty.call(option,'initialSelected')?option.initialSelected:!!option.selected,defaultSelected:!!option.selected};})});
        }
        if(!Object.prototype.hasOwnProperty.call(attrs,'initialValue')&&!Object.prototype.hasOwnProperty.call(attrs,'initialChecked')&&!attrs.indeterminate)return '';
        if(kind==='textarea')return stateAttribute({version:1,kind:kind,value:initialValue(attrs),defaultValue:attrs.value||''});
        var type=String(attrs.type||'text').toLowerCase();
        if(type==='file')return stateAttribute({version:1,kind:'file'});
        if(!['button','checkbox','color','date','datetime-local','email','hidden','image','month','number','password','radio','range','reset','search','submit','tel','text','time','url','week'].includes(type))type='text';
        return stateAttribute({version:1,kind:kind,type:type,value:initialValue(attrs),defaultValue:attrs.value||'',checked:initialChecked(attrs),defaultChecked:!!attrs.checked,indeterminate:!!attrs.indeterminate});
    }
    function stateAttribute(state){return ' data-blocks-engine-control-state="'+escapeAttribute(JSON.stringify(state))+'"';}
    function safeData(attrs){return Object.keys(attrs.dataAttributes||{}).reduce(function(output,name){if(/^data-(?!wp-)[a-z0-9_.:-]+$/.test(name)&&!['data-dla-native-control-state','data-blocks-engine-control-state'].includes(name))output[name]=attrs.dataAttributes[name];return output;},{});}
    function dataMarkup(attrs){return Object.keys(safeData(attrs)).sort().map(function(name){return ' '+name+'="'+escapeAttribute(attrs.dataAttributes[name])+'"';}).join('');}
JS;
    }
}
