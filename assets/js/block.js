/**
 * StudioFAQ - Gutenberg Block
 *
 * Registers a dynamic block that live-previews the FAQs saved for the
 * current post/page (via the StudioFAQ Builder meta box) and lets
 * the user pick a display style. Actual markup is rendered server-side
 * by StudioFAQ_Accordion_Builder_Block::render_block(), previewed here via ServerSideRender.
 *
 * @package StudioFAQ_Accordion_Builder
 */

(function (blocks, element, blockEditor, components, i18n, serverSideRender) {
	'use strict';

	var el = element.createElement;
	var useEffect = element.useEffect;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ServerSideRender = serverSideRender;

	var i18nStrings = (window.StudioFAQAccordionBuilderBlock && window.StudioFAQAccordionBuilderBlock.i18n) || {};

	blocks.registerBlockType('studiofaq-accordion-builder/faq-block', {
		apiVersion: 2,
		title: i18nStrings.blockTitle || 'StudioFAQ',
		description: i18nStrings.blockDescription || 'Display AI-generated FAQs for this post or page, with optional semantic FAQ markup.',
		icon: 'editor-help',
		category: 'widgets',
		supports: {
			html: false,
			align: ['wide', 'full']
		},
		attributes: {
			style: {
				type: 'string',
				default: ''
			},
			postId: {
				type: 'number',
				default: 0
			},
			sectionTitle: {
				type: 'string',
				default: ''
			},
			headingTag: {
				type: 'string',
				default: ''
			}
		},
		// Declaring this makes WordPress pass the ID of the post currently being
		// edited into props.context.postId — the same mechanism core blocks like
		// "Post Title" and "Post Content" use. It's the most reliable source of
		// the current post ID available to a block's edit() function.
		usesContext: ['postId'],

		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps ? useBlockProps() : {};

			var contextPostId = (props.context && props.context.postId) ? props.context.postId : 0;

			// Persist the resolved post ID onto the block's own attributes so it's
			// serialized into the post content (e.g. wp:studiofaq-accordion-builder/faq-block
			// {"postId":123}). This gives the front-end render_callback a reliable
			// fallback even in edge cases where block context isn't available
			// (e.g. certain caching/REST setups), without ever depending solely
			// on get_the_ID() global state.
			useEffect(function () {
				if (contextPostId && contextPostId !== attributes.postId) {
					setAttributes({ postId: contextPostId });
				}
			}, [contextPostId]);

			var effectivePostId = attributes.postId || contextPostId;

			var styleOptions = [
				{ label: i18nStrings.styleDefault || 'Site Default', value: '' },
				{ label: i18nStrings.styleAccordion || 'Accordion', value: 'accordion' },
				{ label: i18nStrings.styleCards || 'Minimal Cards', value: 'cards' },
				{ label: i18nStrings.styleList || 'Clean List', value: 'list' }
			];

			var headingTagOptions = [
				{ label: i18nStrings.headingTagDefault || 'Use Default', value: '' },
				{ label: 'H2', value: 'h2' },
				{ label: 'H3', value: 'h3' },
				{ label: 'H4', value: 'h4' },
				{ label: 'H5', value: 'h5' },
				{ label: 'H6', value: 'h6' }
			];

			var inspectorControls = el(
				InspectorControls,
				{ key: 'inspector' },
				el(
					PanelBody,
					{ title: i18nStrings.settingsPanel || 'FAQ Settings', initialOpen: true },
					el(SelectControl, {
						label: i18nStrings.styleLabel || 'FAQ Style',
						value: attributes.style,
						options: styleOptions,
						onChange: function (value) {
							setAttributes({ style: value });
						}
					}),
					el(
						'p',
						{ className: 'studiofaq-block-hint' },
						i18nStrings.metaBoxHint || 'FAQ content is managed in the "StudioFAQ Builder" meta box below the editor.'
					)
				),
				el(
					PanelBody,
					{ title: i18nStrings.titlePanel || 'Section Title', initialOpen: false },
					el(TextControl, {
						label: i18nStrings.sectionTitleLabel || 'FAQ Section Title',
						value: attributes.sectionTitle,
						help: i18nStrings.sectionTitleHelp || 'Leave blank to use the site default (or the per-post override) from the meta box below.',
						onChange: function (value) {
							setAttributes({ sectionTitle: value });
						}
					}),
					el(SelectControl, {
						label: i18nStrings.headingTagLabel || 'Title Heading Tag',
						value: attributes.headingTag,
						options: headingTagOptions,
						onChange: function (value) {
							setAttributes({ headingTag: value });
						}
					}),
					el(
						'p',
						{ className: 'studiofaq-block-hint' },
						i18nStrings.colorsHint || 'Accordion colors can be customized in the "Display Settings" panel of the StudioFAQ Builder meta box, or set as site-wide defaults on the StudioFAQ → Settings page.'
					)
				)
			);

			var preview;

			if (!effectivePostId) {
				// No resolvable post ID yet (e.g. a brand-new, still-unsaved post
				// in some editor states) — show a clear placeholder instead of
				// silently rendering nothing, which is what prompted this fix.
				preview = el(
					'div',
					Object.assign({}, blockProps, { className: (blockProps.className || '') + ' studiofaq-block-editor-preview' }),
					el(
						'p',
						{ className: 'studiofaq-empty-preview' },
						__('Save or publish this post to preview the FAQ block.', 'studiofaq-accordion-builder')
					)
				);
			} else {
				preview = el(
					'div',
					Object.assign({}, blockProps, { className: (blockProps.className || '') + ' studiofaq-block-editor-preview' }),
					el(ServerSideRender, {
						block: 'studiofaq-accordion-builder/faq-block',
						attributes: Object.assign({}, attributes, { postId: effectivePostId })
					})
				);
			}

			return [inspectorControls, preview];
		},

		// Dynamic block: rendering is handled entirely server-side via render_callback.
		save: function () {
			return null;
		}
	});

}(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n,
	window.wp.serverSideRender
));
