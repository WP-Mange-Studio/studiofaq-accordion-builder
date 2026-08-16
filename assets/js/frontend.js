/**
 * StudioFAQ - Front-end Accordion Toggle
 *
 * Handles expand/collapse behavior for the accordion display style.
 * Delegated on `document` so it works no matter how many
 * `.studiofaq-wrapper` instances (shortcode or block) are on the page,
 * including ones injected after this script runs.
 *
 * @package StudioFAQ
 */

document.addEventListener( 'click', function ( e ) {
	var trigger = e.target.closest( '.studiofaq-accordion-trigger' );
	if ( ! trigger ) {
		return;
	}

	var item = trigger.closest( '.studiofaq-accordion-item' );
	if ( ! item ) {
		return;
	}

	var panel  = item.querySelector( '.studiofaq-accordion-panel' );
	var isOpen = item.classList.contains( 'is-open' );

	item.classList.toggle( 'is-open', ! isOpen );
	trigger.setAttribute( 'aria-expanded', ! isOpen ? 'true' : 'false' );

	if ( panel ) {
		if ( ! isOpen ) {
			panel.style.maxHeight = panel.scrollHeight + 'px';
		} else {
			panel.style.maxHeight = null;
		}
	}
} );
