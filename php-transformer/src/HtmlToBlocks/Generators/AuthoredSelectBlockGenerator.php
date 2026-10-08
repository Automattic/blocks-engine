<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds the editable companion for browser-native select controls. */
final class AuthoredSelectBlockGenerator
{
    public const LOCAL_NAME = 'authored-select';

    /** @return array<string,mixed> */
    public function blockJson(string $namespace): array
    {
        $attributes = array();
        foreach (array('id', 'name', 'form', 'size', 'ariaLabel', 'placeholder', 'className', 'style', 'label', 'labelMarkup', 'labelClassName', 'labelStyle', 'selectedSummary') as $name) $attributes[$name] = array('type' => 'string', 'default' => '');
        foreach (array('required', 'disabled', 'multiple') as $name) $attributes[$name] = array('type' => 'boolean', 'default' => false);
        $attributes['options'] = array('type' => 'array', 'default' => array());
        $attributes['dataAttributes'] = array('type' => 'object', 'default' => array());
        return array('apiVersion' => 3, 'name' => $namespace . '/' . self::LOCAL_NAME, 'title' => 'Select Field', 'category' => 'widgets',
            'description' => 'An editable native select field.', 'editorScript' => 'file:./index.js', 'viewScript' => 'file:./view.js', 'style' => 'file:./style.css',
            'attributes' => $attributes, 'supports' => array('html' => false));
    }

    /** @return array<string,string> */
    public function assets(string $namespace): array
    {
        $script = <<<'JS'
(function(blocks,blockEditor,components,element){
    var createElement=element.createElement,RichText=blockEditor.RichText,InspectorControls=blockEditor.InspectorControls;
    var attributes=__BLOCK_ATTRIBUTES__;
    function escapeAttribute(value){return String(value||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
__CONTROL_HELPERS__
    function styleObject(value){return value?String(value).split(';').reduce(function(output,declaration){var separator=declaration.indexOf(':');if(separator<1)return output;var name=declaration.slice(0,separator).trim();output[name.indexOf('--')===0?name:name.replace(/-([a-z])/g,function(_,letter){return letter.toUpperCase();})]=declaration.slice(separator+1).trim();return output;},{}):undefined;}
    function optionMarkup(options){
        var output='',group=0;
        (options||[]).forEach(function(option){
            if(!option||(!Object.prototype.hasOwnProperty.call(option,'initialSelected')&&!String(option.label||'').trim()))return;
            var next=option.group?option.group.index:0;
            if(next!==group){if(group)output+='</optgroup>';if(next)output+='<optgroup label="'+escapeAttribute(option.group.label)+'"'+(option.group.disabled?' disabled':'')+'>';group=next;}
            var value=Object.prototype.hasOwnProperty.call(option,'value')?option.value:option.label;
            output+='<option'+(option.valueDeclared===false?'':' value="'+escapeAttribute(value)+'"')+(option.selected?' selected':'')+(option.disabled?' disabled':'')+'>'+escapeAttribute(option.label)+'</option>';
        });
        return output+(group?'</optgroup>':'');
    }
    function markup(attrs){
        var output='<select';
        ['id','name','form','size','ariaLabel','placeholder','className','style'].forEach(function(key){if(attrs[key])output+=' '+({className:'class',ariaLabel:'aria-label'}[key]||key)+'="'+escapeAttribute(attrs[key])+'"';});
        ['required','disabled','multiple'].forEach(function(key){if(attrs[key])output+=' '+key;});
        output+=dataMarkup(attrs)+controlState('select',attrs)+'>'+optionMarkup(attrs.options)+'</select>';
        return attrs.label?'<label'+(attrs.labelClassName?' class="'+escapeAttribute(attrs.labelClassName)+'"':'')+(attrs.labelStyle?' style="'+escapeAttribute(attrs.labelStyle)+'"':'')+'>'+(attrs.labelMarkup||escapeAttribute(attrs.label))+output+'</label>':output;
    }
    function optionText(options){return (options||[]).map(function(option){return String(option.value||'')+'|'+String(option.label||'')+(option.selected?'|selected':'')+(option.disabled?'|disabled':'');}).join('\n');}
    function parseOptions(value,previous){return String(value||'').split(/\n/).map(function(line,index){var parts=line.split('|');if(!parts[1])return null;var selected=parts[2]==='selected'||parts[3]==='selected';return Object.assign({},(previous||[])[index],{value:parts[0],valueDeclared:true,label:parts[1],selected:selected,initialSelected:selected,disabled:parts[2]==='disabled'||parts[3]==='disabled'});}).filter(Boolean);}
    function edit(props){
        var attrs=props.attributes;
        var select=createElement('select',{id:attrs.id||undefined,name:attrs.name||undefined,form:attrs.form||undefined,className:attrs.className||undefined,style:styleObject(attrs.style),multiple:attrs.multiple,disabled:attrs.disabled,required:attrs.required,
            dangerouslySetInnerHTML:{__html:optionMarkup(attrs.options)},ref:function(node){if(!node||!(attrs.options||[]).some(function(option){return Object.prototype.hasOwnProperty.call(option,'initialSelected');}))return;(attrs.options||[]).forEach(function(option,index){if(node.options[index])node.options[index].defaultSelected=!!option.selected;});node.selectedIndex=-1;(attrs.options||[]).forEach(function(option,index){if(node.options[index])node.options[index].selected=Object.prototype.hasOwnProperty.call(option,'initialSelected')?option.initialSelected:!!option.selected;});},
            onChange:function(event){var node=event.target;props.setAttributes({options:(attrs.options||[]).map(function(option,index){var selected=!!node.options[index].selected;return Object.assign({},option,{selected:selected,initialSelected:selected});})});}});
        var labelContent=attrs.labelMarkup?createElement(RichText,{tagName:'span',value:attrs.labelMarkup,allowedFormats:['core/bold','core/italic','core/strikethrough','core/underline'],onChange:function(labelMarkup){props.setAttributes( { labelMarkup: labelMarkup } );}}):attrs.label;
        var field=attrs.label?createElement('label',{className:attrs.labelClassName||undefined,style:styleObject(attrs.labelStyle)},labelContent,select):select;
        return createElement(element.Fragment,null,createElement(InspectorControls,null,createElement(components.PanelBody,{title:'Select settings'},
            createElement(components.TextControl,{label:'Label',value:attrs.label||'',onChange:function(label){props.setAttributes( { label: label } );}}),
            createElement(components.TextControl,{label:'Field name',value:attrs.name||'',onChange:function(name){props.setAttributes({name:name});}}),
            createElement(components.TextControl,{label:'Placeholder',value:attrs.placeholder||'',onChange:function(placeholder){props.setAttributes({placeholder:placeholder});}}),
            createElement(components.TextareaControl,{label:'Options',help:'One per line: value|label|selected|disabled',value:optionText(attrs.options),onChange:function(value){props.setAttributes({options:parseOptions(value,attrs.options)});}}))),field);
    }
    function save(props){return createElement(element.RawHTML,null,markup(props.attributes));}
    blocks.registerBlockType('__BLOCK_NAME__',{attributes:attributes,supports:{html:false},edit:edit,save:save});
})(window.wp.blocks,window.wp.blockEditor,window.wp.components,window.wp.element);
JS;
        $script = AuthoredFormLabelEditorScript::synchronize($script);
        return array('index.js' => str_replace(array('__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__', '__CONTROL_HELPERS__'),
            array($namespace . '/' . self::LOCAL_NAME, json_encode($this->blockJson($namespace)['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), AuthoredControlState::editorHelpers()), $script),
            'style.css' => '.wp-block-group.blocks-engine-authored-select-wrapper{display:contents}');
    }

    /** @param array<string,mixed> $attrs */
    public function markup(array $attrs): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<select';
        foreach (array('id', 'name', 'form', 'size', 'ariaLabel', 'placeholder', 'className', 'style') as $key) if ('' !== (string) ($attrs[$key] ?? '')) {
            $html .= ' ' . (array('className' => 'class', 'ariaLabel' => 'aria-label')[$key] ?? $key) . '="' . $escape($attrs[$key]) . '"';
        }
        foreach (array('required', 'disabled', 'multiple') as $key) if (!empty($attrs[$key])) $html .= ' ' . $key;
        $html .= AuthoredControlState::dataMarkup($attrs) . AuthoredControlState::markup('select', $attrs) . '>';
        $group = 0;
        foreach ($attrs['options'] ?? array() as $option) {
            if (!is_array($option) || (!array_key_exists('initialSelected', $option) && '' === trim((string) ($option['label'] ?? '')))) continue;
            $next = $option['group']['index'] ?? 0;
            if ($next !== $group) {
                if ($group) $html .= '</optgroup>';
                if ($next) $html .= '<optgroup label="' . $escape($option['group']['label']) . '"' . (!empty($option['group']['disabled']) ? ' disabled' : '') . '>';
                $group = $next;
            }
            $value = $option['value'] ?? $option['label'];
            $html .= '<option' . (false === ($option['valueDeclared'] ?? true) ? '' : ' value="' . $escape($value) . '"')
                . (!empty($option['selected']) ? ' selected' : '') . (!empty($option['disabled']) ? ' disabled' : '') . '>' . $escape($option['label']) . '</option>';
        }
        if ($group) $html .= '</optgroup>';
        $html .= '</select>';
        if (!empty($attrs['label'])) $html = '<label' . (!empty($attrs['labelClassName']) ? ' class="' . $escape($attrs['labelClassName']) . '"' : '')
            . (!empty($attrs['labelStyle']) ? ' style="' . $escape($attrs['labelStyle']) . '"' : '') . '>' . ($attrs['labelMarkup'] ?? '') . (empty($attrs['labelMarkup']) ? $escape($attrs['label']) : '') . $html . '</label>';
        return $html;
    }

    /** @return array<string,mixed> */
    public function definition(string $namespace): array
    {
        return array('name' => self::LOCAL_NAME, 'block_json' => $this->blockJson($namespace),
            'script_dependencies' => array('index.js' => array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-rich-text')),
            'assets' => $this->assets($namespace), 'view_js' => AuthoredControlState::viewScript());
    }
}
