( function ( $ ) {
	'use strict';

	function getSetting( name ) {
		var selectors = [
			'[name="_gform_setting_' + name + '"]',
			'[name="_gaddon_setting_' + name + '"]',
		];
		var $field = $( selectors.join( ',' ) );

		return $field.length ? $.trim( $field.val() ) : '';
	}

	function setConnectionStatus( status, message ) {
		var $indicator = $( '#gf-odoo-connection-status' );

		if ( ! $indicator.length ) {
			return;
		}

		$indicator
			.removeClass(
				'gf-odoo-connection-status--success gf-odoo-connection-status--error gf-odoo-connection-status--unknown'
			)
			.addClass( 'gf-odoo-connection-status--' + status )
			.attr( 'data-status', status );

		$indicator.find( '.gf-odoo-connection-status__text' ).text( message );
	}

	function setResult( message, isError ) {
		var $result = $( '#gf-odoo-test-connection-result' );

		$result
			.text( message )
			.css( 'color', isError ? '#d63638' : '#00a32a' )
			.show();

		setConnectionStatus( isError ? 'error' : 'success', message );
	}

	$( document ).on( 'click', '#gf-odoo-export-all-data', function ( e ) {
		e.preventDefault();

		if ( typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		var $form = $( '<form>', {
			method: 'POST',
			action: gfOdooAdmin.ajaxUrl,
			target: '_blank',
		} );

		$form.append(
			$( '<input>', { type: 'hidden', name: 'action', value: 'gf_odoo_export_all_data' } ),
			$( '<input>', { type: 'hidden', name: 'nonce', value: gfOdooAdmin.odooNonce } )
		);

		$( document.body ).append( $form );
		$form.trigger( 'submit' );
		$form.remove();
	} );

	$( document ).on( 'click', '#gf-odoo-change-api-key', function ( e ) {
		e.preventDefault();

		var selectors = [
			'[name="_gform_setting_api_key"]',
			'[name="_gaddon_setting_api_key"]',
		];
		var $input = $( selectors.join( ',' ) );

		$input.val( '' ).attr( 'placeholder', '' ).trigger( 'focus' );
	} );

	$( document ).on( 'click', '#gf-odoo-reset-settings', function ( e ) {
		e.preventDefault();

		if ( typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		if ( ! window.confirm( gfOdooAdmin.resetSettingsConfirm || '' ) ) {
			return;
		}

		var $button = $( this );
		var $result = $( '#gf-odoo-reset-settings-result' );

		$button.prop( 'disabled', true );
		$result.text( '' ).css( 'color', '#646970' );

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_reset_settings',
			nonce: gfOdooAdmin.odooNonce,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					var redirect =
						response.data && response.data.redirect
							? response.data.redirect
							: window.location.href.split( '#' )[0];
					window.location.href = redirect;
					return;
				}

				var msg =
					response.data && response.data.message
						? response.data.message
						: gfOdooAdmin.requestFailed;
				$result.text( msg ).css( 'color', '#d63638' );
			} )
			.fail( function () {
				$result.text( gfOdooAdmin.requestFailed ).css( 'color', '#d63638' );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#gf-odoo-clear-cache', function ( e ) {
		e.preventDefault();

		if ( typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		var $button = $( this );
		var $result = $( '#gf-odoo-clear-cache-result' );

		$button.prop( 'disabled', true );
		$result.text( '' ).css( 'color', '#646970' );

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_clear_cache',
			nonce: gfOdooAdmin.odooNonce,
		} )
			.done( function ( response ) {
				var msg =
					response.success && response.data && response.data.message
						? response.data.message
						: gfOdooAdmin.requestFailed;
				$result.text( msg ).css( 'color', response.success ? '#00a32a' : '#d63638' );
			} )
			.fail( function () {
				$result.text( gfOdooAdmin.requestFailed ).css( 'color', '#d63638' );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '#gf-odoo-test-connection', function ( e ) {
		e.preventDefault();

		var $button = $( this );

		if ( typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		$button.prop( 'disabled', true );
		setResult( gfOdooAdmin.testing, false );

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_test_connection',
			nonce: gfOdooAdmin.nonce,
			odoo_url: getSetting( 'odoo_url' ),
			db_name: getSetting( 'db_name' ),
			login_email: getSetting( 'login_email' ),
			api_key: getSetting( 'api_key' ),
		} )
			.done( function ( response ) {
				if ( response.success && response.data && response.data.message ) {
					setResult( response.data.message, false );
					return;
				}

				var errorMessage =
					response.data && response.data.message
						? response.data.message
						: gfOdooAdmin.unknownError;

				setResult( errorMessage, true );
			} )
			.fail( function () {
				setResult( gfOdooAdmin.requestFailed, true );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	function getGfSettingSelector( name ) {
		return '[name="_gform_setting_' + name + '"], [name="_gaddon_setting_' + name + '"]';
	}

	function syncSalesTeamFromSalesperson() {
		if ( typeof gfOdooAdmin === 'undefined' || ! gfOdooAdmin.userTeams ) {
			return;
		}

		var userId = $( getGfSettingSelector( 'crm_user_id' ) ).val();
		var teamId = userId ? gfOdooAdmin.userTeams[ userId ] : null;

		if ( teamId ) {
			$( getGfSettingSelector( 'crm_team_id' ) ).val( String( teamId ) );
		}
	}

	$( document ).on( 'change', getGfSettingSelector( 'crm_user_id' ), syncSalesTeamFromSalesperson );
	$( syncSalesTeamFromSalesperson );

	function getActiveModule() {
		var fromSetting = getSetting( 'module' );
		if ( fromSetting ) {
			return fromSetting;
		}

		var $templateModule = $( '#gf-odoo-template-module' );
		if ( $templateModule.length ) {
			return $.trim( $templateModule.val() );
		}

		var $templateFields = $( '#gf-odoo-template-fields' );
		if ( $templateFields.length && $templateFields.data( 'module' ) ) {
			return String( $templateFields.data( 'module' ) );
		}

		return '';
	}

	function isHelpdeskModuleSelected() {
		return getActiveModule() === 'helpdesk';
	}

	function isCrmModuleSelected() {
		return getActiveModule() === 'crm';
	}

	function getHelpdeskTeamSelect() {
		return $( 'select.gf-odoo-helpdesk-team-select' ).first();
	}

	function populateHelpdeskTeamSelect( teams, selectedValue ) {
		var $select = getHelpdeskTeamSelect();

		if ( ! $select.length ) {
			return;
		}

		var options =
			'<option value="">' +
			escHtml( gfOdooAdmin.selectHelpdeskTeam ) +
			'</option>';

		teams.forEach( function ( team ) {
			if ( ! team || ! team.value ) {
				return;
			}

			options +=
				'<option value="' +
				escAttr( String( team.value ) ) +
				'">' +
				escHtml( team.label ) +
				'</option>';
		} );

		$select.html( options );

		if ( selectedValue ) {
			$select.val( String( selectedValue ) );
		}
	}

	function loadHelpdeskTeams( isManualRefresh ) {
		if ( typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		var $select = getHelpdeskTeamSelect();
		var $status = $( '#gf-odoo-helpdesk-teams-status' );

		if ( ! $select.length || ! isHelpdeskModuleSelected() ) {
			return;
		}

		if ( $select.data( 'teamsLoading' ) ) {
			return;
		}

		var savedValue = $.trim( $select.val() ) || gfOdooAdmin.savedHelpdeskTeamId || '';

		$select.data( 'teamsLoading', true );
		$select.prop( 'disabled', true );

		if ( $status.length ) {
			$status.text( gfOdooAdmin.loadingTeams );
		}

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_get_teams',
			nonce: gfOdooAdmin.teamsNonce,
		} )
			.done( function ( response ) {
				var teams =
					response.success && Array.isArray( response.data )
						? response.data
						: [];

				if ( ! teams.length ) {
					if ( $status.length ) {
						$status.text( gfOdooAdmin.teamsLoadError );
					}
					return;
				}

				populateHelpdeskTeamSelect( teams, savedValue );

				if ( $status.length ) {
					$status.text(
						isManualRefresh
							? teams.length + ' teams loaded.'
							: ''
					);
				}
			} )
			.fail( function () {
				if ( $status.length ) {
					$status.text( gfOdooAdmin.teamsLoadError );
				}
			} )
			.always( function () {
				$select.prop( 'disabled', false );
				$select.data( 'teamsLoading', false );
			} );
	}

	function escHtml( text ) {
		return $( '<div>' ).text( text ).html();
	}

	function escAttr( text ) {
		return escHtml( text ).replace( /"/g, '&quot;' );
	}

	$( document ).on( 'click', '#gf-odoo-refresh-helpdesk-teams', function ( e ) {
		e.preventDefault();
		loadHelpdeskTeams( true );
	} );

	function getCrmUserSelect() {
		return $( 'select.gf-odoo-crm-user-select' ).first();
	}

	function getCrmTeamSelect() {
		return $( 'select.gf-odoo-crm-team-select' ).first();
	}

	function loadCrmAssignment( isManualRefresh ) {
		if ( typeof gfOdooAdmin === 'undefined' || ! isCrmModuleSelected() ) {
			return;
		}

		var $userSelect = getCrmUserSelect();
		var $teamSelect = getCrmTeamSelect();
		var $status = $( '#gf-odoo-crm-assignment-status' );

		if ( ! $userSelect.length && ! $teamSelect.length ) {
			return;
		}

		if ( $userSelect.data( 'assignmentLoading' ) ) {
			return;
		}

		var savedUser = $.trim( $userSelect.val() );
		var savedTeam = $.trim( $teamSelect.val() );

		$userSelect.data( 'assignmentLoading', true );
		$userSelect.prop( 'disabled', true );
		$teamSelect.prop( 'disabled', true );

		if ( $status.length ) {
			$status.text( gfOdooAdmin.loadingCrmAssignment ).css( 'color', '#646970' );
		}

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_get_crm_assignment',
			nonce: gfOdooAdmin.odooNonce,
		} )
			.done( function ( response ) {
				if ( ! response.success || ! response.data ) {
					if ( $status.length ) {
						$status
							.text(
								( response.data && response.data.message ) ||
									gfOdooAdmin.crmAssignmentError
							)
							.css( 'color', '#d63638' );
					}
					return;
				}

				var teams = response.data.teams || [];
				var users = response.data.users || [];

				gfOdooAdmin.userTeams = response.data.userTeams || {};

				if ( $teamSelect.length ) {
					populateSelectOptions(
						$teamSelect,
						teams,
						savedTeam,
						gfOdooAdmin.selectSalesTeam
					);
				}

				if ( $userSelect.length ) {
					populateSelectOptions(
						$userSelect,
						users,
						savedUser,
						gfOdooAdmin.selectSalesperson
					);
				}

				syncSalesTeamFromSalesperson();

				if ( $status.length ) {
					$status
						.text(
							isManualRefresh
								? gfOdooAdmin.crmAssignmentLoaded
								: ''
						)
						.css( 'color', '#00a32a' );
				}
			} )
			.fail( function () {
				if ( $status.length ) {
					$status
						.text( gfOdooAdmin.crmAssignmentError )
						.css( 'color', '#d63638' );
				}
			} )
			.always( function () {
				$userSelect.prop( 'disabled', false );
				$teamSelect.prop( 'disabled', false );
				$userSelect.data( 'assignmentLoading', false );
			} );
	}

	$( document ).on( 'click', '#gf-odoo-refresh-crm-assignment', function ( e ) {
		e.preventDefault();
		loadCrmAssignment( true );
	} );

	// Global CRM assignment defaults page: refresh choices from Odoo then reload
	// so the server re-renders the salesperson/team selects with fresh data.
	$( document ).on(
		'click',
		'#gf-odoo-refresh-global-crm-assignment',
		function ( e ) {
			e.preventDefault();

			if ( typeof gfOdooAdmin === 'undefined' ) {
				return;
			}

			var $btn = $( this );
			var $status = $( '#gf-odoo-global-crm-assignment-status' );

			$btn.prop( 'disabled', true );

			if ( $status.length ) {
				$status
					.text( gfOdooAdmin.loadingCrmAssignment )
					.css( 'color', '#646970' );
			}

			$.post( gfOdooAdmin.ajaxUrl, {
				action: 'gf_odoo_get_crm_assignment',
				nonce: gfOdooAdmin.odooNonce,
			} )
				.done( function () {
					window.location.reload();
				} )
				.fail( function () {
					if ( $status.length ) {
						$status
							.text( gfOdooAdmin.crmAssignmentError )
							.css( 'color', '#d63638' );
					}
					$btn.prop( 'disabled', false );
				} );
		}
	);

	function populateSelectOptions( $select, items, savedValue, emptyLabel ) {
		var options =
			'<option value="">' +
			escHtml( emptyLabel || gfOdooAdmin.selectNone ) +
			'</option>';

		( items || [] ).forEach( function ( item ) {
			if ( ! item || item.value === undefined || item.value === '' ) {
				return;
			}

			options +=
				'<option value="' +
				escAttr( String( item.value ) ) +
				'">' +
				escHtml( item.label ) +
				'</option>';
		} );

		$select.html( options );

		if ( savedValue ) {
			$select.val( String( savedValue ) );
		}
	}

	function getRowConfig( key ) {
		var lists = [
			gfOdooAdmin.crmFieldRows || [],
			gfOdooAdmin.helpdeskFieldRows || [],
		];
		var found = null;

		lists.forEach( function ( rows ) {
			rows.forEach( function ( row ) {
				if ( row.key === key ) {
					found = row;
				}
			} );
		} );

		return found;
	}

	function getParentValue( parentKey ) {
		if ( ! parentKey ) {
			return '';
		}
		return getSetting( parentKey + '_value' );
	}

	function loadOdooSelect( $select ) {
		var action = $select.data( 'ajax-action' );
		var parentKey = $select.data( 'parent-key' );
		var savedValue = $select.val();
		var $row = $select.closest( '.gf-odoo-crm-field-row' );
		var $spinner = $row.find( '.gf-odoo-select-spinner' );

		if ( ! action || $select.data( 'odooLoading' ) ) {
			return;
		}

		$select.data( 'odooLoading', true );
		$spinner.addClass( 'is-active' );

		var postData = {
			action: action,
			nonce: gfOdooAdmin.odooNonce,
		};

		if ( parentKey ) {
			var parentVal = getParentValue( parentKey );
			if ( 'lead_sub_industry' === $row.data( 'key' ) ) {
				postData.industry_id = parentVal;
			}
			if ( 'lead_sub_source' === $row.data( 'key' ) ) {
				postData.source_id = parentVal;
			}
		}

		$.post( gfOdooAdmin.ajaxUrl, postData )
			.done( function ( response ) {
				var items =
					response.success && Array.isArray( response.data )
						? response.data
						: [];
				populateSelectOptions(
					$select,
					items,
					savedValue,
					gfOdooAdmin.selectNone
				);
			} )
			.fail( function () {
				// eslint-disable-next-line no-console
				console.warn( gfOdooAdmin.crmOptionsLoadError );
			} )
			.always( function () {
				$select.data( 'odooLoading', false );
				$spinner.removeClass( 'is-active' );
			} );
	}

	function syncRowValueInputNames( $row ) {
		var key = $row.data( 'key' );
		if ( ! key ) {
			return;
		}

		var mode = $row.find( '.gf-odoo-mode-input' ).val() || 'off';
		var settingName = '_gform_setting_' + key + '_value';

		$row.find( 'select, input, textarea' ).each( function () {
			var $el = $( this );

			if (
				$el.hasClass( 'gf-odoo-mode-input' ) ||
				$el.hasClass( 'gf-odoo-readonly-value-hidden' )
			) {
				return;
			}

			var panel = $el.closest( '[data-mode-panel]' ).data( 'mode-panel' );
			if ( ! panel ) {
				return;
			}

			if (
				panel === mode &&
				mode !== 'off' &&
				mode !== 'auto' &&
				! $el.prop( 'disabled' )
			) {
				$el.attr( 'name', settingName );
			} else {
				$el.removeAttr( 'name' );
			}
		} );
	}

	function setRowMode( $row, mode ) {
		var key = $row.data( 'key' );
		var modes = $row.data( 'modes' );

		if ( typeof modes === 'string' ) {
			try {
				modes = JSON.parse( modes );
			} catch ( e ) {
				modes = [];
			}
		}

		if ( modes.indexOf( mode ) === -1 ) {
			mode = modes[0] || 'off';
		}

		$row.toggleClass( 'is-off', mode === 'off' );
		$row.find( '.gf-odoo-mode-tab' ).removeClass( 'is-active' );
		$row
			.find( '.gf-odoo-mode-tab[data-mode="' + mode + '"]' )
			.addClass( 'is-active' );
		$row.find( '.gf-odoo-mode-input' ).val( mode );

		$row.find( '[data-mode-panel]' ).removeClass( 'is-visible' );
		if ( mode !== 'off' ) {
			$row
				.find( '[data-mode-panel="' + mode + '"]' )
				.addClass( 'is-visible' );
		}

		if ( mode === 'fixed' ) {
			var $odooSelect = $row.find( '.gf-odoo-odoo-select' );
			if (
				$odooSelect.length &&
				$odooSelect.find( 'option' ).length <= 2
			) {
				loadOdooSelect( $odooSelect );
			}
		}

		syncRowValueInputNames( $row );
	}

	function initFieldRowsInContainer( $container ) {
		$container.find( '.gf-odoo-crm-field-row' ).each( function () {
			var $row = $( this );
			var key = $row.data( 'key' );
			var config = getRowConfig( key );
			var mode = $row.find( '.gf-odoo-mode-input' ).val();

			if ( ! mode && config ) {
				mode = config.mode;
			}

			if ( ! mode ) {
				mode = 'off';
			}

			setRowMode( $row, mode );

			if ( 'fixed' === mode ) {
				var $odooSelect = $row.find( '.gf-odoo-odoo-select' );
				if ( $odooSelect.length ) {
					loadOdooSelect( $odooSelect );
				}
			}
		} );
	}

	function initCrmFieldRows() {
		if ( typeof gfOdooAdmin === 'undefined' || ! isCrmModuleSelected() ) {
			return;
		}

		$( '.gf-odoo-crm-fields' ).not( '.gf-odoo-helpdesk-fields' ).each( function () {
			initFieldRowsInContainer( $( this ) );
		} );
	}

	function initHelpdeskFieldRows() {
		if ( typeof gfOdooAdmin === 'undefined' || ! isHelpdeskModuleSelected() ) {
			return;
		}

		$( '.gf-odoo-helpdesk-fields' ).each( function () {
			initFieldRowsInContainer( $( this ) );
		} );
	}

	$( document ).on( 'click', '.gf-odoo-mode-tab', function ( e ) {
		e.preventDefault();
		var $tab = $( this );
		var $row = $tab.closest( '.gf-odoo-crm-field-row' );

		if ( $row.hasClass( 'gf-odoo-field--readonly' ) ) {
			$row.removeClass( 'gf-odoo-field--readonly' ).addClass( 'gf-odoo-field--override' );
			$row.find( '.gf-odoo-mode-tab' ).prop( 'disabled', false );
			$row.find( 'select, input, textarea' ).not( '.gf-odoo-readonly-value-hidden' ).prop( 'disabled', false );
			$row.find( '.gf-odoo-readonly-value-hidden' ).remove();
		}

		setRowMode( $row, $tab.data( 'mode' ) );
	} );

	$( document ).on( 'submit', '#gform-settings', function () {
		$( '.gf-odoo-crm-field-row' ).each( function () {
			syncRowValueInputNames( $( this ) );
		} );
	} );

	$( document ).on(
		'change',
		getGfSettingSelector( 'lead_industry_value' ),
		function () {
			var $subRow = $( '.gf-odoo-crm-field-row[data-key="lead_sub_industry"]' );
			var $select = $subRow.find( '.gf-odoo-odoo-select' );
			if ( $select.length && $subRow.find( '.gf-odoo-mode-input' ).val() === 'fixed' ) {
				$select.html(
					'<option value="">' + escHtml( gfOdooAdmin.selectNone ) + '</option>'
				);
				loadOdooSelect( $select );
			}
		}
	);

	$( document ).on(
		'change',
		getGfSettingSelector( 'lead_source_value' ),
		function () {
			var $subRow = $( '.gf-odoo-crm-field-row[data-key="lead_sub_source"]' );
			var $select = $subRow.find( '.gf-odoo-odoo-select' );
			if ( $select.length && $subRow.find( '.gf-odoo-mode-input' ).val() === 'fixed' ) {
				$select.html(
					'<option value="">' + escHtml( gfOdooAdmin.selectNone ) + '</option>'
				);
				loadOdooSelect( $select );
			}
		}
	);

	$( initCrmFieldRows );
	$( initHelpdeskFieldRows );
	$( function () {
		loadCrmAssignment( false );
	} );

	$( document ).on( 'click', '#gf-odoo-debug-country-btn', function ( e ) {
		e.preventDefault();

		if ( typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		var input = $( '#gf-odoo-debug-country' ).val();
		var $result = $( '#gf-odoo-debug-country-result' );

		$result.text( 'Testing...' ).css( 'color', '' );

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_debug_country_resolve',
			nonce: gfOdooAdmin.odooNonce,
			country: input,
		} )
			.done( function ( response ) {
				var msg =
					response.success && response.data
						? response.data.message
						: gfOdooAdmin.requestFailed;
				var resolved =
					response.success &&
					response.data &&
					response.data.resolved;

				$result.text( msg || '' );
				$result.css( 'color', resolved ? 'green' : 'red' );
			} )
			.fail( function () {
				$result.text( gfOdooAdmin.requestFailed ).css( 'color', 'red' );
			} );
	} );

	$( document ).on( 'click', '#gf-odoo-debug-industry-btn', function ( e ) {
		e.preventDefault();

		if ( typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		var input = $( '#gf-odoo-debug-industry' ).val();
		var $result = $( '#gf-odoo-debug-industry-result' );

		$result.text( 'Testing...' ).css( 'color', '' );

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_debug_industry_resolve',
			nonce: gfOdooAdmin.odooNonce,
			industry: input,
		} )
			.done( function ( response ) {
				var msg =
					response.success && response.data
						? response.data.message
						: gfOdooAdmin.requestFailed;
				var resolved =
					response.success &&
					response.data &&
					response.data.resolved;

				$result.text( msg || '' );
				$result.css( 'color', resolved ? 'green' : 'red' );
			} )
			.fail( function () {
				$result.text( gfOdooAdmin.requestFailed ).css( 'color', 'red' );
			} );
	} );

	$( document ).on( 'click', '#gf-odoo-debug-model-btn', function ( e ) {
		e.preventDefault();

		if ( typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		var model = $( '#gf-odoo-debug-model' ).val();
		var $status = $( '#gf-odoo-debug-model-status' );
		var $result = $( '#gf-odoo-debug-model-result' );

		$status.text( gfOdooAdmin.loadingCrmOptions );
		$result.hide().empty();

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_debug_odoo_model_fields',
			nonce: gfOdooAdmin.odooNonce,
			model: model,
		} )
			.done( function ( response ) {
				if ( response.success && response.data && response.data.fields ) {
					$status.text( response.data.message || '' );
					$result
						.text( JSON.stringify( response.data.fields, null, 2 ) )
						.show();
					return;
				}

				$status.text(
					( response.data && response.data.message ) ||
						gfOdooAdmin.crmOptionsLoadError
				);
			} )
			.fail( function () {
				$status.text( gfOdooAdmin.requestFailed );
			} );
	} );

	$( document ).on( 'click', '#gf-odoo-debug-helpdesk-fields', function ( e ) {
		e.preventDefault();

		if ( typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		var $button = $( this );
		var $status = $( '#gf-odoo-helpdesk-fields-debug-status' );
		var $result = $( '#gf-odoo-helpdesk-fields-debug-result' );

		$button.prop( 'disabled', true );
		$status.text( gfOdooAdmin.loadingCrmOptions );
		$result.empty();

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_debug_helpdesk_fields',
			nonce: gfOdooAdmin.odooNonce,
		} )
			.done( function ( response ) {
				if ( response.success && response.data && response.data.html ) {
					$result.html( response.data.html );
					$status.text(
						( response.data.count || 0 ) + ' fields loaded.'
					);
					return;
				}

				$status.text( gfOdooAdmin.crmOptionsLoadError );
			} )
			.fail( function () {
				$status.text( gfOdooAdmin.requestFailed );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	function postErrorLogAction( action, errorId, $button, loadingText, successText ) {
		if ( typeof gfOdooAdmin === 'undefined' || ! errorId ) {
			return;
		}

		var nonce =
			action === 'gf_odoo_retry'
				? gfOdooAdmin.retryNonce
				: gfOdooAdmin.resolveNonce;

		$button.prop( 'disabled', true ).text( loadingText );

		$.post( gfOdooAdmin.ajaxUrl, {
			action: action,
			nonce: nonce,
			error_id: errorId,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					$button.text( successText );
					window.location.reload();
					return;
				}

				var message =
					response.data && response.data.message
						? response.data.message
						: gfOdooAdmin.retryFailed;

				alert( message );
				$button.prop( 'disabled', false );
			} )
			.fail( function () {
				alert( gfOdooAdmin.requestFailed );
				$button.prop( 'disabled', false );
			} );
	}

	$( document ).on( 'click', '.gf-odoo-retry-error', function ( e ) {
		e.preventDefault();

		var $button = $( this );
		var errorId = parseInt( $button.data( 'error-id' ), 10 );

		postErrorLogAction(
			'gf_odoo_retry',
			errorId,
			$button,
			gfOdooAdmin.retrying,
			gfOdooAdmin.retrySuccess
		);
	} );

	function formatRetryCountdown( seconds ) {
		if ( seconds < 60 ) {
			return seconds + 's';
		}

		var minutes = Math.floor( seconds / 60 );
		var hours = Math.floor( minutes / 60 );
		var days = Math.floor( hours / 24 );

		if ( days > 0 ) {
			hours = hours % 24;
			return days + 'd ' + hours + 'h';
		}

		if ( hours > 0 ) {
			minutes = minutes % 60;
			return hours + 'h ' + minutes + 'm';
		}

		return minutes + 'm ' + ( seconds % 60 ) + 's';
	}

	function updateRetryCountdowns() {
		var template = gfOdooAdmin.retryingIn || 'Retrying in %s';
		var dueLabel = gfOdooAdmin.retryDueNow || 'Retry due now';

		$( '.gf-odoo-retry-countdown' ).each( function () {
			var $el = $( this );
			var retryAt = parseInt( $el.data( 'retry-at' ), 10 );

			if ( ! retryAt ) {
				return;
			}

			var diff = Math.max( 0, retryAt * 1000 - Date.now() );

			if ( diff <= 0 ) {
				$el.text( dueLabel );
				return;
			}

			$el.text( template.replace( '%s', formatRetryCountdown( Math.ceil( diff / 1000 ) ) ) );
		} );
	}

	if ( $( '.gf-odoo-retry-countdown' ).length ) {
		updateRetryCountdowns();
		setInterval( updateRetryCountdowns, 1000 );
	}

	$( '#gf-odoo-copy-webhook-url' ).on( 'click', function ( e ) {
		e.preventDefault();

		var $input = $( '#gf-odoo-webhook-url' );
		var $result = $( '#gf-odoo-copy-webhook-result' );

		if ( ! $input.length ) {
			return;
		}

		var url = $input.val();

		function showCopied() {
			$result.text( gfOdooAdmin.webhookUrlCopied || 'Copied.' );
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( showCopied ).catch( function () {
				$result.text( gfOdooAdmin.webhookCopyFailed || 'Copy failed.' );
			} );
			return;
		}

		$input.trigger( 'focus' ).trigger( 'select' );

		try {
			document.execCommand( 'copy' );
			showCopied();
		} catch ( err ) {
			$result.text( gfOdooAdmin.webhookCopyFailed || 'Copy failed.' );
		}
	} );

	$( document ).on( 'click', '.gf-odoo-entry-sync-now', function ( e ) {
		e.preventDefault();

		var $button = $( this );
		var entryId = parseInt( $button.data( 'entry-id' ), 10 );
		var $result = $button.siblings( '.gf-odoo-entry-sync-result' );

		if ( ! entryId || ! gfOdooAdmin.entrySyncNonce ) {
			return;
		}

		$button.prop( 'disabled', true );
		$result.text( gfOdooAdmin.entrySyncing || 'Syncing…' );

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_entry_sync_now',
			nonce: gfOdooAdmin.entrySyncNonce,
			entry_id: entryId,
		} )
			.done( function ( response ) {
				if ( response.success ) {
					$result.text( gfOdooAdmin.entrySyncSuccess || 'Synced.' );
					window.location.reload();
					return;
				}

				var message =
					response.data && response.data.message
						? response.data.message
						: gfOdooAdmin.entrySyncFailed;

				$result.text( message );
				$button.prop( 'disabled', false );
			} )
			.fail( function () {
				$result.text( gfOdooAdmin.requestFailed );
				$button.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '.gf-odoo-resolve-error', function ( e ) {
		e.preventDefault();

		var $button = $( this );
		var errorId = parseInt( $button.data( 'error-id' ), 10 );

		postErrorLogAction(
			'gf_odoo_mark_resolved',
			errorId,
			$button,
			gfOdooAdmin.resolving,
			gfOdooAdmin.resolveSuccess
		);
	} );

	window.gfOdooSyncRowValueInputNames = syncRowValueInputNames;

	$( document ).on( 'change', '#gf-odoo-test-form', function () {
		var formId = $( this ).val();
		var $feed = $( '#gf-odoo-test-feed' );
		var $result = $( '#gf-odoo-test-submission-result' );

		$result.text( '' );
		$feed.prop( 'disabled', true ).html(
			'<option value="">' + escHtml( gfOdooAdmin.selectNone || '—' ) + '</option>'
		);

		if ( ! formId || typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_get_test_feeds',
			nonce: gfOdooAdmin.odooNonce,
			form_id: formId,
		} )
			.done( function ( response ) {
				if ( ! response.success || ! response.data.feeds ) {
					return;
				}

				var html =
					'<option value="">' +
					escHtml( gfOdooAdmin.selectNone || '— Select a feed —' ) +
					'</option>';

				response.data.feeds.forEach( function ( feed ) {
					html +=
						'<option value="' +
						escHtml( String( feed.id ) ) +
						'">' +
						escHtml( feed.label ) +
						'</option>';
				} );

				$feed.html( html ).prop( 'disabled', false );
			} )
			.fail( function () {
				$feed.prop( 'disabled', true );
			} );
	} );

	$( document ).on( 'click', '#gf-odoo-send-test-submission', function ( e ) {
		e.preventDefault();

		if ( typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		var formId = $( '#gf-odoo-test-form' ).val();
		var feedId = $( '#gf-odoo-test-feed' ).val();
		var scenario = $( '#gf-odoo-test-scenario' ).val() || 'normal';
		var $result = $( '#gf-odoo-test-submission-result' );
		var $button = $( this );

		if ( ! formId || ! feedId ) {
			$result
				.text( gfOdooAdmin.testSelectFormFeed || 'Select a form and feed first.' )
				.css( 'color', '#d63638' );
			return;
		}

		$button.prop( 'disabled', true );
		$result
			.text( gfOdooAdmin.testSending || 'Sending…' )
			.css( 'color', '#646970' );

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_send_test_submission',
			nonce: gfOdooAdmin.odooNonce,
			form_id: formId,
			feed_id: feedId,
			scenario: scenario,
		} )
			.done( function ( response ) {
				var msg =
					response.data && response.data.message
						? response.data.message
						: gfOdooAdmin.requestFailed;
				var ok = response.success;

				if ( ok && response.data && response.data.record_url ) {
					msg +=
						' <a href="' +
						escHtml( response.data.record_url ) +
						'" target="_blank" rel="noopener noreferrer">' +
						escHtml( 'Open in Odoo' ) +
						'</a>';
				}

				$result.html( msg ).css( 'color', ok ? '#00a32a' : '#d63638' );

				if ( response.data && response.data.scenario_row ) {
					updateScenarioRow( response.data.scenario_row );
				} else if ( scenario ) {
					refreshScenarioRowFromResponse( scenario, response );
				}
			} )
			.fail( function () {
				$result.text( gfOdooAdmin.requestFailed ).css( 'color', '#d63638' );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	function refreshScenarioRowFromResponse( scenario, response ) {
		var $row = $( '#gf-odoo-scenario-results tr[data-scenario="' + scenario + '"]' );
		if ( ! $row.length ) {
			return;
		}

		var ok = response.success;
		var msg =
			response.data && response.data.message ? response.data.message : '';

		$row.find( '.gf-odoo-scenario-ran-at' ).text(
			new Date().toLocaleString()
		);
		$row
			.find( '.gf-odoo-scenario-status' )
			.html(
				ok
					? '<span class="gf-odoo-badge badge-crm">Pass</span>'
					: '<span class="gf-odoo-badge" style="background:#d63638;color:#fff;">Fail</span>'
			);
		$row.find( '.gf-odoo-scenario-message' ).text( msg );
	}

	function updateScenarioRow( row ) {
		var $tr = $( '#gf-odoo-scenario-results tr[data-scenario="' + row.key + '"]' );
		if ( ! $tr.length ) {
			return;
		}

		$tr.find( '.gf-odoo-scenario-ran-at' ).text( row.ran_at || '' );
		$tr.find( '.gf-odoo-scenario-message' ).text( row.message || '' );
		$tr
			.find( '.gf-odoo-scenario-status' )
			.html(
				row.passed
					? '<span class="gf-odoo-badge badge-crm">Pass</span>'
					: '<span class="gf-odoo-badge" style="background:#d63638;color:#fff;">Fail</span>'
			);
	}

	$( document ).on( 'change', '.gf-odoo-checklist-item', function () {
		if ( typeof gfOdooAdmin === 'undefined' ) {
			return;
		}

		var $cb = $( this );
		var item = $cb.data( 'item' );

		$.post( gfOdooAdmin.ajaxUrl, {
			action: 'gf_odoo_save_checklist_item',
			nonce: gfOdooAdmin.odooNonce,
			item: item,
			checked: $cb.is( ':checked' ) ? 1 : 0,
		} );
	} );
}( jQuery ) );
