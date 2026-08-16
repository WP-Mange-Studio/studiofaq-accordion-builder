/**
 * StudioFAQ - Admin Meta Box JS
 *
 * Handles:
 * - Reading post content from Gutenberg or Classic editor.
 * - Triggering AJAX generation of FAQs.
 * - Repeater field management (add, remove, reorder).
 *
 * @package StudioFAQ_Accordion_Builder
 */

(function ($) {
	'use strict';

	var StudioFAQAdmin = {

		rowIndexCounter: 0,

		init: function () {
			var $repeater = $('#studiofaq-repeater');

			// Set the row index counter to be higher than any existing row.
			this.rowIndexCounter = $repeater.find('.studiofaq-row').length;

			this.bindEvents();
			this.initSortable();
			this.initColorPickers();
			this.initLivePreview();
		},

		bindEvents: function () {
			$(document).on('click', '#studiofaq-generate-btn', this.handleGenerateClick.bind(this));
			$(document).on('click', '#studiofaq-add-manual-btn', this.handleAddManualClick.bind(this));
			$(document).on('click', '.studiofaq-remove-row', this.handleRemoveRow.bind(this));
			$(document).on('click', '.studiofaq-copy-btn', this.handleCopyShortcode.bind(this));
			$(document).on('click', '.studiofaq-tab', this.handleTabClick.bind(this));
		},

		/**
		 * Switch between the "Generate FAQs with AI" and "+ Add Manual FAQ"
		 * tabs: only the clicked tab's panel (its action button and helper
		 * text) is shown below the tab strip; the other panel is hidden.
		 * The FAQ repeater list itself stays visible regardless of which
		 * tab is active, since both actions add rows to the same list.
		 *
		 * @param {Event} e Click event from a .studiofaq-tab button.
		 */
		handleTabClick: function (e) {
			e.preventDefault();

			var $tab = $(e.currentTarget);
			var target = $tab.data('tab');

			if ($tab.hasClass('studiofaq-tab-active')) {
				return;
			}

			$('.studiofaq-tab')
				.removeClass('studiofaq-tab-active')
				.attr('aria-selected', 'false');
			$tab.addClass('studiofaq-tab-active').attr('aria-selected', 'true');

			$('.studiofaq-tab-panel')
				.removeClass('studiofaq-tab-panel-active')
				.hide();
			$('#studiofaq-panel-' + target)
				.addClass('studiofaq-tab-panel-active')
				.show();
		},

		/**
		 * Initialize the WordPress Color Picker (wp-color-picker) on every
		 * .studiofaq-color-field input — used both by the per-post
		 * "Display Settings" panel in the meta box and, separately, by the
		 * global Style & Branding fields on the settings page.
		 *
		 * The stock widget only toggles its own picker open/closed when its
		 * own swatch button is clicked — it does NOT close automatically on
		 * an outside click, and it does NOT close other pickers when a
		 * different one is opened. With several color fields on one page,
		 * that lets more than one picker end up open at once, and one can
		 * get stuck open. bindColorPickerCloseBehavior() below adds just
		 * that missing open/close coordination (no extra visible button —
		 * this is the widget's own "Select Color" toggle behaving the way
		 * a single-picker-open-at-a-time UI should).
		 *
		 * Also adds a title/aria-label on the swatch toggle button naming
		 * which field it belongs to (from data-color-label), since several
		 * color pickers sitting close together otherwise all look alike.
		 */
		initColorPickers: function () {
			var $fields = $('.studiofaq-color-field');

			if (!$fields.length || !$.fn.wpColorPicker) {
				return;
			}

			$fields.each(function () {
				var $field = $(this);
				var label = $field.data('color-label') || '';

				$field.wpColorPicker({
					defaultColor: $field.data('default-color') || false
				});

				if (label) {
					$field.closest('.wp-picker-container').find('.wp-color-result').attr({
						title: label,
						'aria-label': label
					});
				}
			});

			this.bindColorPickerCloseBehavior();
		},

		/**
		 * Coordinates opening/closing across every .studiofaq-color-field
		 * picker on the page, since the stock widget only manages its own
		 * open/closed state:
		 * 1. Clicking any picker's "Select Color" swatch closes every
		 *    other currently-open picker (so opening one always closes
		 *    the rest, instead of leaving several open at once).
		 * 2. Clicking anywhere outside all picker containers closes every
		 *    open picker.
		 * Bound once regardless of how many times initColorPickers() runs.
		 */
		bindColorPickerCloseBehavior: function () {
			if (this._colorCloseBound) {
				return;
			}
			this._colorCloseBound = true;

			$(document).on('click', '.wp-picker-container .wp-color-result', function () {
				StudioFAQAdmin.closeOtherPickers($(this).closest('.wp-picker-container'));
			});

			$(document).on('click', function (e) {
				if ($(e.target).closest('.wp-picker-container').length) {
					return;
				}
				StudioFAQAdmin.closeOtherPickers( null );
			});
		},

		/**
		 * Close every open .studiofaq-color-field picker except the one
		 * inside $exceptContainer (if given). Calls the official
		 * wpColorPicker 'close' method first, then also forces the DOM
		 * state closed directly as a defensive fallback, so the picker
		 * visually collapses even if the widget's internal state ever
		 * gets out of sync.
		 *
		 * @param {jQuery|null} $exceptContainer The .wp-picker-container to leave open, if any.
		 */
		closeOtherPickers: function ($exceptContainer) {
			$('.studiofaq-color-field').each(function () {
				var $field     = $(this);
				var $container = $field.closest('.wp-picker-container');

				if ($exceptContainer && $container.is($exceptContainer)) {
					return;
				}

				if (!$container.find('.wp-color-result').hasClass('wp-picker-open')) {
					return;
				}

				if ($.fn.wpColorPicker) {
					try {
						$field.wpColorPicker('close');
					} catch (err) {
						// Fall through to the manual close below regardless.
					}
				}

				$container
					.find('.wp-color-result')
					.removeClass('wp-picker-open')
					.attr('aria-expanded', 'false');
				$container.find('.wp-picker-holder').hide();
			});
		},

		/**
		 * Wire up the "Live Preview" panel in the meta box: re-renders on
		 * any change to the questions/answers, section title, heading tag,
		 * or colors, without requiring the post to be saved first.
		 */
		initLivePreview: function () {
			var $preview = $('#studiofaq-live-preview');
			if (!$preview.length) {
				return;
			}

			var debouncedRender = this.debounce(this.renderLivePreview.bind(this), 200);

			// Typing in a question/answer, the title, or a color hex field.
			$(document).on('input', '.studiofaq-question-input, .studiofaq-answer-input', debouncedRender);
			$(document).on('input change', 'input[name="studiofaq_settings[section_title]"]', debouncedRender);
			$(document).on('change', 'select[name="studiofaq_settings[heading_tag]"]', debouncedRender);
			// Covers both manual typing in the hex field and the color
			// picker's own 'change' event when a swatch/hue is picked or
			// the "Clear" button is used.
			$(document).on('input change', '.studiofaq-color-field', debouncedRender);

			// Accordion open/close inside the preview itself — mirrors the
			// same toggle behavior StudioFAQ_Accordion_Builder_Renderer prints on the front end.
			$(document).on('click', '#studiofaq-live-preview .studiofaq-accordion-trigger', function () {
				var $trigger = $(this);
				var $item = $trigger.closest('.studiofaq-accordion-item');
				var $panel = $item.find('.studiofaq-accordion-panel');
				var isOpen = $item.hasClass('is-open');

				$item.toggleClass('is-open', !isOpen);
				$trigger.attr('aria-expanded', !isOpen ? 'true' : 'false');

				if (!isOpen && $panel.length) {
					$panel.css('max-height', $panel[0].scrollHeight + 'px');
				} else if ($panel.length) {
					$panel.css('max-height', '');
				}
			});

			this.renderLivePreview();
		},

		/**
		 * Rebuild the "Live Preview" panel from the meta box's current
		 * in-browser state (unsaved edits included). Mirrors the markup
		 * and "override falls back to global default" logic that
		 * StudioFAQ_Accordion_Builder_Renderer uses on the real front end, so the preview
		 * matches what will actually be published once the post is saved.
		 */
		renderLivePreview: function () {
			var $preview = $('#studiofaq-live-preview');
			if (!$preview.length) {
				return;
			}

			var globalDefaults = (window.StudioFAQAccordionBuilder && StudioFAQAccordionBuilder.globalDefaults) || {
				sectionTitle: '',
				headingTag: 'h2',
				colors: {}
			};
			var allowedTags = ['h2', 'h3', 'h4', 'h5', 'h6'];

			// Collect non-empty FAQ rows from the repeater as it currently stands.
			var items = [];
			$('#studiofaq-repeater .studiofaq-row').each(function () {
				var $row = $(this);
				var question = $.trim($row.find('.studiofaq-question-input').val() || '');
				var answer = $.trim($row.find('.studiofaq-answer-input').val() || '');
				if (question === '' && answer === '') {
					return;
				}
				items.push({ question: question, answer: answer });
			});

			// Section title: per-post field, falling back to the site default.
			var title = $.trim($('input[name="studiofaq_settings[section_title]"]').val() || '');
			if (title === '') {
				title = globalDefaults.sectionTitle || '';
			}

			// Heading tag: per-post select, falling back to the site default, then h2.
			var headingTag = $('select[name="studiofaq_settings[heading_tag]"]').val() || '';
			if (allowedTags.indexOf(headingTag) === -1) {
				headingTag = allowedTags.indexOf(globalDefaults.headingTag) !== -1 ? globalDefaults.headingTag : 'h2';
			}

			// Colors: per-post override (if a valid hex value) falling back to the site default.
			var cssVarMap = {
				faq_header_bg_color: '--faq-header-bg',
				faq_header_bg_hover_color: '--faq-header-bg-hover',
				faq_header_text_color: '--faq-header-text',
				faq_header_text_active_color: '--faq-header-text-active',
				faq_content_text_color: '--faq-content-text',
				faq_content_bg_color: '--faq-content-bg',
				faq_border_color: '--faq-border',
				faq_icon_color: '--faq-icon'
			};
			var hexPattern = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;
			var styleAttr = '';

			$.each(cssVarMap, function (optionKey, cssVar) {
				var value = $.trim($('input[name="studiofaq_settings[colors][' + optionKey + ']"]').val() || '');
				if (!hexPattern.test(value)) {
					value = (globalDefaults.colors && globalDefaults.colors[optionKey]) || '';
				}
				if (value) {
					styleAttr += cssVar + ':' + value + ';';
				}
			});

			var emptyMessage = (window.StudioFAQAccordionBuilder && StudioFAQAccordionBuilder.i18n && StudioFAQAccordionBuilder.i18n.previewEmpty) ||
				'Add a question and answer above to see a live preview here.';

			var html = '';

			if (title !== '') {
				html += '<' + headingTag + ' class="studiofaq-main-title">' + this.escapeHtml(title) + '</' + headingTag + '>';
			}

			html += '<div class="studiofaq-wrapper studiofaq-style-accordion" style="' + this.escapeAttr(styleAttr) + '">';

			if (!items.length) {
				html += '<p class="studiofaq-empty-preview">' + this.escapeHtml(emptyMessage) + '</p>';
			} else {
				$.each(items, function (i, item) {
					var itemId = 'studiofaq-preview-item-' + i;
					html += '<div class="studiofaq-accordion-item">';
					html += '<button type="button" class="studiofaq-accordion-trigger" aria-expanded="false" aria-controls="' + itemId + '-panel">';
					html += '<span class="studiofaq-question-text">' + StudioFAQAdmin.escapeHtml(item.question) + '</span>';
					html += '<span class="studiofaq-accordion-icon" aria-hidden="true">+</span>';
					html += '</button>';
					html += '<div class="studiofaq-accordion-panel" id="' + itemId + '-panel">';
					html += '<div class="studiofaq-accordion-panel-inner">' + StudioFAQAdmin.escapeHtml(item.answer).replace(/\n/g, '<br>') + '</div>';
					html += '</div>';
					html += '</div>';
				});
			}

			html += '</div>';

			$preview.html(html);
		},

		/**
		 * Return a debounced version of fn that only runs `delay` ms after
		 * the last call — used so the live preview doesn't rebuild on
		 * every single keystroke.
		 *
		 * @param {Function} fn
		 * @param {number} delay
		 * @return {Function}
		 */
		debounce: function (fn, delay) {
			var timer = null;
			return function () {
				var args = arguments;
				var ctx = this;
				clearTimeout(timer);
				timer = setTimeout(function () {
					fn.apply(ctx, args);
				}, delay);
			};
		},

		/**
		 * Escape a string for safe insertion as HTML text content.
		 *
		 * @param {*} str
		 * @return {string}
		 */
		escapeHtml: function (str) {
			return $('<div>').text(str === null || str === undefined ? '' : String(str)).html();
		},

		/**
		 * Escape a string for safe insertion inside an HTML attribute value.
		 *
		 * @param {*} str
		 * @return {string}
		 */
		escapeAttr: function (str) {
			return String(str === null || str === undefined ? '' : str)
				.replace(/&/g, '&amp;')
				.replace(/"/g, '&quot;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;');
		},

		initSortable: function () {
			var $repeater = $('#studiofaq-repeater');
			if ($repeater.length && $.fn.sortable) {
				$repeater.sortable({
					handle: '.studiofaq-drag-handle',
					axis: 'y',
					placeholder: 'studiofaq-row-placeholder',
					forcePlaceholderSize: true,
					update: function () {
						StudioFAQAdmin.renderLivePreview();
					}
				});
			}
		},

		/**
		 * Get the current post content from either Gutenberg or the Classic editor.
		 *
		 * @return {string} Post content (may include HTML).
		 */
		getEditorContent: function () {
			// Try Gutenberg (Block Editor) first.
			if (window.wp && wp.data && typeof wp.data.select === 'function') {
				try {
					var editorSelect = wp.data.select('core/editor');
					if (editorSelect && typeof editorSelect.getEditedPostContent === 'function') {
						var content = editorSelect.getEditedPostContent();
						if (content && content.length) {
							return content;
						}
					}
				} catch (err) {
					// Fall through to classic editor handling below.
				}
			}

			// Try Classic Editor with TinyMCE active.
			if (window.tinymce) {
				var editor = window.tinymce.get('content');
				if (editor && !editor.isHidden()) {
					return editor.getContent();
				}
			}

			// Fallback: plain textarea (Classic editor in text/HTML mode).
			var $textarea = $('#content');
			if ($textarea.length) {
				return $textarea.val();
			}

			return '';
		},

		handleGenerateClick: function (e) {
			e.preventDefault();

			var $btn = $('#studiofaq-generate-btn');
			var $spinner = $('#studiofaq-spinner');
			var $notice = $('#studiofaq-notice');
			var content = this.getEditorContent();

			var textOnly = $('<div>').html(content).text().trim();

			if (!textOnly.length) {
				this.showNotice(StudioFAQAccordionBuilder.i18n.noContent, 'error');
				return;
			}

			$btn.prop('disabled', true).text(StudioFAQAccordionBuilder.i18n.generating);
			$spinner.addClass('is-active');
			$notice.hide();

			$.ajax({
				url: StudioFAQAccordionBuilder.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'studiofaq_generate',
					nonce: StudioFAQAccordionBuilder.nonce,
					post_id: StudioFAQAccordionBuilder.postId,
					content: content
				}
			}).done(function (response) {
				if (response && response.success && response.data && response.data.items) {
					StudioFAQAdmin.appendItems(response.data.items);
					StudioFAQAdmin.showNotice(
						response.data.items.length + ' FAQs generated successfully.',
						'success'
					);
				} else {
					var message = (response && response.data && response.data.message)
						? response.data.message
						: StudioFAQAccordionBuilder.i18n.error;
					StudioFAQAdmin.showNotice(message, 'error');
				}
			}).fail(function () {
				StudioFAQAdmin.showNotice(StudioFAQAccordionBuilder.i18n.error, 'error');
			}).always(function () {
				$btn.prop('disabled', false).text(StudioFAQAccordionBuilder.i18n.generateBtn);
				$spinner.removeClass('is-active');
			});
		},

		handleAddManualClick: function (e) {
			e.preventDefault();
			this.appendItems([{ question: '', answer: '' }]);
		},

		/**
		 * Append one or more Q&A items to the repeater UI.
		 *
		 * @param {Array} items Array of { question, answer } objects.
		 */
		appendItems: function (items) {
			var $repeater = $('#studiofaq-repeater');
			var templateHtml = document.getElementById('studiofaq-row-template').innerHTML;

			items.forEach(function (item) {
				var index = StudioFAQAdmin.rowIndexCounter++;
				var rowHtml = templateHtml.split('__INDEX__').join(index);
				var $row = $(rowHtml);

				$row.find('.studiofaq-question-input').val(item.question || '');
				$row.find('.studiofaq-answer-input').val(item.answer || '');

				$repeater.append($row);
			});

			this.initSortable();
			this.renderLivePreview();
		},

		handleRemoveRow: function (e) {
			e.preventDefault();
			var $row = $(e.currentTarget).closest('.studiofaq-row');

			if (window.confirm(StudioFAQAccordionBuilder.i18n.confirmDelete)) {
				$row.remove();
				this.renderLivePreview();
			}
		},

		handleCopyShortcode: function (e) {
			e.preventDefault();
			var $btn = $(e.currentTarget);
			var shortcode = $btn.siblings('.studiofaq-shortcode-copy').data('shortcode');

			if (navigator.clipboard && shortcode) {
				navigator.clipboard.writeText(shortcode).then(function () {
					var originalText = $btn.text();
					$btn.text('Copied!');
					setTimeout(function () {
						$btn.text(originalText);
					}, 1500);
				});
			}
		},

		showNotice: function (message, type) {
			var $notice = $('#studiofaq-notice');
			$notice
				.removeClass('notice-success notice-error')
				.addClass(type === 'error' ? 'notice-error' : 'notice-success')
				.text(message)
				.show();
		}
	};

	$(function () {
		if ($('#studiofaq-metabox').length) {
			// Full meta box context: repeater, sortable, generate button, and colors.
			StudioFAQAdmin.init();
		} else if ($('.studiofaq-color-field').length) {
			// Settings page: only the color pickers are relevant here.
			StudioFAQAdmin.initColorPickers();
		}
	});

}(jQuery));
