<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds the editable companion for browser-native textarea controls. */
final class AuthoredTextareaBlockGenerator
{
    public const LOCAL_NAME = 'authored-textarea';

    /** @return array<string,mixed> */
    public function blockJson(string $namespace): array
    {
        $attributes = array();
        foreach (array('id', 'name', 'form', 'value', 'placeholder', 'ariaLabel', 'className', 'style', 'rows', 'cols', 'maxLength', 'label', 'labelMarkup', 'labelClassName', 'labelStyle') as $name) $attributes[$name] = array('type' => 'string', 'default' => '');
        foreach (array('required', 'disabled', 'readOnly') as $name) $attributes[$name] = array('type' => 'boolean', 'default' => false);
        $attributes['initialValue'] = array('type' => 'string');
        $attributes['dataAttributes'] = array('type' => 'object', 'default' => array());
        return array('apiVersion' => 3, 'name' => $namespace . '/' . self::LOCAL_NAME, 'title' => 'Textarea Field', 'category' => 'widgets',
            'description' => 'An editable native textarea field.', 'editorScript' => 'file:./index.js', 'viewScript' => 'file:./view.js',
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
    function markup(attrs){
        var output='<textarea';
        ['id','name','form','placeholder','ariaLabel','className','style','rows','cols','maxLength'].forEach(function(key){if(attrs[key])output+=' '+({className:'class',ariaLabel:'aria-label',maxLength:'maxlength'}[key]||key)+'="'+escapeAttribute(attrs[key])+'"';});
        ['required','disabled','readOnly'].forEach(function(key){if(attrs[key])output+=' '+(key==='readOnly'?'readonly':key);});
        var value=attrs.value||'';
        output+=dataMarkup(attrs)+controlState('textarea',attrs)+'>'+(value.startsWith('\n')?'\n':'')+escapeAttribute(value)+'</textarea>';
        return attrs.label?'<label'+(attrs.labelClassName?' class="'+escapeAttribute(attrs.labelClassName)+'"':'')+(attrs.labelStyle?' style="'+escapeAttribute(attrs.labelStyle)+'"':'')+'>'+(attrs.labelMarkup||escapeAttribute(attrs.label))+output+'</label>':output;
    }
    function edit(props){
        var attrs=props.attributes;
        function valueEdit(value){props.setAttributes({value:value,initialValue:value});}
        var textarea=createElement('textarea',Object.assign({id:attrs.id||undefined,name:attrs.name||undefined,form:attrs.form||undefined,value:initialValue(attrs),placeholder:attrs.placeholder||undefined,
            'aria-label':attrs.ariaLabel||undefined,className:attrs.className||undefined,style:styleObject(attrs.style),rows:attrs.rows||undefined,cols:attrs.cols||undefined,maxLength:attrs.maxLength||undefined,
            required:attrs.required,disabled:attrs.disabled,readOnly:attrs.readOnly,ref:function(node){if(node){node.defaultValue=attrs.value||'';node.value=initialValue(attrs);}},onChange:function(event){valueEdit(event.target.value);}},safeData(attrs)));
        var labelContent=attrs.labelMarkup?createElement(RichText,{tagName:'span',value:attrs.labelMarkup,allowedFormats:['core/bold','core/italic','core/strikethrough','core/underline'],onChange:function(labelMarkup){props.setAttributes( { labelMarkup: labelMarkup } );}}):attrs.label;
        var field=attrs.label?createElement('label',{className:attrs.labelClassName||undefined,style:styleObject(attrs.labelStyle)},labelContent,textarea):textarea;
        return createElement(element.Fragment,null,createElement(InspectorControls,null,createElement(components.PanelBody,{title:'Field settings'},
            createElement(components.TextControl,{label:'Label',value:attrs.label||'',onChange:function(label){props.setAttributes( { label: label } );}}),
            createElement(components.TextControl,{label:'Field name',value:attrs.name||'',onChange:function(name){props.setAttributes({name:name});}}),
            createElement(components.TextControl,{label:'Placeholder',value:attrs.placeholder||'',onChange:function(placeholder){props.setAttributes({placeholder:placeholder});}}),
            createElement(components.TextControl,{label:'Rows',value:attrs.rows||'',onChange:function(rows){props.setAttributes({rows:rows});}}),
            createElement(components.TextareaControl,{label:'Default value',value:attrs.value||'',onChange:valueEdit}),
            createElement(components.ToggleControl,{label:'Required',checked:!!attrs.required,onChange:function(required){props.setAttributes({required:required});}}),
            createElement(components.ToggleControl,{label:'Disabled',checked:!!attrs.disabled,onChange:function(disabled){props.setAttributes({disabled:disabled});}}))),field);
    }
    function save(props){return createElement(element.RawHTML,null,markup(props.attributes));}
    blocks.registerBlockType('__BLOCK_NAME__',{attributes:attributes,supports:{html:false},edit:edit,save:save});
})(window.wp.blocks,window.wp.blockEditor,window.wp.components,window.wp.element);
JS;
        $script = AuthoredFormLabelEditorScript::synchronize($script);
        return array('index.js' => str_replace(array('__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__', '__CONTROL_HELPERS__'),
            array($namespace . '/' . self::LOCAL_NAME, json_encode($this->blockJson($namespace)['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), AuthoredControlState::editorHelpers()), $script));
    }

    /** @param array<string,mixed> $attrs */
    public function markup(array $attrs): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<textarea';
        foreach (array('id', 'name', 'form', 'placeholder', 'ariaLabel', 'className', 'style', 'rows', 'cols', 'maxLength') as $key) if ('' !== (string) ($attrs[$key] ?? '')) {
            $html .= ' ' . (array('className' => 'class', 'ariaLabel' => 'aria-label', 'maxLength' => 'maxlength')[$key] ?? $key) . '="' . $escape($attrs[$key]) . '"';
        }
        foreach (array('required', 'disabled', 'readOnly') as $key) if (!empty($attrs[$key])) $html .= ' ' . ('readOnly' === $key ? 'readonly' : $key);
        $value = $attrs['value'] ?? '';
        $html .= AuthoredControlState::dataMarkup($attrs) . AuthoredControlState::markup('textarea', $attrs) . '>' . (str_starts_with($value, "\n") ? "\n" : '') . $escape($value) . '</textarea>';
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
