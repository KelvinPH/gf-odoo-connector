<?php
/**
 * Admin list table for Odoo sync errors.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays failed sync rows in wp-admin.
 */
class GF_Odoo_Errors_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'gf_odoo_error',
				'plural'   => 'gf_odoo_errors',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array
	 */
	protected function get_table_classes() {
		return array( 'widefat', 'fixed', 'striped', 'gf-odoo-error-table' );
	}

	/**
	 * Single thead; tfoot for bulk actions only (no duplicate header row styling).
	 */
	public function display(): void {
		$this->display_tablenav( 'top' );

		echo '<table class="wp-list-table ' . esc_attr( implode( ' ', $this->get_table_classes() ) ) . '">';
		echo '<thead>';
		$this->print_column_headers();
		echo '</thead>';
		echo '<tbody id="the-list">';
		$this->display_rows_or_placeholder();
		echo '</tbody>';
		echo '<tfoot>';
		$this->print_column_headers( false );
		echo '</tfoot>';
		echo '</table>';

		$this->display_tablenav( 'bottom' );
	}

	/**
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'created_at'    => esc_html__( 'Date', 'gf-odoo-connector' ),
			'form_id'       => esc_html__( 'Form', 'gf-odoo-connector' ),
			'entry_id'      => esc_html__( 'Entry', 'gf-odoo-connector' ),
			'module'        => esc_html__( 'Module', 'gf-odoo-connector' ),
			'attempt'       => esc_html__( 'Attempt', 'gf-odoo-connector' ),
			'sync_status'   => esc_html__( 'Status', 'gf-odoo-connector' ),
			'error'         => esc_html__( 'Error', 'gf-odoo-connector' ),
			'error_actions' => esc_html__( 'Actions', 'gf-odoo-connector' ),
		);
	}

	/**
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'form_id'    => array( 'form_id', false ),
			'module'     => array( 'module', false ),
		);
	}

	/**
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'mark_resolved' => esc_html__( 'Mark resolved', 'gf-odoo-connector' ),
		);
	}

	/**
	 * @param array $item Row.
	 *
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="error_ids[]" value="%d" />',
			(int) $item['id']
		);
	}

	/**
	 * @param array $item Row.
	 *
	 * @return string
	 */
	protected function column_created_at( $item ) {
		$raw = (string) ( $item['created_at'] ?? '' );

		if ( '' === $raw || str_starts_with( $raw, '0000-' ) ) {
			return '<span class="gf-odoo-empty-cell">—</span>';
		}

		$timestamp = strtotime( $raw );

		if ( ! $timestamp || $timestamp <= 0 ) {
			$local = get_date_from_gmt( $raw );
			if ( $local ) {
				$timestamp = strtotime( $local );
			}
		}

		if ( ! $timestamp || $timestamp <= 0 ) {
			return '<span class="gf-odoo-empty-cell">—</span>';
		}

		$formatted = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
		$ago       = human_time_diff( $timestamp, (int) current_time( 'timestamp' ) ) . ' ' . esc_html__( 'ago', 'gf-odoo-connector' );

		return '<span class="gf-odoo-error-date" title="' . esc_attr( $ago ) . '">' . esc_html( $formatted ) . '</span>';
	}

	/**
	 * @param array $item Row.
	 *
	 * @return string
	 */
	protected function column_form_id( $item ) {
		$form_id = (int) $item['form_id'];

		if ( $form_id <= 0 || ! class_exists( 'GFAPI' ) ) {
			return '<span class="gf-odoo-empty-cell">—</span>';
		}

		$form = GFAPI::get_form( $form_id );

		if ( is_wp_error( $form ) || empty( $form ) ) {
			return esc_html( sprintf( '#%d', $form_id ) );
		}

		$title = (string) rgar( $form, 'title' );

		return esc_html( '' !== $title ? $title : sprintf( '#%d', $form_id ) );
	}

	/**
	 * @param array $item Row.
	 *
	 * @return string
	 */
	protected function column_entry_id( $item ) {
		$entry_id = (int) $item['entry_id'];
		$form_id  = (int) $item['form_id'];

		if ( $entry_id <= 0 || $form_id <= 0 ) {
			return '<span class="gf-odoo-empty-cell">—</span>';
		}

		$url = admin_url(
			sprintf(
				'admin.php?page=gf_entries&view=entry&id=%d&lid=%d',
				$form_id,
				$entry_id
			)
		);

		return sprintf(
			'<a href="%s">#%d</a>',
			esc_url( $url ),
			$entry_id
		);
	}

	/**
	 * @param array $item Row.
	 *
	 * @return string
	 */
	protected function column_module( $item ) {
		$module = (string) $item['module'];

		if ( 'crm' === $module ) {
			return '<span class="gf-odoo-badge badge-crm">' . esc_html__( 'CRM', 'gf-odoo-connector' ) . '</span>';
		}

		if ( 'helpdesk' === $module ) {
			return '<span class="gf-odoo-badge badge-helpdesk">' . esc_html__( 'Helpdesk', 'gf-odoo-connector' ) . '</span>';
		}

		return esc_html( $module );
	}

	/**
	 * @param array $item Row.
	 *
	 * @return string
	 */
	protected function column_attempt( $item ) {
		$attempt = isset( $item['attempt'] ) ? max( 1, (int) $item['attempt'] ) : 1;
		$max     = class_exists( 'GF_Odoo_Async_Sync' ) ? GF_Odoo_Async_Sync::MAX_ATTEMPTS : 4;

		return esc_html(
			sprintf(
				/* translators: 1: current attempt, 2: max attempts */
				__( '%1$d / %2$d', 'gf-odoo-connector' ),
				$attempt,
				$max
			)
		);
	}

	/**
	 * @param array $item Row.
	 *
	 * @return string
	 */
	protected function column_sync_status( $item ) {
		if ( ! empty( $item['resolved'] ) ) {
			return '<span class="gf-odoo-badge badge-success">' . esc_html__( 'Resolved', 'gf-odoo-connector' ) . '</span>';
		}

		$entry_id = (int) $item['entry_id'];
		$feed_id  = (int) $item['feed_id'];
		$status   = 'pending';

		if ( $entry_id > 0 && function_exists( 'gform_get_meta' ) ) {
			$status = (string) gform_get_meta( $entry_id, 'odoo_sync_status' );
		}

		if ( '' === $status ) {
			$status = 'pending';
		}

		$labels = array(
			'pending'  => array(
				'label' => __( 'Pending', 'gf-odoo-connector' ),
				'class' => 'badge-pending',
			),
			'retrying' => array(
				'label' => __( 'Retrying', 'gf-odoo-connector' ),
				'class' => 'badge-retrying',
			),
			'success'  => array(
				'label' => __( 'Synced', 'gf-odoo-connector' ),
				'class' => 'badge-success',
			),
			'failed'   => array(
				'label' => __( 'Failed', 'gf-odoo-connector' ),
				'class' => 'badge-failed',
			),
		);

		$cfg  = $labels[ $status ] ?? $labels['failed'];
		$html = '<span class="gf-odoo-badge ' . esc_attr( $cfg['class'] ) . '">' . esc_html( $cfg['label'] ) . '</span>';

		if ( 'retrying' === $status ) {
			$countdown = $this->get_retry_countdown_text( $entry_id, $feed_id );
			if ( '' !== $countdown ) {
				$html .= '<div class="gf-odoo-status-sub">' . esc_html( $countdown ) . '</div>';
			}
		}

		return $html;
	}

	/**
	 * Short retry countdown for the status column.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 *
	 * @return string
	 */
	private function get_retry_countdown_text( int $entry_id, int $feed_id ): string {
		$retry_at = class_exists( 'GF_Odoo_Async_Sync' )
			? GF_Odoo_Async_Sync::get_next_run_timestamp( $entry_id, $feed_id )
			: null;

		if ( null === $retry_at || $retry_at <= 0 ) {
			return '';
		}

		return sprintf(
			/* translators: %s: human-readable time until retry */
			__( 'Retry in %s', 'gf-odoo-connector' ),
			human_time_diff( (int) current_time( 'timestamp' ), $retry_at )
		);
	}

	/**
	 * @param array $item Row.
	 *
	 * @return string
	 */
	protected function column_error( $item ) {
		$code    = ! empty( $item['error_code'] ) ? (string) $item['error_code'] : '';
		$message = (string) ( $item['error_message'] ?? '' );
		$short   = wp_trim_words( $message, 10, '…' );

		$html = '';

		if ( '' !== $code ) {
			$html .= '<span class="gf-odoo-error-code">' . esc_html( $code ) . '</span>';
		}

		if ( '' !== $short ) {
			$html .= '<div class="gf-odoo-error-message" title="' . esc_attr( $message ) . '">' . esc_html( $short ) . '</div>';
		}

		return $html !== '' ? $html : '<span class="gf-odoo-empty-cell">—</span>';
	}

	/**
	 * @param array $item Row.
	 *
	 * @return string
	 */
	protected function column_error_actions( $item ) {
		$error_id    = (int) $item['id'];
		$resolved    = ! empty( $item['resolved'] );
		$entry_id    = (int) $item['entry_id'];
		$sync_status = '';

		if ( $entry_id > 0 && function_exists( 'gform_get_meta' ) ) {
			$sync_status = (string) gform_get_meta( $entry_id, 'odoo_sync_status' );
		}

		$html = '<div class="gf-odoo-table-actions">';

		if ( $resolved ) {
			$html .= '<span class="gf-odoo-actions-note">' . esc_html__( 'Resolved', 'gf-odoo-connector' ) . '</span>';
		} else {
			if ( 'failed' === $sync_status || '' === $sync_status || 'pending' === $sync_status ) {
				$html .= sprintf(
					'<button type="button" class="gf-odoo-btn gf-odoo-btn-sm gf-odoo-btn-primary gf-odoo-retry-error" data-error-id="%1$d">%2$s</button>',
					$error_id,
					esc_html__( 'Retry now', 'gf-odoo-connector' )
				);
			} elseif ( 'retrying' === $sync_status ) {
				$html .= '<span class="gf-odoo-actions-note">' . esc_html__( 'Auto-retry scheduled', 'gf-odoo-connector' ) . '</span>';
			}

			$html .= sprintf(
				'<button type="button" class="gf-odoo-btn gf-odoo-btn-sm gf-odoo-btn-secondary gf-odoo-resolve-error" data-error-id="%1$d">%2$s</button>',
				$error_id,
				esc_html__( 'Mark resolved', 'gf-odoo-connector' )
			);
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * @param array  $item        Row.
	 * @param string $column_name Column key.
	 *
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items() {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$show_resolved = isset( $_GET['show_resolved'] ) && '1' === $_GET['show_resolved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$filters = array(
			'limit'  => $per_page,
			'offset' => $offset,
		);

		if ( ! $show_resolved ) {
			$filters['resolved'] = false;
		}

		$this->items = Error_Logger::get_errors( $filters );

		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = array( $columns, $hidden, $sortable, 'created_at' );

		$count_filters = array();
		if ( ! $show_resolved ) {
			$count_filters['resolved'] = false;
		}

		$total_items = Error_Logger::count_errors( $count_filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Message when no errors match the current filter.
	 */
	public function no_items() {
		esc_html_e( 'No sync errors found.', 'gf-odoo-connector' );
	}

	/**
	 * Extra filters above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$show_resolved = isset( $_GET['show_resolved'] ) && '1' === $_GET['show_resolved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base_url      = class_exists( 'GF_Odoo_Admin_Menu' )
			? GF_Odoo_Admin_Menu::url( 'gf_odoo_errors' )
			: admin_url( 'admin.php?page=gf_odoo_errors' );
		?>
		<div class="alignleft actions">
			<a href="<?php echo esc_url( $show_resolved ? $base_url : add_query_arg( 'show_resolved', '1', $base_url ) ); ?>" class="button">
				<?php
				if ( $show_resolved ) {
					esc_html_e( 'Hide resolved', 'gf-odoo-connector' );
				} else {
					esc_html_e( 'Show resolved', 'gf-odoo-connector' );
				}
				?>
			</a>
		</div>
		<?php
	}
}
