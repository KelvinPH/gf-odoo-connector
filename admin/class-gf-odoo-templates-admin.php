<?php
/**
 * Feed templates admin UI and AJAX.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Templates list / edit screens.
 */
class GF_Odoo_Templates_Admin {

	/**
	 * @var GF_Odoo_Addon
	 */
	private $addon;

	/**
	 * @param GF_Odoo_Addon $addon Add-on instance.
	 */
	public function __construct( GF_Odoo_Addon $addon ) {
		$this->addon = $addon;
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Enqueue template editor scripts with inline config (edit screen only).
	 */
	public function enqueue_editor_assets(): void {
		if ( ! $this->addon->is_templates_admin_page() || ! $this->is_edit_screen_request() ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		wp_enqueue_script(
			'gf_odoo_admin',
			GF_ODOO_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			GF_ODOO_VERSION,
			true
		);
		wp_enqueue_script(
			'gf_odoo_templates',
			GF_ODOO_URL . 'assets/js/templates.js',
			array( 'jquery', 'gf_odoo_admin' ),
			GF_ODOO_VERSION,
			true
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$template_id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$sample_form_id = 0;
		if ( $template_id > 0 ) {
			$template = Template_Manager::get( $template_id );
			if ( $template ) {
				$sample_form_id = (int) ( $template->sample_form_id ?? 0 );
			}
		}

		wp_localize_script(
			'gf_odoo_templates',
			'gfOdooTemplatePage',
			array(
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'odooNonce'            => wp_create_nonce( 'gf_odoo_nonce' ),
				'selectField'          => esc_html__( '— Select a field —', 'gf-odoo-connector' ),
				'initialFields'        => $this->get_form_field_choices( $sample_form_id ),
				'sampleFieldsError'    => esc_html__( 'Could not load form fields. Please refresh and try again.', 'gf-odoo-connector' ),
				'sampleFieldsEmpty'    => esc_html__( 'No mappable fields were found in that form.', 'gf-odoo-connector' ),
			)
		);
	}

	/**
	 * @return bool
	 */
	private function is_edit_screen_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['action'] ) && 'edit' === sanitize_key( (string) $_GET['action'] );
	}

	/**
	 * GF field choices for template "From field" dropdowns.
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function get_form_field_choices( int $form_id ): array {
		if ( $form_id <= 0 ) {
			return array();
		}

		$choices = array_values(
			array_filter(
				GF_Odoo_Addon::get_field_map_choices( $form_id ),
				static function ( $choice ) {
					return '' !== (string) rgar( $choice, 'value' );
				}
			)
		);

		if ( ! empty( $choices ) ) {
			return $choices;
		}

		if ( ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		$form = GFAPI::get_form( $form_id );
		if ( is_wp_error( $form ) || ! is_array( $form ) || ! class_exists( 'Field_Mapper' ) ) {
			return array();
		}

		return Field_Mapper::get_form_field_choices( $form );
	}

	/**
	 * Register AJAX handlers.
	 */
	public function register_ajax(): void {
		add_action( 'wp_ajax_gf_odoo_save_template', array( $this, 'ajax_save_template' ) );
		add_action( 'wp_ajax_gf_odoo_delete_template', array( $this, 'ajax_delete_template' ) );
		add_action( 'wp_ajax_gf_odoo_duplicate_template', array( $this, 'ajax_duplicate_template' ) );
		add_action( 'wp_ajax_gf_odoo_save_template_link', array( $this, 'ajax_save_template_link' ) );
		add_action( 'wp_ajax_gf_odoo_remove_template_override', array( $this, 'ajax_remove_template_override' ) );
		add_action( 'wp_ajax_gf_odoo_get_sample_form_fields', array( $this, 'ajax_get_sample_form_fields' ) );
		add_action( 'wp_ajax_gf_odoo_compute_remap', array( $this, 'ajax_compute_remap' ) );
	}

	/**
	 * Render templates page (list or edit).
	 */
	public function render_page(): void {
		if ( ! $this->addon->current_user_can_manage_plugin() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		$this->maybe_show_updated_notice();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : 'list';

		if ( 'edit' === $action ) {
			$this->render_edit_screen();
			return;
		}

		$this->render_list_screen();
	}

	/**
	 * Show notice after template save when forms have overrides.
	 */
	private function maybe_show_updated_notice(): void {
		$data = get_transient( Template_Manager::TRANSIENT_UPDATED_NOTICE );
		if ( ! is_array( $data ) ) {
			return;
		}

		delete_transient( Template_Manager::TRANSIENT_UPDATED_NOTICE );

		$count = (int) ( $data['linked_count'] ?? 0 );
		if ( $count <= 0 ) {
			return;
		}

		$url = add_query_arg(
			array(
				'page'   => 'gf_odoo_templates',
				'action' => 'edit',
				'id'     => (int) ( $data['template_id'] ?? 0 ),
			),
			admin_url( 'admin.php' )
		);

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			wp_kses(
				sprintf(
					/* translators: 1: number of forms, 2: link to template */
					__( 'Template updated. %1$d forms have overrides — <a href="%2$s">review linked forms</a>.', 'gf-odoo-connector' ),
					$count,
					esc_url( $url )
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}

	/**
	 * List all templates.
	 */
	private function render_list_screen(): void {
		$templates = Template_Manager::get_all();
		$edit_base = add_query_arg(
			array(
				'page'   => 'gf_odoo_templates',
				'action' => 'edit',
			),
			admin_url( 'admin.php' )
		);
		$new_url   = add_query_arg( 'id', 0, $edit_base );

		echo '<div class="gf-odoo-page-header">';
		echo '<a href="' . esc_url( $new_url ) . '" class="gf-odoo-btn gf-odoo-btn-primary">' . esc_html__( 'New template', 'gf-odoo-connector' ) . '</a>';
		echo '</div>';

		if ( empty( $templates ) ) {
			echo '<div class="gf-odoo-card"><div class="gf-odoo-card-body">';
			echo '<p class="gf-odoo-hint">' . esc_html__( 'No templates yet. Create one to reuse feed settings across multiple forms.', 'gf-odoo-connector' ) . '</p>';
			echo '</div></div>';
			return;
		}

		echo '<div class="gf-odoo-card"><div class="gf-odoo-card-body gf-odoo-card-body--flush">';
		echo '<table class="gf-odoo-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'gf-odoo-connector' ) . '</th>';
		echo '<th>' . esc_html__( 'Module', 'gf-odoo-connector' ) . '</th>';
		echo '<th>' . esc_html__( 'Sample form', 'gf-odoo-connector' ) . '</th>';
		echo '<th>' . esc_html__( 'Linked forms', 'gf-odoo-connector' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'gf-odoo-connector' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'gf-odoo-connector' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $templates as $template ) {
			$id     = (int) $template->id;
			$module = (string) $template->module;
			$badge  = 'helpdesk' === $module ? 'badge-helpdesk' : 'badge-crm';
			$label  = 'helpdesk' === $module
				? esc_html__( 'Helpdesk', 'gf-odoo-connector' )
				: esc_html__( 'CRM', 'gf-odoo-connector' );
			$count          = Template_Manager::count_linked_forms( $id );
			$edit           = add_query_arg( 'id', $id, $edit_base );
			$sample_form_id = (int) ( $template->sample_form_id ?? 0 );
			$sample_cell    = '';

			if ( $sample_form_id > 0 ) {
				$sample_cell = esc_html( $this->addon->get_form_title_for_dashboard( $sample_form_id ) );
			} else {
				$sample_cell = '<a href="' . esc_url( $edit ) . '" class="gf-odoo-badge badge-override">'
					. esc_html__( 'No sample form', 'gf-odoo-connector' ) . '</a>'
					. '<span class="gf-odoo-hint gf-odoo-hint-block">'
					. esc_html__( '"From field" mappings need a sample form.', 'gf-odoo-connector' )
					. '</span>';
			}

			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( (string) $template->name ) . '</a></strong></td>';
			echo '<td><span class="gf-odoo-badge ' . esc_attr( $badge ) . '">' . esc_html( $label ) . '</span></td>';
			echo '<td>' . $sample_cell . '</td>';
			echo '<td>' . esc_html(
				sprintf(
					/* translators: %d: number of forms */
					_n( '%d form', '%d forms', $count, 'gf-odoo-connector' ),
					$count
				)
			) . '</td>';
			echo '<td>' . esc_html( (string) $template->created_at ) . '</td>';
			echo '<td class="actions">';
			echo '<a class="gf-odoo-btn gf-odoo-btn-sm gf-odoo-btn-secondary" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'gf-odoo-connector' ) . '</a>';
			echo '<button type="button" class="gf-odoo-btn gf-odoo-btn-sm gf-odoo-btn-ghost gf-odoo-duplicate-template" data-id="' . esc_attr( (string) $id ) . '">' . esc_html__( 'Duplicate', 'gf-odoo-connector' ) . '</button>';
			echo '<button type="button" class="gf-odoo-btn gf-odoo-btn-sm gf-odoo-btn-danger gf-odoo-delete-template" data-id="' . esc_attr( (string) $id ) . '" data-linked="' . esc_attr( (string) $count ) . '">' . esc_html__( 'Delete', 'gf-odoo-connector' ) . '</button>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div></div>';
	}

	/**
	 * Edit / create template.
	 */
	private function render_edit_screen(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$template = $id > 0 ? Template_Manager::get( $id ) : null;
		$module         = $template ? (string) $template->module : 'crm';
		$name           = $template ? (string) $template->name : '';
		$meta           = $template ? (array) $template->feed_meta : array( 'module' => 'crm' );
		$sample_form_id = $template ? (int) ( $template->sample_form_id ?? 0 ) : 0;

		$feed = array(
			'meta' => $meta,
		);

		echo '<p class="gf-odoo-page-desc"><a href="' . esc_url( admin_url( 'admin.php?page=gf_odoo_templates' ) ) . '">&larr; ' . esc_html__( 'All templates', 'gf-odoo-connector' ) . '</a></p>';

		if ( $id > 0 ) {
			$linked = Template_Manager::get_linked_forms( $id );
			if ( ! empty( $linked ) ) {
				echo '<div class="gf-odoo-card">';
				echo '<div class="gf-odoo-card-header"><h3>' . esc_html__( 'Linked forms', 'gf-odoo-connector' ) . '</h3></div>';
				echo '<div class="gf-odoo-card-body"><ul>';
				foreach ( $linked as $link ) {
					$form_id    = (int) ( $link['form_id'] ?? 0 );
					$feed_id    = (int) ( $link['feed_id'] ?? 0 );
					$form_title = $this->addon->get_form_title_for_dashboard( $form_id );
					$feed_url   = $form_id > 0
						? admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=gf-odoo-connector&id=' . $form_id . '&fid=' . $feed_id )
						: '#';
					printf(
						'<li><a href="%1$s">%2$s</a> (feed #%3$d)</li>',
						esc_url( $feed_url ),
						esc_html( $form_title ),
						$feed_id
					);
				}
				echo '</ul></div></div>';
			}
		}

		echo '<form id="gf-odoo-template-form" class="gf-odoo-template-form">';
		echo '<input type="hidden" name="template_id" id="gf-odoo-template-id" value="' . esc_attr( (string) $id ) . '" />';

		echo '<div class="gf-odoo-card">';
		echo '<div class="gf-odoo-card-header"><h3>' . esc_html__( 'Template details', 'gf-odoo-connector' ) . '</h3></div>';
		echo '<div class="gf-odoo-card-body">';
		echo '<div class="gf-odoo-field">';
		echo '<label class="gf-odoo-label" for="gf-odoo-template-name">' . esc_html__( 'Name', 'gf-odoo-connector' ) . ' <span class="req">' . esc_html__( 'Required', 'gf-odoo-connector' ) . '</span></label>';
		echo '<input type="text" class="gf-odoo-input" id="gf-odoo-template-name" value="' . esc_attr( $name ) . '" required />';
		echo '</div>';
		echo '<div class="gf-odoo-field">';
		echo '<label class="gf-odoo-label" for="gf-odoo-template-module">' . esc_html__( 'Module', 'gf-odoo-connector' ) . '</label>';
		echo '<select class="gf-odoo-select" id="gf-odoo-template-module" name="module"' . ( $id > 0 ? ' disabled' : '' ) . '>';
		echo '<option value="crm"' . selected( $module, 'crm', false ) . '>' . esc_html__( 'CRM', 'gf-odoo-connector' ) . '</option>';
		echo '<option value="helpdesk"' . selected( $module, 'helpdesk', false ) . '>' . esc_html__( 'Helpdesk', 'gf-odoo-connector' ) . '</option>';
		echo '</select>';
		echo '</div>';

		$forms = array();
		if ( class_exists( 'GFFormsModel' ) ) {
			$forms = GFFormsModel::get_forms( true );
		} elseif ( class_exists( 'GFAPI' ) ) {
			$forms = GFAPI::get_forms( true, false );
		}

		echo '<div class="gf-odoo-field">';
		echo '<label class="gf-odoo-label" for="gf-odoo-sample-form">' . esc_html__( 'Sample form', 'gf-odoo-connector' );
		echo ' <span class="gf-odoo-label-note">' . esc_html__( '— used for "From field" dropdowns', 'gf-odoo-connector' ) . '</span></label>';
		echo '<select class="gf-odoo-select gf-odoo-sample-form-select" id="gf-odoo-sample-form" name="sample_form_id">';
		echo '<option value="">' . esc_html__( '— No sample form selected —', 'gf-odoo-connector' ) . '</option>';
		foreach ( $forms as $form ) {
			$form_obj = is_object( $form ) ? $form : (object) $form;
			$fid      = (int) ( $form_obj->id ?? 0 );
			if ( $fid <= 0 ) {
				continue;
			}
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				$fid,
				selected( $sample_form_id, $fid, false ),
				esc_html( (string) ( $form_obj->title ?? '' ) )
			);
		}
		echo '</select>';
		echo '<p class="gf-odoo-hint">' . esc_html__( 'Choose a form representative of all forms that will use this template. Its fields appear in the "From field" dropdowns below.', 'gf-odoo-connector' ) . '</p>';
		echo '<p id="gf-odoo-sample-form-notice" class="gf-odoo-notice gf-odoo-notice--warning"' . ( $sample_form_id > 0 ? ' hidden' : '' ) . '>';
		echo esc_html__( 'Select a sample form here before using "From field" mappings — otherwise field dropdowns stay empty.', 'gf-odoo-connector' );
		echo '</p>';
		echo '</div>';
		echo '</div></div>';

		echo '<div id="gf-odoo-template-fields" class="gf-odoo-template-field-config" data-module="' . esc_attr( $module ) . '">';
		if ( 'helpdesk' === $module ) {
			echo $this->addon->render_helpdesk_fields_editor_html( $feed, 0, true, $sample_form_id );
		} else {
			echo $this->addon->render_crm_fields_editor_html( $feed, 0, true, $sample_form_id );
		}
		echo '</div>';

		echo '<div class="gf-odoo-card-footer">';
		echo '<button type="button" class="gf-odoo-btn gf-odoo-btn-primary" id="gf-odoo-save-template">' . esc_html__( 'Save template', 'gf-odoo-connector' ) . '</button>';
		echo '</div>';
		echo '</form>';

		$this->print_template_editor_boot_script( $sample_form_id );
	}

	/**
	 * Inline boot script so sample-form → field dropdown works even if other assets fail.
	 *
	 * @param int $sample_form_id Saved sample form ID.
	 */
	private function print_template_editor_boot_script( int $sample_form_id ): void {
		$boot = array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'odooNonce'     => wp_create_nonce( 'gf_odoo_nonce' ),
			'selectField'   => __( '— Select a field —', 'gf-odoo-connector' ),
			'initialFields' => $this->get_form_field_choices( $sample_form_id ),
			'loadError'     => __( 'Could not load form fields. Please refresh and try again.', 'gf-odoo-connector' ),
			'emptyError'    => __( 'No mappable fields were found in that form.', 'gf-odoo-connector' ),
		);
		?>
		<script id="gf-odoo-template-editor-boot">
		(function () {
			var boot = <?php echo wp_json_encode( $boot ); ?>;
			window.gfOdooTemplatePage = Object.assign(window.gfOdooTemplatePage || {}, boot);

			function escHtml(s) {
				return String(s)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/"/g, '&quot;');
			}

			function buildOptions(fields, selectedId) {
				var html = '<option value="">' + escHtml(boot.selectField) + '</option>';
				var hasSelected = false;
				(fields || []).forEach(function (choice) {
					var value = String(choice.value || '');
					if (!value) {
						return;
					}
					var label = String(choice.label || '');
					var shortLabel = label.replace(/\s*\(field\s+\d+\)\s*$/i, '');
					var isSelected = String(selectedId) === value;
					if (isSelected) {
						hasSelected = true;
					}
					html +=
						'<option value="' +
						escHtml(value) +
						'" data-field-label="' +
						escHtml(shortLabel) +
						'"' +
						(isSelected ? ' selected' : '') +
						'>' +
						escHtml(label) +
						'</option>';
				});
				if (selectedId && !hasSelected) {
					html +=
						'<option value="' +
						escHtml(String(selectedId)) +
						'" selected>' +
						escHtml(String(selectedId)) +
						'</option>';
				}
				return html;
			}

			function applyFields(fields) {
				var root = document.getElementById('gf-odoo-template-fields');
				if (!root) {
					return;
				}
				var selects = root.querySelectorAll('.gf-odoo-gf-field-select');
				if (!fields || !fields.length) {
					return;
				}
				selects.forEach(function (select) {
					var current = select.value || '';
					select.classList.remove('gf-odoo-gf-field-select--needs-sample');
					select.innerHTML = buildOptions(fields, current);
				});
				var notice = document.getElementById('gf-odoo-sample-form-notice');
				if (notice) {
					notice.setAttribute('hidden', 'hidden');
				}
			}

			function loadFields(formId) {
				if (!formId) {
					return;
				}
				var body = new URLSearchParams({
					action: 'gf_odoo_get_sample_form_fields',
					nonce: boot.odooNonce,
					form_id: String(formId),
				});
				fetch(boot.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString(),
				})
					.then(function (r) {
						return r.text().then(function (text) {
							try {
								return JSON.parse(text);
							} catch (e) {
								return { success: false, data: { message: boot.loadError } };
							}
						});
					})
					.then(function (res) {
						if (!res || typeof res !== 'object') {
							window.alert(boot.loadError);
							return;
						}
						if (!res.success) {
							window.alert(
								(res.data && res.data.message) || boot.loadError
							);
							return;
						}
						var fields = (res.data && res.data.fields) || [];
						if (!fields.length) {
							window.alert(boot.emptyError);
							return;
						}
						applyFields(fields);
					})
					.catch(function () {
						window.alert(boot.loadError);
					});
			}

			function bindSampleForm() {
				var sample = document.getElementById('gf-odoo-sample-form');
				if (!sample) {
					return;
				}
				sample.addEventListener('change', function () {
					var notice = document.getElementById('gf-odoo-sample-form-notice');
					if (notice) {
						if (sample.value) {
							notice.setAttribute('hidden', 'hidden');
						} else {
							notice.removeAttribute('hidden');
						}
					}
					loadFields(parseInt(sample.value, 10) || 0);
				});
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', function () {
					bindSampleForm();
					if (boot.initialFields && boot.initialFields.length) {
						applyFields(boot.initialFields);
					}
				});
			} else {
				bindSampleForm();
				if (boot.initialFields && boot.initialFields.length) {
					applyFields(boot.initialFields);
				}
			}
		})();
		</script>
		<?php
	}

	/**
	 * @return void
	 */
	public function ajax_save_template(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->addon->current_user_can_manage_plugin() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'gf-odoo-connector' ) ), 403 );
		}

		$id         = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$module     = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : 'crm';
		$feed_meta      = array();
		$sample_form_id = isset( $_POST['sample_form_id'] ) ? absint( $_POST['sample_form_id'] ) : 0;

		if ( isset( $_POST['feed_meta_json'] ) ) {
			$decoded = json_decode( wp_unslash( (string) $_POST['feed_meta_json'] ), true );
			if ( is_array( $decoded ) ) {
				$feed_meta = $decoded;
			}
		} elseif ( isset( $_POST['feed_meta'] ) ) {
			$feed_meta = (array) wp_unslash( $_POST['feed_meta'] );
		}

		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Name is required.', 'gf-odoo-connector' ) ) );
		}

		if ( $id > 0 ) {
			$existing = Template_Manager::get( $id );
			if ( $existing && is_array( $existing->feed_meta ) ) {
				$feed_meta = array_merge( $existing->feed_meta, $feed_meta );
			}
		}

		$feed_meta           = $this->addon->sanitize_feed_meta_array( $feed_meta, $module, $sample_form_id );
		$feed_meta['module'] = $module;

		if ( 'helpdesk' === $module ) {
			$feed_meta['gf_odoo_helpdesk_config_v2'] = '1';
		} else {
			$feed_meta['gf_odoo_crm_config_v2'] = '1';
		}

		$new_id = Template_Manager::save(
			array(
				'id'             => $id,
				'name'           => $name,
				'module'         => $module,
				'feed_meta'      => $feed_meta,
				'sample_form_id' => $sample_form_id,
			)
		);

		if ( $new_id <= 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Template could not be saved. Check that the database tables exist (re-activate the plugin if needed).', 'gf-odoo-connector' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'id'       => $new_id,
				'message'  => __( 'Template saved.', 'gf-odoo-connector' ),
				'redirect' => add_query_arg(
					array(
						'page'   => 'gf_odoo_templates',
						'action' => 'edit',
						'id'     => $new_id,
					),
					admin_url( 'admin.php' )
				),
			)
		);
	}

	/**
	 * @return void
	 */
	public function ajax_delete_template(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->addon->current_user_can_manage_plugin() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'gf-odoo-connector' ) ), 403 );
		}

		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		if ( $id <= 0 ) {
			wp_send_json_error();
		}

		Template_Manager::delete( $id );
		wp_send_json_success();
	}

	/**
	 * @return void
	 */
	public function ajax_duplicate_template(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->addon->current_user_can_manage_plugin() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'gf-odoo-connector' ) ), 403 );
		}

		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid template.', 'gf-odoo-connector' ) ) );
		}

		$new_id = Template_Manager::duplicate( $id );
		if ( $new_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Could not duplicate template.', 'gf-odoo-connector' ) ) );
		}

		wp_send_json_success(
			array(
				'redirect' => add_query_arg(
					array(
						'page'   => 'gf_odoo_templates',
						'action' => 'edit',
						'id'     => $new_id,
					),
					admin_url( 'admin.php' )
				),
			)
		);
	}

	/**
	 * @return void
	 */
	public function ajax_save_template_link(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->addon->current_user_can_manage_plugin() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'gf-odoo-connector' ) ), 403 );
		}

		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$form_id     = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$feed_id     = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
		$overrides    = isset( $_POST['overrides'] ) ? (array) wp_unslash( $_POST['overrides'] ) : array();
		$field_remaps = array();
		$module       = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : 'crm';

		if ( isset( $_POST['field_remaps'] ) && is_array( $_POST['field_remaps'] ) ) {
			foreach ( (array) wp_unslash( $_POST['field_remaps'] ) as $remap_key => $remap_val ) {
				$clean_key = sanitize_key( (string) $remap_key );
				if ( '' === $clean_key ) {
					continue;
				}
				$field_remaps[ $clean_key ] = (string) absint( $remap_val );
			}
		}

		if ( $form_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing form or template.', 'gf-odoo-connector' ) ) );
		}

		if ( $feed_id <= 0 && $template_id > 0 ) {
			$key = 'gf_odoo_pending_tpl_' . get_current_user_id() . '_' . $form_id;
			set_transient(
				$key,
				array(
					'template_id'  => $template_id,
					'field_remaps' => $field_remaps,
					'overrides'    => $overrides,
					'module'       => $module,
				),
				HOUR_IN_SECONDS
			);

			wp_send_json_success(
				array(
					'pending' => true,
					'message' => __(
						'Template link queued. Save this feed with “Update Settings” — the template will be linked automatically.',
						'gf-odoo-connector'
					),
				)
			);
		}

		if ( $feed_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing feed.', 'gf-odoo-connector' ) ) );
		}

		if ( $template_id > 0 ) {
			$manual_clean = $this->addon->sanitize_feed_meta_array( $overrides, $module, $form_id );

			if ( empty( $field_remaps ) && empty( $manual_clean ) ) {
				$current_template = Template_Manager::get_linked_template_id( $form_id, $feed_id );
				if ( $current_template !== $template_id ) {
					Template_Manager::link_feed_to_template(
						$template_id,
						$form_id,
						$feed_id,
						Template_Manager::get_feed_overrides( $form_id, $feed_id )
					);
				}
			} else {
				$clean = Template_Manager::resolve_link_overrides( $template_id, $form_id, $feed_id, $field_remaps, $manual_clean );
				Template_Manager::link_feed_to_template( $template_id, $form_id, $feed_id, $clean );
			}
		} else {
			Template_Manager::unlink_feed( $form_id, $feed_id );
		}

		wp_send_json_success( array( 'message' => __( 'Template link saved.', 'gf-odoo-connector' ) ) );
	}

	/**
	 * Return GF field choices for a sample form (template editor).
	 */
	public function ajax_get_sample_form_fields(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->current_user_can_edit_forms() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'gf-odoo-connector' ) ), 403 );
		}

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			wp_send_json_success(
				array(
					'form_id'    => 0,
					'form_title' => '',
					'fields'     => array(),
				)
			);
		}

		$fields     = $this->get_form_field_choices( $form_id );
		$form_title = '';

		if ( class_exists( 'GFAPI' ) ) {
			$form = GFAPI::get_form( $form_id );
			if ( is_array( $form ) && ! is_wp_error( $form ) ) {
				$form_title = (string) rgar( $form, 'title' );
			}
		}

		if ( '' === $form_title && class_exists( 'GFFormsModel' ) ) {
			$meta = GFFormsModel::get_form_meta( $form_id );
			if ( is_array( $meta ) ) {
				$form_title = (string) rgar( $meta, 'title' );
			}
		}

		if ( empty( $fields ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No mappable fields were found in that form.', 'gf-odoo-connector' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'form_id'    => $form_id,
				'form_title' => $form_title,
				'fields'     => $fields,
			)
		);
	}

	/**
	 * Compute field remap preview when linking a template to a form.
	 */
	public function ajax_compute_remap(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->current_user_can_edit_forms() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'gf-odoo-connector' ) ), 403 );
		}

		$template_id    = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$target_form_id = isset( $_POST['target_form_id'] ) ? absint( $_POST['target_form_id'] ) : 0;

		if ( $template_id <= 0 || $target_form_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid template or form.', 'gf-odoo-connector' ) ) );
		}

		$result      = Template_Manager::compute_remap( $template_id, $target_form_id );
		$target_form = class_exists( 'GFAPI' ) ? GFAPI::get_form( $target_form_id ) : null;
		$choices     = is_array( $target_form ) ? Field_Mapper::get_form_field_choices( $target_form ) : array();
		$field_count = Template_Manager::count_field_mode_mappings( $template_id );

		wp_send_json_success(
			array(
				'remap'              => $result,
				'field_choices'      => $choices,
				'field_mapping_count' => $field_count,
				'has_unmatched'      => ! empty( $result['unmatched'] ),
				'summary'            => sprintf(
					/* translators: 1: matched count, 2: unmatched count, 3: identical count */
					__( '%1$d matched automatically, %2$d need manual mapping, %3$d identical (no override needed)', 'gf-odoo-connector' ),
					count( $result['matched'] ) + count( $result['identical'] ),
					count( $result['unmatched'] ),
					count( $result['identical'] )
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	private function current_user_can_edit_forms(): bool {
		return $this->addon->current_user_can_manage_plugin();
	}

	/**
	 * @return void
	 */
	public function ajax_remove_template_override(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->addon->current_user_can_manage_plugin() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'gf-odoo-connector' ) ), 403 );
		}

		$form_id  = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$feed_id  = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
		$meta_key = isset( $_POST['meta_key'] ) ? sanitize_key( wp_unslash( $_POST['meta_key'] ) ) : '';

		if ( $form_id <= 0 || $feed_id <= 0 || '' === $meta_key ) {
			wp_send_json_error();
		}

		$overrides = Template_Manager::get_feed_overrides( $form_id, $feed_id );
		unset( $overrides[ $meta_key ] );

		$mode_key = preg_replace( '/_value$/', '_mode', $meta_key );
		if ( $mode_key !== $meta_key ) {
			unset( $overrides[ $mode_key ] );
		}

		Template_Manager::save_overrides( $form_id, $feed_id, $overrides );
		wp_send_json_success();
	}
}
