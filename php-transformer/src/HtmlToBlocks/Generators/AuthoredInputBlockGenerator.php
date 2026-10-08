<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/** Builds the editable companion for browser-native input controls. */
final class AuthoredInputBlockGenerator
{
    public const LOCAL_NAME = 'authored-input';

    /** @return array<string,mixed> */
    public function blockJson(string $namespace): array
    {
        $attributes = array();
        foreach (array('type', 'id', 'name', 'form', 'value', 'placeholder', 'ariaLabel', 'className', 'style', 'min', 'max', 'step', 'label', 'labelMarkup', 'labelId', 'labelClassName', 'labelStyle') as $name) {
            $attributes[$name] = array('type' => 'string', 'default' => 'type' === $name ? 'text' : '');
        }
        foreach (array('required', 'disabled', 'readOnly', 'checked', 'valueDeclared', 'indeterminate', 'labelAfterControl') as $name) $attributes[$name] = array('type' => 'boolean', 'default' => false);
        $attributes['initialValue'] = array('type' => 'string');
        $attributes['initialChecked'] = array('type' => 'boolean');
        $attributes['dataAttributes'] = array('type' => 'object', 'default' => array());
        return array('apiVersion' => 3, 'name' => $namespace . '/' . self::LOCAL_NAME, 'title' => 'Input Field', 'category' => 'widgets',
            'description' => 'An editable native input field.', 'editorScript' => 'file:./index.js', 'viewScript' => 'file:./view.js',
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
        var output='<input';
        ['type','id','name','form','value','placeholder','ariaLabel','className','style','min','max','step'].forEach(function(key){
            if(key==='value'&&attrs.type==='file')return;
            if(attrs[key]||(key==='value'&&attrs.valueDeclared))output+=' '+({className:'class',ariaLabel:'aria-label'}[key]||key)+'="'+escapeAttribute(attrs[key])+'"';
        });
        output+=dataMarkup(attrs);
        ['required','disabled','readOnly','checked'].forEach(function(key){if(attrs[key])output+=' '+(key==='readOnly'?'readonly':key);});
        output+=controlState('input',attrs)+'>';
        if(!attrs.label)return output;
        var label=attrs.labelMarkup||escapeAttribute(attrs.label);
        return '<label'+(attrs.labelId?' id="'+escapeAttribute(attrs.labelId)+'"':'')+(attrs.labelClassName?' class="'+escapeAttribute(attrs.labelClassName)+'"':'')+(attrs.labelStyle?' style="'+escapeAttribute(attrs.labelStyle)+'"':'')+'>'+(attrs.labelAfterControl?output+label:label+output)+'</label>';
    }
    function edit(props){
        var attrs=props.attributes;
        function valueEdit(value){props.setAttributes({value:value,initialValue:value,valueDeclared:true});}
        function checkedEdit(checked){props.setAttributes({checked:checked,initialChecked:checked,indeterminate:false});}
        var input=createElement('input',Object.assign({type:attrs.type||'text',id:attrs.id||undefined,name:attrs.name||undefined,form:attrs.form||undefined,
            value:attrs.type==='file'?undefined:initialValue(attrs),checked:initialChecked(attrs),placeholder:attrs.placeholder||undefined,'aria-label':attrs.ariaLabel||undefined,
            className:attrs.className||undefined,style:styleObject(attrs.style),min:attrs.min||undefined,max:attrs.max||undefined,step:attrs.step||undefined,
            required:attrs.required,disabled:attrs.disabled,readOnly:attrs.readOnly,
            ref:function(node){if(node){if(attrs.type!=='file'){if(!attrs.valueDeclared&&!attrs.value)node.removeAttribute('value');else node.defaultValue=attrs.value||'';if(node.value!==initialValue(attrs))node.value=initialValue(attrs);}node.defaultChecked=!!attrs.checked;node.checked=initialChecked(attrs);node.indeterminate=!!attrs.indeterminate;}},
            onChange:function(event){if(['checkbox','radio'].includes(attrs.type))checkedEdit(event.target.checked);else if(attrs.type!=='file')valueEdit(event.target.value);}},safeData(attrs)));
        var labelContent=attrs.labelMarkup?createElement(RichText,{tagName:'span',value:attrs.labelMarkup,allowedFormats:['core/bold','core/italic','core/strikethrough','core/underline'],onChange:function(labelMarkup){props.setAttributes( { labelMarkup: labelMarkup } );}}):attrs.label;
        var field=attrs.label?createElement('label',{className:attrs.labelClassName||undefined,style:styleObject(attrs.labelStyle)},labelContent,input):input;
        var settings=[createElement(components.TextControl,{label:'Label',value:attrs.label||'',onChange:function(label){props.setAttributes( { label: label } );}}),
            createElement(components.TextControl,{label:'Field name',value:attrs.name||'',onChange:function(name){props.setAttributes({name:name});}}),
            createElement(components.TextControl,{label:'Placeholder',value:attrs.placeholder||'',onChange:function(placeholder){props.setAttributes({placeholder:placeholder});}}),
            createElement(components.SelectControl,{label:'Type',value:attrs.type||'text',options:['text','email','tel','number','search','checkbox','radio','hidden','submit','file'].map(function(type){return {label:type,value:type};}),onChange:function(type){props.setAttributes({type:type,initialValue:attrs.value||'',initialChecked:!!attrs.checked,indeterminate:false});}}),
            createElement(components.ToggleControl,{label:'Required',checked:!!attrs.required,onChange:function(required){props.setAttributes({required:required});}}),
            createElement(components.ToggleControl,{label:'Disabled',checked:!!attrs.disabled,onChange:function(disabled){props.setAttributes({disabled:disabled});}})];
        if(['checkbox','radio'].includes(attrs.type))settings.push(createElement(components.ToggleControl,{label:'Default checked',checked:!!attrs.checked,onChange:checkedEdit}));
        else if(attrs.type!=='file')settings.push(createElement(components.TextControl,{label:'Default value',value:attrs.value||'',onChange:valueEdit}));
        return createElement(element.Fragment,null,createElement(InspectorControls,null,createElement(components.PanelBody,{title:'Field settings'},...settings)),field);
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
        $html = '<input';
        foreach (array('type', 'id', 'name', 'form', 'value', 'placeholder', 'ariaLabel', 'className', 'style', 'min', 'max', 'step') as $key) {
            if ('value' === $key && 'file' === ($attrs['type'] ?? '')) continue;
            $value = (string) ($attrs[$key] ?? '');
            if ('' !== $value || ('value' === $key && !empty($attrs['valueDeclared']))) $html .= ' ' . (array('className' => 'class', 'ariaLabel' => 'aria-label')[$key] ?? $key) . '="' . $escape($value) . '"';
        }
        $html .= AuthoredControlState::dataMarkup($attrs);
        foreach (array('required', 'disabled', 'readOnly', 'checked') as $key) if (!empty($attrs[$key])) $html .= ' ' . ('readOnly' === $key ? 'readonly' : $key);
        $html .= AuthoredControlState::markup('input', $attrs) . '>';
        if (!empty($attrs['label'])) {
            $label = !empty($attrs['labelMarkup']) ? (string) $attrs['labelMarkup'] : $escape($attrs['label']);
            $html = '<label' . (!empty($attrs['labelId']) ? ' id="' . $escape($attrs['labelId']) . '"' : '') . (!empty($attrs['labelClassName']) ? ' class="' . $escape($attrs['labelClassName']) . '"' : '')
                . (!empty($attrs['labelStyle']) ? ' style="' . $escape($attrs['labelStyle']) . '"' : '') . '>' . (!empty($attrs['labelAfterControl']) ? $html . $label : $label . $html) . '</label>';
        }
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
