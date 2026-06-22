( function ( $ ) {
	'use strict';

	function adminConfig() {
		if ( typeof gfOdooTemplatePage !== 'undefined' ) {
			return gfOdooTemplatePage;
		}
		return typeof gfOdooAdmin !== 'undefined' ? gfOdooAdmin : null;
	}

	function post( action, data ) {
		var cfg = adminConfig();
		if ( ! cfg ) {
			return $.Deferred().reject().promise();
		}

		return $.post( cfg.ajaxUrl, $.extend( { action: action, nonce: cfg.odooNonce }, data ) );
	}

	function __( text ) {
		return text;
	}

	function collectFieldMappingValue( $el ) {
		if ( $el.hasClass( 'gf-odoo-gf-field-select' ) ) {
			var $opt = $el.find( 'option:selected' );
			return {
				field_id: $opt.val() || '',
				field_label: $opt.data( 'fieldLabel' ) || '',
			};
		}

		if ( $el.hasClass( 'gf-odoo-gf-field-id-fallback' ) ) {
			return {
				field_id: $el.val() || '',
				field_label: '',
			};
		}

		return $el.val();
	}

	function getRowValueInput( $row, mode, key ) {
		var settingName = '_gform_setting_' + key + '_value';
		var $named = $row
			.find( '[name="' + settingName + '"]' )
			.not( '.gf-odoo-readonly-value-hidden' );

		if ( $named.length ) {
			return $named.first();
		}

		return $row
			.find( '[data-setting-name="' + settingName + '"]' )
			.filter( function () {
				return (
					$( this ).closest( '[data-mode-panel]' ).data( 'mode-panel' ) ===
					mode
				);
			} )
			.first();
	}

	function collectFeedMetaFromForm( $scope ) {
		var meta = {};

		$scope.find( '.gf-odoo-crm-field-row' ).each( function () {
			var $row = $( this );
			var key = $row.data( 'key' );

			if ( ! key ) {
				return;
			}

			var mode =
				$row.find( '.gf-odoo-mode-input' ).val() ||
				$row.find( '[name="_gform_setting_' + key + '_mode"]' ).val() ||
				'off';

			meta[ key + '_mode' ] = mode;

			if ( mode === 'off' || mode === 'auto' ) {
				meta[ key + '_value' ] = '';
				return;
			}

			var $valueEl = getRowValueInput( $row, mode, key );

			if ( $valueEl.length ) {
				meta[ key + '_value' ] = collectFieldMappingValue( $valueEl );
			}
		} );

		return meta;
	}

	function buildFieldSelectOptions( fields, selectedId ) {
		var cfg = adminConfig();
		var html =
			'<option value="">' +
			( cfg && cfg.selectField ? cfg.selectField : '— Select a field —' ) +
			'</option>';

		var hasSelected = false;

		( fields || [] ).forEach( function ( choice ) {
			var value = String( choice.value || '' );
			if ( ! value ) {
				return;
			}
			var label = String( choice.label || '' );
			var shortLabel = label.replace( /\s*\(field\s+\d+\)\s*$/i, '' );
			var isSelected = String( selectedId ) === value;

			if ( isSelected ) {
				hasSelected = true;
			}

			html +=
				'<option value="' +
				value +
				'" data-field-label="' +
				shortLabel.replace( /"/g, '&quot;' ) +
				'"' +
				( isSelected ? ' selected' : '' ) +
				'>' +
				label +
				'</option>';
		} );

		if ( selectedId && ! hasSelected ) {
			html +=
				'<option value="' +
				String( selectedId ) +
				'" selected>' +
				String( selectedId ) +
				'</option>';
		}

		return html;
	}

	function updateTemplateFieldSelects( fields ) {
		$( '#gf-odoo-template-fields .gf-odoo-gf-field-select' ).each( function () {
			var $select = $( this );
			var current = $select.val();
			$select.html( buildFieldSelectOptions( fields, current ) );

			if ( current && ! $select.val() ) {
				$select.val( '' );
			}
		} );
	}

	function replaceLegacyFallbackInputs() {
		$( '#gf-odoo-template-fields .gf-odoo-gf-field-id-fallback' ).each( function () {
			var $input = $( this );
			var name = $input.attr( 'name' );
			var value = $input.val() || '';
			var $select = $(
				'<select class="gf-odoo-gf-field-select gf-odoo-select medium gf-odoo-gf-field-select--needs-sample"></select>'
			);
			$select.attr( 'name', name ).html( buildFieldSelectOptions( [], value ) );
			$input.replaceWith( $select );
		} );
	}

	function markSelectsNeedSampleForm() {
		$( '#gf-odoo-template-fields .gf-odoo-gf-field-select' ).each( function () {
			var $select = $( this );
			var current = $select.val() || '';
			$select.addClass( 'gf-odoo-gf-field-select--needs-sample' );
			$select.html( buildFieldSelectOptions( [], current ) );
		} );
	}

	function toggleSampleFormNotice( formId ) {
		var $notice = $( '#gf-odoo-sample-form-notice' );
		if ( ! $notice.length ) {
			return;
		}
		if ( formId ) {
			$notice.attr( 'hidden', 'hidden' );
		} else {
			$notice.removeAttr( 'hidden' );
		}
	}

	function applySampleFormFields( fields ) {
		replaceLegacyFallbackInputs();
		if ( ! fields || ! fields.length ) {
			return;
		}
		$( '#gf-odoo-template-fields .gf-odoo-gf-field-select' ).each( function () {
			$( this ).removeClass( 'gf-odoo-gf-field-select--needs-sample' );
		} );
		updateTemplateFieldSelects( fields );
		toggleSampleFormNotice( parseInt( $( '#gf-odoo-sample-form' ).val(), 10 ) || 0 );
	}

	function loadSampleFormFields( formId ) {
		var $cfg = $( '#gf-odoo-template-fields' );
		if ( ! $cfg.length ) {
			return;
		}

		$cfg.addClass( 'is-loading' );

		if ( ! formId ) {
			markSelectsNeedSampleForm();
			$cfg.removeClass( 'is-loading' );
			return;
		}

		var cfg = adminConfig();

		post( 'gf_odoo_get_sample_form_fields', { form_id: formId } )
			.done( function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					window.alert(
						( res && res.data && res.data.message ) ||
							( cfg && cfg.sampleFieldsError ) ||
							'Could not load form fields.'
					);
					return;
				}
				var fields = res.data.fields || [];
				if ( ! fields.length ) {
					window.alert( ( cfg && cfg.sampleFieldsEmpty ) || 'No fields found in that form.' );
					return;
				}
				applySampleFormFields( fields );
			} )
			.fail( function () {
				window.alert( ( cfg && cfg.sampleFieldsError ) || 'Could not load form fields.' );
			} )
			.always( function () {
				$cfg.removeClass( 'is-loading' );
			} );
	}

	function collectOverrides( $wrap ) {
		var overrides = {};
		$wrap.find( '.gf-odoo-crm-field-row.gf-odoo-field--override' ).each( function () {
			var $row = $( this );
			var key = $row.data( 'key' );
			if ( ! key ) {
				return;
			}
			var mode = $row.find( '.gf-odoo-mode-input' ).val();
			var $value = getRowValueInput( $row, mode, key );

			overrides[ key + '_mode' ] = mode;
			if ( $value.length ) {
				overrides[ key + '_value' ] = collectFieldMappingValue( $value );
			}
		} );
		return overrides;
	}

	function enableRowEdit( $row ) {
		var key = $row.data( 'key' );
		var $mapLabel = $row.find( '.gf-odoo-linked-map-label' ).clone();

		$row.removeClass( 'gf-odoo-field--readonly' ).addClass( 'gf-odoo-field--override' );
		$row.find( '.gf-odoo-readonly-value-hidden' ).remove();
		$row.find( '.gf-odoo-mode-tab' ).prop( 'disabled', false );
		$row.find( 'select, input, textarea' ).prop( 'disabled', false );

		var $templateCol = $row.find( '.gf-odoo-crm-field-row__template' );
		$templateCol.empty();
		if ( $mapLabel.length ) {
			$templateCol.append( $mapLabel );
		}
		$templateCol.append(
			'<span class="gf-odoo-override-badge">' +
				( adminConfig().overrideLabel || 'Override' ) +
				'</span> <button type="button" class="button-link gf-odoo-remove-override" data-meta-key="' +
				key +
				'_value">× ' +
				( adminConfig().removeOverrideLabel || 'Remove override' ) +
				'</button>'
		);
	}

	/* --- Template link + remap modal --- */

	var remapState = {
		templateId: 0,
		templateName: '',
		formId: 0,
		formTitle: '',
		module: 'crm',
		mode: '',
		data: null,
		fieldRemaps: {},
	};

	function $modal() {
		return $( '#gf-odoo-template-remap-modal' );
	}

	function closeRemapModal() {
		$modal().attr( 'hidden', true ).attr( 'aria-hidden', 'true' );
	}

	function openRemapModal() {
		$modal().removeAttr( 'hidden' ).attr( 'aria-hidden', 'false' );
	}

	function getTemplateNames() {
		var $block = $( '#gf-odoo-feed-template' );
		var raw = $block.data( 'templateNames' );
		if ( typeof raw === 'string' ) {
			try {
				return JSON.parse( raw );
			} catch ( e ) {
				return {};
			}
		}
		return raw || {};
	}

	function fieldSelectHtml( choices, selected, extraClass ) {
		return (
			'<select class="gf-odoo-select gf-odoo-remap-field-select ' +
			( extraClass || '' ) +
			'">' +
			buildFieldSelectOptions( choices, selected ) +
			'</select>'
		);
	}

	function renderStepChoose() {
		var cfg = adminConfig();
		var count = remapState.data ? remapState.data.field_mapping_count : 0;

		return (
			'<h3 id="gf-odoo-remap-title">' +
			( cfg.remapTitle || 'Link template to form' ) +
			'</h3>' +
			'<p>' +
			( cfg.remapIntro || 'Linking' ) +
			' <strong>' +
			remapState.formTitle +
			'</strong> ' +
			( cfg.remapTo || 'to template' ) +
			' <strong>' +
			remapState.templateName +
			'</strong></p>' +
			'<p>' +
			sprintf(
				cfg.remapFieldCount || 'This template has %d "From field" mappings.',
				count
			) +
			'</p>' +
			'<p>' +
			( cfg.remapHow || 'How would you like to map the fields?' ) +
			'</p>' +
			'<div class="gf-odoo-remap-actions">' +
			'<button type="button" class="' +
			btnClass( 'primary' ) +
			' gf-odoo-remap-auto">' +
			( cfg.remapAuto || 'Auto-match by label' ) +
			'</button> ' +
			'<button type="button" class="' +
			btnClass( 'secondary' ) +
			' gf-odoo-remap-manual">' +
			( cfg.remapManual || 'Map manually' ) +
			'</button> ' +
			'<button type="button" class="' +
			btnClass( 'ghost' ) +
			' gf-odoo-remap-cancel">' +
			( cfg.remapCancel || 'Cancel' ) +
			'</button></div>'
		);
	}

	function sprintf( template, value ) {
		return String( template ).replace( '%d', String( value ) );
	}

	function btnClass( variant ) {
		return 'button gf-odoo-btn gf-odoo-btn-' + variant;
	}

	function showLinkMessage( message, isError ) {
		window.alert( message );
	}

	function handleTemplateLinkResponse( res ) {
		var cfg = adminConfig() || {};

		if ( ! res || ! res.success ) {
			var err =
				res && res.data && res.data.message
					? res.data.message
					: cfg.templateLinkFailed || 'Could not link the template.';
			showLinkMessage( err, true );
			return;
		}

		if ( res.data && res.data.pending ) {
			showLinkMessage(
				res.data.message || cfg.templateLinkPending || '',
				false
			);
			closeRemapModal();
			var $save = $( '#gform-settings input[type="submit"], #gform-settings .gform_settings_save button' ).first();
			if ( $save.length ) {
				$save.trigger( 'focus' );
			}
			return;
		}

		closeRemapModal();
		window.location.reload();
	}

	function methodLabel( method ) {
		var cfg = adminConfig();
		if ( method === 'id' ) {
			return cfg.remapSameId || 'same ID';
		}
		if ( method === 'label' ) {
			return cfg.remapByLabel || 'matched by label';
		}
		return '';
	}

	function renderAutoResult() {
		var cfg = adminConfig();
		var remap = remapState.data.remap;
		var choices = remapState.data.field_choices || [];
		var html = '';

		html +=
			'<h3>' + ( cfg.remapAutoTitle || 'Automatic field matching' ) + '</h3>';
		html += '<p class="gf-odoo-hint">' + ( remapState.data.summary || '' ) + '</p>';

		var matchedCount =
			Object.keys( remap.identical || {} ).length +
			Object.keys( remap.matched || {} ).length;

		html +=
			'<p><strong>' +
			sprintf( cfg.remapMatchedHeading || 'Automatically matched (%d):', matchedCount ) +
			'</strong></p><ul class="gf-odoo-remap-list">';

		$.each( remap.fields || {}, function ( key, field ) {
			if ( field.status === 'unmatched' ) {
				return;
			}
			var templateLabel =
				field.template_field_label ||
				( field.template_field_id ? 'field ' + field.template_field_id : '' );
			var line =
				'✓ ' +
				field.odoo_label +
				' — "' +
				templateLabel +
				'"';
			if ( field.target_field_id ) {
				line += ' → field ' + field.target_field_id;
			}
			if ( field.status === 'identical' ) {
				line += ' [' + methodLabel( 'id' ) + ']';
			} else if ( field.match_method ) {
				line += ' [' + methodLabel( field.match_method ) + ']';
			}
			html += '<li>' + line + '</li>';
			if ( field.target_field_id ) {
				remapState.fieldRemaps[ key ] = field.target_field_id;
			}
		} );

		html += '</ul>';

		var unmatchedKeys = Object.keys( remap.unmatched || {} );
		if ( unmatchedKeys.length ) {
			html +=
				'<p><strong>' +
				( cfg.remapUnmatchedHeading || 'Could not match:' ) +
				'</strong></p><ul class="gf-odoo-remap-list gf-odoo-remap-list--unmatched">';
			unmatchedKeys.forEach( function ( key ) {
				var field = remap.unmatched[ key ];
				var templateLabel = field.template_field_label || '';
				html +=
					'<li data-field-key="' +
					key +
					'"><span class="gf-odoo-remap-unmatched-label">✗ ' +
					( field.field_label_in_template || key ) +
					' — "' +
					templateLabel +
					'" ' +
					( cfg.remapNotFound || 'not found in this form' ) +
					'</span> ' +
					fieldSelectHtml( choices, remapState.fieldRemaps[ key ] || '', 'gf-odoo-remap-unmatched-select' ) +
					'</li>';
			} );
			html += '</ul>';
		}

		html +=
			'<div class="gf-odoo-remap-actions">' +
			'<button type="button" class="' +
			btnClass( 'primary' ) +
			' gf-odoo-remap-confirm">' +
			( cfg.remapConfirm || 'Confirm & link' ) +
			'</button> ' +
			'<button type="button" class="' +
			btnClass( 'ghost' ) +
			' gf-odoo-remap-cancel">' +
			( cfg.remapCancel || 'Cancel' ) +
			'</button></div>';

		return html;
	}

	function renderManualTable() {
		var cfg = adminConfig();
		var fields = remapState.data.remap.fields || {};
		var choices = remapState.data.field_choices || [];
		var html =
			'<h3>' + ( cfg.remapManualTitle || 'Manual field mapping' ) + '</h3>' +
			'<table class="gf-odoo-table gf-odoo-remap-table"><thead><tr>' +
			'<th>' + ( cfg.remapColOdoo || 'Odoo field' ) + '</th>' +
			'<th>' + ( cfg.remapColTemplate || 'Template maps to' ) + '</th>' +
			'<th>' + ( cfg.remapColForm || 'This form' ) + '</th>' +
			'</tr></thead><tbody>';

		$.each( fields, function ( key, field ) {
			var templateLabel =
				field.template_field_label ||
				( field.template_field_id ? 'field ' + field.template_field_id : '—' );
			var selected = field.target_field_id || remapState.fieldRemaps[ key ] || '';

			if ( selected ) {
				remapState.fieldRemaps[ key ] = selected;
			}

			html +=
				'<tr data-field-key="' +
				key +
				'"><td>' +
				field.odoo_label +
				'</td><td>→ "' +
				templateLabel +
				'"' +
				( field.template_field_id ? ' (field ' + field.template_field_id + ')' : '' ) +
				'</td><td>' +
				fieldSelectHtml( choices, selected ) +
				'</td></tr>';
		} );

		html +=
			'</tbody></table><div class="gf-odoo-remap-actions">' +
			'<button type="button" class="' +
			btnClass( 'primary' ) +
			' gf-odoo-remap-confirm">' +
			( cfg.remapConfirm || 'Confirm & link' ) +
			'</button> ' +
			'<button type="button" class="' +
			btnClass( 'ghost' ) +
			' gf-odoo-remap-cancel">' +
			( cfg.remapCancel || 'Cancel' ) +
			'</button></div>';

		return html;
	}

	function allUnmatchedResolved() {
		var unmatched = remapState.data.remap.unmatched || {};
		return Object.keys( unmatched ).every( function ( key ) {
			return !!remapState.fieldRemaps[ key ];
		} );
	}

	function confirmTemplateLink() {
		if ( remapState.mode === 'auto' && !allUnmatchedResolved() ) {
			window.alert(
				adminConfig().remapResolveAll ||
					'Please select a field for each unmatched mapping.'
			);
			return;
		}

		var $block = $( '#gf-odoo-feed-template' );
		var module =
			$( '[name="_gform_setting_module"]' ).val() ||
			$block.data( 'module' ) ||
			'crm';

		post( 'gf_odoo_save_template_link', {
			template_id: remapState.templateId,
			form_id: remapState.formId,
			feed_id: $block.data( 'feed-id' ),
			module: module,
			field_remaps: remapState.fieldRemaps,
			overrides: {},
		} )
			.done( handleTemplateLinkResponse )
			.fail( function () {
				showLinkMessage(
					( adminConfig() || {} ).templateLinkFailed ||
						'Could not link the template.',
					true
				);
			} );
	}

	function startTemplateLink( templateId ) {
		var $block = $( '#gf-odoo-feed-template' );
		var names = getTemplateNames();

		remapState.templateId = templateId;
		remapState.templateName = names[ templateId ] || 'Template #' + templateId;
		remapState.formId = parseInt( $block.data( 'form-id' ), 10 );
		remapState.formTitle = $block.data( 'form-title' ) || 'Form';
		remapState.fieldRemaps = {};
		remapState.data = null;

		post( 'gf_odoo_compute_remap', {
			template_id: templateId,
			target_form_id: remapState.formId,
		} )
			.done( function ( res ) {
			if ( ! res.success ) {
				showLinkMessage(
					( res.data && res.data.message ) ||
						( adminConfig() || {} ).templateLinkFailed ||
						'Could not prepare field mapping.',
					true
				);
				return;
			}

			remapState.data = res.data;

			if ( !res.data.field_mapping_count ) {
				post( 'gf_odoo_save_template_link', {
					template_id: templateId,
					form_id: remapState.formId,
					feed_id: $block.data( 'feed-id' ),
					module: $block.data( 'module' ),
					field_remaps: {},
					overrides: {},
				} )
					.done( handleTemplateLinkResponse )
					.fail( function () {
						showLinkMessage(
							( adminConfig() || {} ).templateLinkFailed ||
								'Could not link the template.',
							true
						);
					} );
				return;
			}

			remapState.mode = '';
			$modal().find( '.gf-odoo-remap-modal__body' ).html( renderStepChoose() );
			openRemapModal();
		} )
			.fail( function () {
				showLinkMessage(
					( adminConfig() || {} ).templateLinkFailed ||
						'Could not load field mapping preview.',
					true
				);
			} );
	}

	function saveTemplateLinkDirect() {
		var $block = $( '#gf-odoo-feed-template' );
		if ( ! $block.length ) {
			return;
		}

		var useTemplate = $( 'input[name="gf_odoo_template_mode"]:checked' ).val() === 'template';
		var templateId = useTemplate ? parseInt( $( '#gf-odoo-template-select' ).val(), 10 ) : 0;
		var module =
			$( '[name="_gform_setting_module"]' ).val() ||
			$block.data( 'module' ) ||
			'crm';
		var overrides = useTemplate ? collectOverrides( $( '#gform-settings' ) ) : {};

		// Unlinking still needs a request; linking with no overrides is a no-op on the server.
		if ( useTemplate && templateId > 0 && $.isEmptyObject( overrides ) ) {
			return;
		}

		post( 'gf_odoo_save_template_link', {
			template_id: templateId,
			form_id: $block.data( 'form-id' ),
			feed_id: $block.data( 'feed-id' ),
			module: module,
			field_remaps: {},
			overrides: overrides,
		} );
	}

	$( document ).on( 'click', '.gf-odoo-remap-auto', function () {
		remapState.mode = 'auto';
		remapState.fieldRemaps = {};
		$modal().find( '.gf-odoo-remap-modal__body' ).html( renderAutoResult() );
	} );

	$( document ).on( 'click', '.gf-odoo-remap-manual', function () {
		remapState.mode = 'manual';
		remapState.fieldRemaps = {};
		$modal().find( '.gf-odoo-remap-modal__body' ).html( renderManualTable() );
	} );

	$( document ).on( 'change', '.gf-odoo-remap-field-select, .gf-odoo-remap-unmatched-select', function () {
		var $select = $( this );
		var key =
			$select.closest( 'tr' ).data( 'field-key' ) ||
			$select.closest( 'li' ).data( 'field-key' );

		if ( ! key && remapState.mode === 'auto' ) {
			var index = $select.closest( 'li' ).index();
			var unmatchedKeys = Object.keys( remapState.data.remap.unmatched || {} );
			key = unmatchedKeys[ index ];
		}

		if ( key ) {
			remapState.fieldRemaps[ key ] = $select.val();
		}
	} );

	$( document ).on( 'click', '.gf-odoo-remap-confirm', confirmTemplateLink );
	$( document ).on( 'click', '.gf-odoo-remap-cancel, .gf-odoo-remap-modal__backdrop', function () {
		closeRemapModal();
		$( '#gf-odoo-template-select' ).val( $( '#gf-odoo-feed-template' ).data( 'prev-template' ) || '0' );
	} );

	/* --- Sample form selector (template editor) --- */

	$( document ).on( 'change', '#gf-odoo-sample-form', function () {
		var formId = parseInt( $( this ).val(), 10 ) || 0;
		toggleSampleFormNotice( formId );
		loadSampleFormFields( formId );
	} );

	$( document ).on( 'click', '#gf-odoo-template-fields .gf-odoo-mode-tab[data-mode="field"]', function () {
		var formId = parseInt( $( '#gf-odoo-sample-form' ).val(), 10 ) || 0;
		if ( ! formId ) {
			return;
		}
		var $row = $( this ).closest( '.gf-odoo-crm-field-row' );
		var $select = $row.find( '.gf-odoo-gf-field-select' );
		if (
			$select.length &&
			( $select.hasClass( 'gf-odoo-gf-field-select--needs-sample' ) ||
				$select.find( 'option' ).length <= 1 )
		) {
			loadSampleFormFields( formId );
		}
	} );

	/* --- Template list: delete --- */

	$( document ).on( 'click', '.gf-odoo-delete-template', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var id = parseInt( $btn.data( 'id' ), 10 );
		var linked = parseInt( $btn.data( 'linked' ), 10 ) || 0;
		var tpl = adminConfig();
		var msg =
			linked > 0 && tpl && tpl.deleteTemplateLinked
				? tpl.deleteTemplateLinked.replace( '%d', String( linked ) )
				: ( tpl && tpl.deleteTemplateConfirm ) || 'Delete this template?';

		if ( ! window.confirm( msg ) ) {
			return;
		}

		post( 'gf_odoo_delete_template', { template_id: id } ).done( function ( res ) {
			if ( res.success ) {
				$btn.closest( 'tr' ).fadeOut( function () {
					$( this ).remove();
				} );
			}
		} );
	} );

	$( document ).on( 'click', '.gf-odoo-duplicate-template', function ( e ) {
		e.preventDefault();
		var id = parseInt( $( this ).data( 'id' ), 10 );

		post( 'gf_odoo_duplicate_template', { template_id: id } ).done( function ( res ) {
			if ( res.success && res.data && res.data.redirect ) {
				window.location.href = res.data.redirect;
			}
		} );
	} );

	$( document ).on( 'click', '#gf-odoo-save-template', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $form = $( '#gf-odoo-template-form' );
		var module = $( '#gf-odoo-template-module' ).val();
		var cfg = adminConfig();

		if ( typeof window.gfOdooSyncRowValueInputNames === 'function' ) {
			$form.find( '.gf-odoo-crm-field-row' ).each( function () {
				window.gfOdooSyncRowValueInputNames( $( this ) );
			} );
		}

		$btn.prop( 'disabled', true );

		post( 'gf_odoo_save_template', {
			template_id: $( '#gf-odoo-template-id' ).val(),
			name: $( '#gf-odoo-template-name' ).val(),
			module: module,
			sample_form_id: $( '#gf-odoo-sample-form' ).val() || 0,
			feed_meta_json: JSON.stringify( collectFeedMetaFromForm( $form ) ),
		} )
			.done( function ( res ) {
				if ( res.success && res.data && res.data.redirect ) {
					window.location.href = res.data.redirect;
					return;
				}

				var msg =
					( res.data && res.data.message ) ||
					( cfg && cfg.requestFailed ) ||
					'Could not save template.';
				window.alert( msg );
			} )
			.fail( function () {
				window.alert(
					( cfg && cfg.requestFailed ) ||
						'Could not save template. Please try again.'
				);
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'change', 'input[name="gf_odoo_template_mode"]', function () {
		var useTemplate = $( 'input[name="gf_odoo_template_mode"]:checked' ).val() === 'template';
		$( '#gf-odoo-template-select' ).prop( 'disabled', ! useTemplate );

		if ( ! useTemplate ) {
			saveTemplateLinkDirect();
			window.location.reload();
		}
	} );

	$( document ).on( 'change', '#gf-odoo-template-select', function () {
		var $select = $( this );
		var templateId = parseInt( $select.val(), 10 ) || 0;
		var $block = $( '#gf-odoo-feed-template' );
		var prev = parseInt( $block.data( 'prev-template' ) || '0', 10 );

		if ( templateId > 0 ) {
			$block.data( 'prev-template', prev || $select.data( 'was' ) || 0 );
			$select.data( 'was', templateId );
			startTemplateLink( templateId );
			return;
		}

		saveTemplateLinkDirect();
		window.location.reload();
	} );

	$( document ).on( 'click', '.gf-odoo-override-field', function ( e ) {
		e.preventDefault();
		var $row = $( this ).closest( '.gf-odoo-crm-field-row' );
		enableRowEdit( $row );
		saveTemplateLinkDirect();
	} );

	$( document ).on( 'click', '#gf-odoo-refresh-template-mappings', function ( e ) {
		e.preventDefault();

		var $block = $( '#gf-odoo-feed-template' );
		if ( ! $block.length || typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		var cfg = adminConfig() || {};

		if (
			! window.confirm(
				cfg.refreshTemplateMappingsConfirm ||
					'Refresh all field mappings from the template? Manual per-field overrides for this form will be replaced.'
			)
		) {
			return;
		}

		var $btn = $( this );
		$btn.prop( 'disabled', true );

		post( 'gf_odoo_refresh_template_mappings', {
			form_id: $block.data( 'form-id' ),
			feed_id: $block.data( 'feed-id' ),
		} )
			.done( function ( res ) {
				if ( res.success ) {
					window.location.reload();
					return;
				}
				window.alert(
					( res.data && res.data.message ) ||
						cfg.requestFailed ||
						'Could not refresh mappings.'
				);
			} )
			.fail( function () {
				window.alert( cfg.requestFailed || 'Could not refresh mappings.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '.gf-odoo-remove-override', function ( e ) {
		e.preventDefault();
		var $block = $( '#gf-odoo-feed-template' );
		var metaKey = $( this ).data( 'meta-key' );

		post( 'gf_odoo_remove_template_override', {
			form_id: $block.data( 'form-id' ),
			feed_id: $block.data( 'feed-id' ),
			meta_key: metaKey,
		} ).done( function () {
			window.location.reload();
		} );
	} );

	$( function () {
		var $block = $( '#gf-odoo-feed-template' );
		if ( $block.length ) {
			var linked = parseInt( $( '#gf-odoo-template-select' ).val(), 10 ) || 0;
			$block.data( 'prev-template', linked );
		}

		if ( ! $( '#gf-odoo-template-fields' ).length ) {
			return;
		}

		replaceLegacyFallbackInputs();

		var cfg = adminConfig();
		var sampleFormId = parseInt( $( '#gf-odoo-sample-form' ).val(), 10 ) || 0;
		toggleSampleFormNotice( sampleFormId );

		if ( cfg && cfg.initialFields && cfg.initialFields.length ) {
			applySampleFormFields( cfg.initialFields );
		} else if ( sampleFormId ) {
			loadSampleFormFields( sampleFormId );
		}
	} );
} )( jQuery );
