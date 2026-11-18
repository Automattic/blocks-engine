<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

/** Restores captured native control properties independently of block conversion. */
final class AuthoredControlStateScript
{
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
}
