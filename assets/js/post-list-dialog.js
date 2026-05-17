( function () {
	'use strict';

	var __ = wp.i18n.__;

	var DEFAULT_WORDS  = 300;
	var WARNING_WORDS  = 500;
	var MAX_WORDS      = 700;
	var MIN_WORDS      = 100;
	var STEP_WORDS     = 50;

	function init() {
		var dialog  = buildDialog();
		var trigger = buildTriggerButton();
		if ( ! trigger ) return;
		wireEvents( dialog, trigger );
	}

	// ── Dialog ────────────────────────────────────────────────────────────────

	function buildDialog() {
		var el = document.createElement( 'dialog' );
		el.id  = 'wpa-dialog';

		el.innerHTML =
			'<div class="wpa-dialog__header">' +
				'<h2>' + __( 'Generate New Post with AI', 'wp-abilities-api-test' ) + '</h2>' +
				'<button type="button" class="wpa-dialog__close" aria-label="' +
					__( 'Close dialog', 'wp-abilities-api-test' ) + '">' +
					'<span aria-hidden="true">&times;</span>' +
				'</button>' +
			'</div>' +
			'<div class="wpa-dialog__body">' +
				'<label class="wpa-dialog__label" for="wpa-title">' +
					__( 'Post Title', 'wp-abilities-api-test' ) +
				'</label>' +
				'<input type="text" id="wpa-title" class="large-text"' +
					' placeholder="' + __( 'Enter a title…', 'wp-abilities-api-test' ) + '" />' +

				'<label class="wpa-dialog__label" for="wpa-notes">' +
					__( 'Notes (optional)', 'wp-abilities-api-test' ) +
				'</label>' +
				'<textarea id="wpa-notes" class="large-text" rows="3"' +
					' placeholder="' + __( 'Additional context for the AI…', 'wp-abilities-api-test' ) + '"></textarea>' +

				'<div class="wpa-dialog__length-row">' +
					'<label class="wpa-dialog__label" for="wpa-max-words">' +
						__( 'Post length', 'wp-abilities-api-test' ) +
					'</label>' +
					'<div class="wpa-dialog__slider-wrap">' +
						'<input type="range" id="wpa-max-words"' +
							' min="' + MIN_WORDS + '" max="' + MAX_WORDS + '"' +
							' step="' + STEP_WORDS + '" value="' + DEFAULT_WORDS + '" />' +
						'<span id="wpa-words-value" class="wpa-dialog__words-value">' +
							DEFAULT_WORDS + ' ' + __( 'words', 'wp-abilities-api-test' ) +
						'</span>' +
					'</div>' +
					'<p id="wpa-length-warning" class="wpa-dialog__length-warning" hidden>' +
						__( 'Longer posts take more time to generate and may time out.', 'wp-abilities-api-test' ) +
					'</p>' +
				'</div>' +

				'<div id="wpa-notice" class="wpa-dialog__notice" hidden></div>' +
			'</div>' +
			'<div class="wpa-dialog__footer">' +
				'<button type="button" class="button button-secondary" id="wpa-cancel">' +
					__( 'Cancel', 'wp-abilities-api-test' ) +
				'</button>' +
				'<button type="button" class="button button-primary" id="wpa-submit">' +
					__( 'Generate Post', 'wp-abilities-api-test' ) +
				'</button>' +
			'</div>';

		document.body.appendChild( el );
		return el;
	}

	// ── Trigger button ────────────────────────────────────────────────────────

	function buildTriggerButton() {
		var anchor = document.querySelector( '.page-title-action' );
		if ( ! anchor ) return null;

		var btn         = document.createElement( 'button' );
		btn.type        = 'button';
		btn.className   = 'page-title-action wpa-trigger';
		btn.textContent = __( 'Generate with AI', 'wp-abilities-api-test' );

		anchor.insertAdjacentElement( 'afterend', btn );
		return btn;
	}

	// ── Event wiring ──────────────────────────────────────────────────────────

	function wireEvents( dialog, trigger ) {
		var titleInput   = document.getElementById( 'wpa-title' );
		var notesInput   = document.getElementById( 'wpa-notes' );
		var wordsSlider  = document.getElementById( 'wpa-max-words' );
		var wordsValue   = document.getElementById( 'wpa-words-value' );
		var lengthWarn   = document.getElementById( 'wpa-length-warning' );
		var submitBtn    = document.getElementById( 'wpa-submit' );
		var cancelBtn    = document.getElementById( 'wpa-cancel' );
		var closeBtn     = dialog.querySelector( '.wpa-dialog__close' );
		var notice       = document.getElementById( 'wpa-notice' );

		// Live-update the word count label and toggle the warning.
		wordsSlider.addEventListener( 'input', function () {
			var val = parseInt( wordsSlider.value, 10 );
			wordsValue.textContent  = val + ' ' + __( 'words', 'wp-abilities-api-test' );
			lengthWarn.hidden       = val < WARNING_WORDS;
			wordsValue.className    = 'wpa-dialog__words-value' +
				( val >= WARNING_WORDS ? ' wpa-dialog__words-value--warn' : '' );
		} );

		function open() {
			titleInput.value  = '';
			notesInput.value  = '';
			wordsSlider.value = DEFAULT_WORDS;
			wordsValue.textContent = DEFAULT_WORDS + ' ' + __( 'words', 'wp-abilities-api-test' );
			wordsValue.className   = 'wpa-dialog__words-value';
			lengthWarn.hidden      = true;
			notice.hidden          = true;
			setSubmitting( false );
			dialog.showModal();
			titleInput.focus();
		}

		function close() {
			dialog.close();
		}

		function setSubmitting( busy ) {
			submitBtn.disabled    = busy;
			submitBtn.textContent = busy
				? __( 'Generating…', 'wp-abilities-api-test' )
				: __( 'Generate Post', 'wp-abilities-api-test' );
		}

		function showError( msg ) {
			notice.textContent = msg;
			notice.className   = 'wpa-dialog__notice wpa-dialog__notice--error';
			notice.hidden      = false;
		}

		function submit() {
			var title = titleInput.value.trim();
			if ( ! title ) {
				titleInput.focus();
				return;
			}

			setSubmitting( true );
			notice.hidden = true;

			wp.apiFetch( {
				path:   '/wp-abilities-test/v1/execute',
				method: 'POST',
				data:   {
					ability: 'wp-abilities-api-test/write-post',
					input:   {
						title:     title,
						notes:     notesInput.value.trim(),
						max_words: parseInt( wordsSlider.value, 10 ),
					},
				},
			} )
			.then( function ( res ) {
				window.location.href = res.post_url;
			} )
			.catch( function ( err ) {
				showError( err.message || __( 'An unexpected error occurred.', 'wp-abilities-api-test' ) );
				setSubmitting( false );
			} );
		}

		trigger.addEventListener( 'click', open );
		closeBtn.addEventListener( 'click', close );
		cancelBtn.addEventListener( 'click', close );
		submitBtn.addEventListener( 'click', submit );

		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) close();
		} );

		titleInput.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) submit();
		} );
	}

	// ── Boot ──────────────────────────────────────────────────────────────────

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
