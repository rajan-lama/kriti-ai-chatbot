/**
 * Editor side of the "Chatbot button" block. Plain JavaScript, no build step.
 * The button itself is rendered on the server.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;

	wp.blocks.registerBlockType( 'kriti-ai-chatbot/open-button', {
		edit: function ( props ) {
			var blockProps = wp.blockEditor.useBlockProps();

			return el(
				'div',
				blockProps,
				el( wp.blockEditor.InspectorControls, {}, el( wp.components.PanelBody, { title: __( 'Chatbot button', 'kriti-ai-chatbot' ) }, el( wp.components.TextControl, {
					label: __( 'Button text', 'kriti-ai-chatbot' ),
					value: props.attributes.label,
					onChange: function ( value ) {
						props.setAttributes( { label: value } );
					},
				} ) ) ),
				el( 'button', { type: 'button', className: 'wp-element-button', disabled: true }, props.attributes.label || __( 'Chat with us', 'kriti-ai-chatbot' ) )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
