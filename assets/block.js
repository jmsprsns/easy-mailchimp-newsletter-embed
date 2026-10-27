/**
 * "Mailchimp Signup Form" block: pick one of the forms from
 * Content Powered > Easy Mailchimp Newsletter Embed and it's drawn by the
 * server (Easy_Mailchimp_Newsletter_Embed_Render::render_block). Written
 * without JSX so there's no build step. window.emneBlock holds the forms list
 * and labels (see Easy_Mailchimp_Newsletter_Embed_Render::block_editor_data).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var components = wp.components;
	var blockEditor = wp.blockEditor || wp.editor;
	var ServerSideRender = wp.serverSideRender;
	var data = window.emneBlock || { forms: [], adminUrl: '', i18n: {} };
	var t = data.i18n || {};

	var icon = el(
		'svg',
		{ width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' },
		el( 'path', { d: 'M22 13V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h8', fill: 'none' } ),
		el( 'path', { d: 'm22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7', fill: 'none' } ),
		el( 'path', { d: 'M19 16v6', fill: 'none' } ),
		el( 'path', { d: 'M16 19h6', fill: 'none' } )
	);

	function options() {
		return [ { label: '— ' + ( t.choose || 'Choose a form' ) + ' —', value: 0 } ].concat(
			data.forms.map( function ( form ) {
				return { label: form.name + ' (#' + form.id + ')', value: form.id };
			} )
		);
	}

	wp.blocks.registerBlockType( 'easy-mailchimp-newsletter-embed/form', {
		apiVersion: 2,
		title: t.title || 'Mailchimp Signup Form',
		description: t.description || '',
		icon: icon,
		category: 'widgets',
		keywords: [ 'mailchimp', 'newsletter', 'subscribe', 'email', 'signup' ],
		attributes: {
			formId: { type: 'number', default: 0 },
		},
		supports: { html: false },

		edit: function ( props ) {
			var formId = props.attributes.formId || 0;
			var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps() : { className: props.className };
			var editUrl = data.adminUrl && formId ? data.adminUrl + '&tab=forms&edit=' + formId : '';

			var select = el( components.SelectControl, {
				label: t.form || 'Signup form',
				value: formId,
				options: options(),
				onChange: function ( value ) {
					props.setAttributes( { formId: parseInt( value, 10 ) || 0 } );
				},
			} );

			var inspector = el(
				blockEditor.InspectorControls,
				null,
				el(
					components.PanelBody,
					{ title: t.form || 'Signup form' },
					select,
					editUrl ? el( 'p', null, el( 'a', { href: editUrl, target: '_blank', rel: 'noopener noreferrer' }, t.edit || 'Edit this form' ) ) : null
				)
			);

			var body;

			if ( ! data.forms.length ) {
				body = el(
					components.Placeholder,
					{ icon: icon, label: t.title, instructions: t.none },
					data.adminUrl ? el( 'a', { className: 'components-button is-primary', href: data.adminUrl + '&tab=forms&edit=new' }, t.create || 'Create a form' ) : null
				);
			} else if ( ! formId ) {
				body = el( components.Placeholder, { icon: icon, label: t.title, instructions: t.pick }, select );
			} else {
				body = el(
					components.Disabled,
					null,
					el( ServerSideRender, { block: 'easy-mailchimp-newsletter-embed/form', attributes: { formId: formId } } )
				);
			}

			return el( 'div', blockProps, inspector, body );
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );
