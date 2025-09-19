(function(wp){
  if (!wp || !wp.blocks) return;
  const el = wp.element.createElement;
  const __ = wp.i18n.__;
  const { TextControl } = wp.components;
  const InspectorControls = wp.blockEditor ? wp.blockEditor.InspectorControls : wp.editor.InspectorControls;

  wp.blocks.registerBlockType('wpmps/subscribe-button', {
    title: __('MP Subscribe Button','wp-mp-subscriptions'),
    icon: 'money',
    category: 'widgets',
    attributes: { plan_id: {type:'string'}, label:{type:'string'}, class:{type:'string'} },
    edit: function(props){
      const attrs = props.attributes;
      return [
        el(InspectorControls, {},
          el(TextControl, {label: __('Plan ID','wp-mp-subscriptions'), value: attrs.plan_id||'', onChange: v=>props.setAttributes({plan_id:v})}),
          el(TextControl, {label: __('Label','wp-mp-subscriptions'), value: attrs.label||'', onChange: v=>props.setAttributes({label:v})}),
          el(TextControl, {label: __('CSS class','wp-mp-subscriptions'), value: attrs.class||'', onChange: v=>props.setAttributes({class:v})})
        ),
        el('p', {}, __('Botón de suscripción (previsualización).','wp-mp-subscriptions')),
        el('code', {}, '[mp_subscribe plan_id="'+(attrs.plan_id||'')+'" label="'+(attrs.label||'Suscribirme')+'" class="'+(attrs.class||'wp-mps-btn')+'"]')
      ];
    },
    save: function(){ return null; }
  });
})(window.wp);
