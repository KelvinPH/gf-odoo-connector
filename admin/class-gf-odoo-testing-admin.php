<?php
/**
 * Testing tools and pre-launch checklist admin UI.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders test submission tools, scenario results, and the pre-launch checklist.
 */
class GF_Odoo_Testing_Admin {

	/**
	 * User meta key for checklist progress.
	 */
	public const CHECKLIST_META_KEY = 'gf_odoo_checklist_progress';

	/**
	 * User meta key for scenario test results.
	 */
	public const SCENARIO_RESULTS_META_KEY = 'gf_odoo_scenario_results';

	/**
	 * @var GF_Odoo_Addon
	 */
	private $addon;

	/**
	 * @param GF_Odoo_Addon $addon Add-on instance.
	 */
	public function __construct( GF_Odoo_Addon $addon ) {
		$this->addon = $addon;
	}

	/**
	 * Register AJAX handlers (also called from init_ajax).
	 */
	public function register_ajax(): void {
		add_action( 'wp_ajax_gf_odoo_send_test_submission', array( $this, 'ajax_send_test_submission' ) );
		add_action( 'wp_ajax_gf_odoo_get_test_feeds', array( $this, 'ajax_get_test_feeds' ) );
		add_action( 'wp_ajax_gf_odoo_save_checklist_item', array( $this, 'ajax_save_checklist_item' ) );
	}

	/**
	 * Pre-launch checklist item definitions.
	 *
	 * @return array<string, string>
	 */
	public static function get_checklist_items(): array {
		return array(
			'crm_contact'      => __( 'CRM: New form submission creates a contact in Odoo (res.partner)', 'gf-odoo-connector' ),
			'crm_lead'         => __( 'CRM: New form submission creates a lead linked to the contact', 'gf-odoo-connector' ),
			'crm_duplicate'    => __( 'CRM: Second submission with same email updates existing contact, creates new lead', 'gf-odoo-connector' ),
			'crm_country'      => __( 'CRM: Country field resolves to correct Odoo country_id', 'gf-odoo-connector' ),
			'crm_industry'     => __( 'CRM: Industry field resolves to correct Odoo industry_id', 'gf-odoo-connector' ),
			'crm_source'       => __( 'CRM: Source field is auto-filled with the page URL', 'gf-odoo-connector' ),
			'helpdesk_ticket'  => __( 'Helpdesk: Form submission creates a ticket in the correct team', 'gf-odoo-connector' ),
			'helpdesk_contact' => __( 'Helpdesk: Contact is linked to the ticket by email lookup', 'gf-odoo-connector' ),
			'async_submit'     => __( 'Form submission returns instantly, with no waiting for Odoo', 'gf-odoo-connector' ),
			'retry_auto'       => __( 'Auto-retry: break Odoo URL, submit form, fix URL, and the ticket syncs automatically', 'gf-odoo-connector' ),
			'retry_manual'     => __( 'Manual retry: Failed entry retries correctly from error log', 'gf-odoo-connector' ),
			'error_log'        => __( 'Error log shows correct date, status, and error message', 'gf-odoo-connector' ),
			'template_link'    => __( 'Template: Linking a form to a template syncs correctly', 'gf-odoo-connector' ),
			'template_override'=> __( 'Template: Override on one form does not affect other forms', 'gf-odoo-connector' ),
			'webhook'          => __( 'Webhook: Changing ticket stage in Odoo updates GF entry notes', 'gf-odoo-connector' ),
			'api_key'          => __( 'Security: API key is stored encrypted (verify in wp_options)', 'gf-odoo-connector' ),
			'test_mode_off'    => __( 'Test mode is disabled before going live', 'gf-odoo-connector' ),
			'debug_log_clean'  => __( 'PHP error log shows no debug dumps after a form submission', 'gf-odoo-connector' ),
		);
	}

	/**
	 * Scenario labels for the results table.
	 *
	 * @return array<string, string>
	 */
	public static function get_scenario_labels(): array {
		return array(
			'normal'           => __( 'Normal test submission', 'gf-odoo-connector' ),
			'bad_url'          => __( 'Invalid Odoo URL', 'gf-odoo-connector' ),
			'bad_api_key'      => __( 'Invalid API key', 'gf-odoo-connector' ),
			'missing_required' => __( 'Missing required lead/contact name', 'gf-odoo-connector' ),
			'duplicate'        => __( 'Duplicate email (same contact)', 'gf-odoo-connector' ),
			'same_company'     => __( 'Same company, different contacts', 'gf-odoo-connector' ),
		);
	}

	/**
	 * Render testing tools page (test submission + scenario results).
	 */
	public function render_testing_page(): void {
		if ( ! $this->addon->current_user_can_manage_plugin() && ! current_user_can( 'gravityforms_edit_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		$forms = $this->get_forms_with_odoo_feeds();

		$this->addon->render_admin_page_open( __( 'Testing tools', 'gf-odoo-connector' ), 'gf-odoo-page--wide' );
		?>
		<p class="gf-odoo-page-desc">
			<?php esc_html_e( 'Send a test submission through the full Odoo sync pipeline. Test records are prefixed with [TEST] and use placeholder field values.', 'gf-odoo-connector' ); ?>
		</p>

		<div class="gf-odoo-card gf-odoo-test-submission-card">
			<div class="gf-odoo-card-header">
				<h2 class="gf-odoo-card-title"><?php esc_html_e( 'Send test submission', 'gf-odoo-connector' ); ?></h2>
			</div>
			<div class="gf-odoo-card-body">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gf-odoo-test-form"><?php esc_html_e( 'Form', 'gf-odoo-connector' ); ?></label></th>
						<td>
							<select id="gf-odoo-test-form" class="regular-text">
								<option value=""><?php esc_html_e( 'Select a form', 'gf-odoo-connector' ); ?></option>
								<?php foreach ( $forms as $form ) : ?>
									<option value="<?php echo esc_attr( (string) $form['id'] ); ?>"><?php echo esc_html( $form['title'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gf-odoo-test-feed"><?php esc_html_e( 'Feed', 'gf-odoo-connector' ); ?></label></th>
						<td>
							<select id="gf-odoo-test-feed" class="regular-text" disabled>
								<option value=""><?php esc_html_e( 'Select a feed', 'gf-odoo-connector' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gf-odoo-test-scenario"><?php esc_html_e( 'Scenario', 'gf-odoo-connector' ); ?></label></th>
						<td>
							<select id="gf-odoo-test-scenario" class="regular-text">
								<?php foreach ( self::get_scenario_labels() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Failure scenarios temporarily override connection settings and are restored after the test.', 'gf-odoo-connector' ); ?></p>
						</td>
					</tr>
				</table>
				<p>
					<button type="button" class="button button-primary" id="gf-odoo-send-test-submission">
						<?php esc_html_e( 'Send test submission', 'gf-odoo-connector' ); ?>
					</button>
					<span id="gf-odoo-test-submission-result" class="gf-odoo-test-result" style="margin-left:8px;" role="status" aria-live="polite"></span>
				</p>
			</div>
		</div>

		<?php $this->render_scenario_results_table(); ?>

		<?php
		$this->addon->render_admin_page_close();
	}

	/**
	 * Render pre-launch checklist page.
	 */
	public function render_checklist_page(): void {
		if ( ! $this->addon->current_user_can_manage_plugin() && ! current_user_can( 'gravityforms_edit_forms' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'gf-odoo-connector' ), '', array( 'response' => 403 ) );
		}

		$user_id  = get_current_user_id();
		$progress = get_user_meta( $user_id, self::CHECKLIST_META_KEY, true );
		if ( ! is_array( $progress ) ) {
			$progress = array();
		}

		$this->addon->render_admin_page_open( __( 'Pre-launch checklist', 'gf-odoo-connector' ) );
		?>
		<p class="gf-odoo-page-desc">
			<?php esc_html_e( 'Verify each item before going live. Progress is saved per user.', 'gf-odoo-connector' ); ?>
		</p>
		<div class="gf-odoo-card">
			<div class="gf-odoo-card-body">
				<ul class="gf-odoo-checklist">
					<?php foreach ( self::get_checklist_items() as $key => $label ) : ?>
						<?php $checked = ! empty( $progress[ $key ] ); ?>
						<li>
							<label>
								<input
									type="checkbox"
									class="gf-odoo-checklist-item"
									data-item="<?php echo esc_attr( $key ); ?>"
									<?php checked( $checked ); ?>
								/>
								<?php echo esc_html( $label ); ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
		$this->addon->render_admin_page_close();
	}

	/**
	 * @return array<int, array{id: int, title: string}>
	 */
	private function get_forms_with_odoo_feeds(): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		$forms = GFAPI::get_forms();
		if ( ! is_array( $forms ) ) {
			return array();
		}

		$out = array();

		foreach ( $forms as $form ) {
			$form_id = (int) rgar( $form, 'id' );
			$feeds   = $this->addon->get_feeds( $form_id );

			if ( empty( $feeds ) ) {
				continue;
			}

			$out[] = array(
				'id'    => $form_id,
				'title' => (string) rgar( $form, 'title', '#' . $form_id ),
			);
		}

		return $out;
	}

	/**
	 * Scenario results table markup.
	 */
	private function render_scenario_results_table(): void {
		$results = get_user_meta( get_current_user_id(), self::SCENARIO_RESULTS_META_KEY, true );
		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$labels = self::get_scenario_labels();
		?>
		<div class="gf-odoo-card" style="margin-top: 20px;">
			<div class="gf-odoo-card-header">
				<h2 class="gf-odoo-card-title"><?php esc_html_e( 'Scenario test results', 'gf-odoo-connector' ); ?></h2>
			</div>
			<div class="gf-odoo-card-body gf-odoo-card-body--flush">
				<table class="gf-odoo-table" id="gf-odoo-scenario-results">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Scenario', 'gf-odoo-connector' ); ?></th>
							<th><?php esc_html_e( 'Last run', 'gf-odoo-connector' ); ?></th>
							<th><?php esc_html_e( 'Result', 'gf-odoo-connector' ); ?></th>
							<th><?php esc_html_e( 'Message', 'gf-odoo-connector' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $labels as $key => $label ) : ?>
							<?php
							$row     = isset( $results[ $key ] ) && is_array( $results[ $key ] ) ? $results[ $key ] : array();
							$passed  = ! empty( $row['passed'] );
							$message = isset( $row['message'] ) ? (string) $row['message'] : '-';
							$ran_at  = isset( $row['ran_at'] ) ? (string) $row['ran_at'] : '-';
							$status  = isset( $row['status'] ) ? (string) $row['status'] : ( ! empty( $row ) ? ( $passed ? 'pass' : 'fail' ) : 'pending' );
							?>
							<tr data-scenario="<?php echo esc_attr( $key ); ?>">
								<td><?php echo esc_html( $label ); ?></td>
								<td class="gf-odoo-scenario-ran-at"><?php echo esc_html( $ran_at ); ?></td>
								<td class="gf-odoo-scenario-status">
									<?php
									if ( 'pending' === $status || '-' === $ran_at ) {
										echo '<span class="gf-odoo-badge">' . esc_html__( 'Not run', 'gf-odoo-connector' ) . '</span>';
									} elseif ( $passed ) {
										echo '<span class="gf-odoo-badge badge-crm">' . esc_html__( 'Pass', 'gf-odoo-connector' ) . '</span>';
									} else {
										echo '<span class="gf-odoo-badge" style="background:#d63638;color:#fff;">' . esc_html__( 'Fail', 'gf-odoo-connector' ) . '</span>';
									}
									?>
								</td>
								<td class="gf-odoo-scenario-message"><?php echo esc_html( $message ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: feeds for a form.
	 */
	public function ajax_get_test_feeds(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->can_run_tests() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'gf-odoo-connector' ) ), 403 );
		}

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			wp_send_json_success( array( 'feeds' => array() ) );
		}

		$feeds = array();

		foreach ( $this->addon->get_feeds( $form_id ) as $feed ) {
			if ( empty( $feed['is_active'] ) ) {
				continue;
			}

			$module = $this->addon->get_feed_module( $feed );
			if ( ! in_array( $module, array( 'crm', 'helpdesk' ), true ) ) {
				continue;
			}

			$feeds[] = array(
				'id'     => (int) rgar( $feed, 'id' ),
				'label'  => sprintf(
					'%s (#%d)',
					'helpdesk' === $module ? __( 'Helpdesk', 'gf-odoo-connector' ) : __( 'CRM', 'gf-odoo-connector' ),
					(int) rgar( $feed, 'id' )
				),
				'module' => $module,
			);
		}

		wp_send_json_success( array( 'feeds' => $feeds ) );
	}

	/**
	 * AJAX: run test submission.
	 */
	public function ajax_send_test_submission(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->can_run_tests() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'gf-odoo-connector' ) ), 403 );
		}

		$form_id  = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$feed_id  = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
		$scenario = isset( $_POST['scenario'] ) ? sanitize_key( (string) wp_unslash( $_POST['scenario'] ) ) : 'normal';

		$result = $this->addon->run_test_submission( $form_id, $feed_id, $scenario );

		$this->store_scenario_result( $scenario, $result );

		$labels = self::get_scenario_labels();
		if ( isset( $labels[ $scenario ] ) ) {
			$stored = get_user_meta( get_current_user_id(), self::SCENARIO_RESULTS_META_KEY, true );
			if ( is_array( $stored ) && isset( $stored[ $scenario ] ) ) {
				$result['scenario_row'] = array_merge(
					array( 'key' => $scenario ),
					$stored[ $scenario ]
				);
			}
		}

		if ( ! empty( $result['success'] ) || ! empty( $result['duplicate_ok'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * AJAX: save checklist checkbox state.
	 */
	public function ajax_save_checklist_item(): void {
		check_ajax_referer( 'gf_odoo_nonce', 'nonce' );

		if ( ! $this->can_run_tests() ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'gf-odoo-connector' ) ), 403 );
		}

		$item    = isset( $_POST['item'] ) ? sanitize_key( (string) wp_unslash( $_POST['item'] ) ) : '';
		$checked = ! empty( $_POST['checked'] );
		$items   = self::get_checklist_items();

		if ( '' === $item || ! isset( $items[ $item ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid checklist item.', 'gf-odoo-connector' ) ) );
		}

		$user_id  = get_current_user_id();
		$progress = get_user_meta( $user_id, self::CHECKLIST_META_KEY, true );
		if ( ! is_array( $progress ) ) {
			$progress = array();
		}

		if ( $checked ) {
			$progress[ $item ] = 1;
		} else {
			unset( $progress[ $item ] );
		}

		update_user_meta( $user_id, self::CHECKLIST_META_KEY, $progress );

		wp_send_json_success();
	}

	/**
	 * @param string               $scenario Scenario key.
	 * @param array<string, mixed> $result   Test result.
	 */
	private function store_scenario_result( string $scenario, array $result ): void {
		$labels = self::get_scenario_labels();
		if ( ! isset( $labels[ $scenario ] ) ) {
			return;
		}

		$results = get_user_meta( get_current_user_id(), self::SCENARIO_RESULTS_META_KEY, true );
		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$expect_fail = in_array( $scenario, array( 'bad_url', 'bad_api_key', 'missing_required' ), true );

		if ( 'duplicate' === $scenario || 'same_company' === $scenario ) {
			$passed = ! empty( $result['duplicate_ok'] );
		} elseif ( $expect_fail ) {
			$passed = empty( $result['success'] ) && ! empty( $result['error_logged'] );
		} else {
			$passed = ! empty( $result['success'] );
		}

		$results[ $scenario ] = array(
			'passed'  => $passed,
			'status'  => $passed ? 'pass' : 'fail',
			'message' => isset( $result['message'] ) ? (string) $result['message'] : '',
			'ran_at'  => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		);

		update_user_meta( get_current_user_id(), self::SCENARIO_RESULTS_META_KEY, $results );
	}

	/**
	 * @return bool
	 */
	private function can_run_tests(): bool {
		return $this->addon->current_user_can_manage_plugin() || current_user_can( 'gravityforms_edit_forms' );
	}
}
