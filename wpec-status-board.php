<?php
/*
Plugin Name: WP e-Commerce - Status Board
Plugin URI: http://kungfugrep.com
Description: Creates an endpoint for your WP e-Commerce stats for use on the Status Board iPad app
Version: 1.0
Author: Chris Klosowski
Author URI: http://kungfugrep.com
License: GPLv2
*/

function wpecsb_add_endpoint( $rewrite_rules ) {
	add_rewrite_endpoint( 'wpecsb-stats', EP_ALL );
}
add_action( 'init', 'wpecsb_add_endpoint' );

function wpecsb_activation_tasks() {
	// Flush the rules so the wpecsb-stats endpoint is found
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wpecsb_activation_tasks' );

function wpecsb_deactivation_hook() {
	// Flush the rules since we no longer have the endpoint
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wpecsb_deactivation_tasks' );

function wpecsb_query_vars( $vars ) {
	$vars[] = 'wpecsb-verify';

	return $vars;
}
add_filter( 'query_vars', 'wpecsb_query_vars' );

function wpecsb_process_request() {
	global $wp_query;
	if ( ! isset( $wp_query->query_vars['wpecsb-stats'] ) )
		return;

	if ( ! isset( $wp_query->query_vars['wpecsb-verify'] ) ) {
		wpecsb_output( array( 'graph' => 
								array( 'title' => 'WP e-Commerce Stats Error', 
										'error' => 
											array( 'message' => 'No Verification Token', 
													'detail' => 'Please use the supplied link in wp-admin to add this graph to Status Board' ) ) ) );
	}

	if ( $wp_query->query_vars['wpecsb-verify'] && $wp_query->query_vars['wpecsb-verify'] != sha1( wp_salt() ) ) {
		wpecsb_output( array( 'graph' => 
								array( 'title' => 'WP e-Commerce Stats Error', 
										'error' => 
											array( 'message' => 'Invalid Verification Token', 
													'detail' => 'Please use the supplied link in wp-admin to add this graph to Status Board' ) ) ) );
	}

	global $wpdb;
	$where_no_filter = implode( ' AND ', $where );

	$where[] = "processed NOT IN (1,2,6)";
	$where[] = "YEAR(FROM_UNIXTIME(date)) = " . esc_sql( date( 'Y' ) );
	$where[] = "MONTH(FROM_UNIXTIME(date)) = " . esc_sql( date( 'm' ) );
	$date_from = date( 'd', strtotime( '-7 days' ) );
	$date_to   = date( 'd' );
	$where[] = "DAY(FROM_UNIXTIME(date)) BETWEEN " . $date_from . " AND " . $date_to;
	
	$where 	 = implode( ' AND ', $where );

	$total_sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(date), '%m/%e') as date, COUNT(id) as sales, SUM(totalprice) as earnings FROM " . WPSC_TABLE_PURCHASE_LOGS . " AS p	WHERE {$where} GROUP BY date";

	$sales_data = $wpdb->get_results( $total_sql );
	$data_sequences = wpec_statusboard_format_datapoints( $sales_data );

	$statusboard_data = array();
	$statusboard_data['graph']['title'] = get_bloginfo( 'name' ) . ' - ' . __( 'Sales & Earnings', 'wpec-statusboard-txt' );
	$statusboard_data['graph']['datasequences'] = $data_sequences;

	wpecsb_output( apply_filters( 'wpecsb_output', $statusboard_data ) );
}
add_action( 'template_redirect', 'wpecsb_process_request', -1 );

function wpecsb_output( $output ) {
	ob_end_clean();
	header( 'Content-Type: application/json' );
	echo json_encode( $output );
	exit;
}

function wpecsb_add_to_statusboard() {
	$key = sha1( wp_salt() );
	$sb_url = get_bloginfo( 'url' ) . '/wpecsb-stats/?wpecsb-verify=' . $key;
	?>
	<p>
		<a class="button secondary" id="sbsales" href="panicboard://?url=<?php echo urlencode( $sb_url ); ?>&panel=graph"><?php _e( 'Add WP e-Commerce Stats to Status Board', 'wpec-statusboard-txt' ); ?></a>
	</p>
	<?php
}
add_action( 'wpsc_purchase_logs_list_table_after', 'wpecsb_add_to_statusboard' );

function wpecsb_help_menu( $contextual_help, $screen_id, $screen ) {
	if ( $screen_id == 'dashboard_page_wpsc-purchase-logs' ) {
		$key = sha1( wp_salt() );
		$sb_url = get_bloginfo( 'url' ) . '/wpecsb-stats/?wpecsb-verify=' . $key;
		$troubleshooting  = '<ol>';
		$troubleshooting .= '<li>' . __( 'To add the graph manually, copy this URL and add it to your Status Board iPad app.', 'wpec-statusboard-txt' ) . '<br />';
		$troubleshooting .= '<span id="wpecsb-key_url"><pre>' . $sb_url . '</pre></span></li>';
		$troubleshooting .= '<li>' . sprintf( __( 'If this gives you a \'404\' error, try clicking \'Save Changes\' on the <a href="%s">Permalinks</a> page to help flush the rewrite rules, and re-add the graph', 'wpec-statusboard-txt' ), admin_url( 'options-permalink.php' ) ) . '</li>';
		$troubleshooting .= '<li>' . sprintf( __( 'If you are still having problems, you can <a href="%s" target="new">submit a support request</a> and we can try and solve the issue.', 'wpec-statusboard-txt' ), 'http://wordpress.org/support/plugin/jetpack-status-board' ) . '</li>';
		$troubleshooting .= '</ol>';

		// Add if current screen is My Admin Page
		$screen->add_help_tab( array(
			'id'	=> 'wpecsb_troubleshooting',
			'title'	=> __( 'Status Board', 'wpec-statusboard-txt' ),
			'content'	=> '<p>' . $troubleshooting . '</p>',
		) );
	}
	return $contextual_help;
}
add_filter( 'contextual_help', 'wpecsb_help_menu', 10, 3 );

function wpec_statusboard_format_datapoints( $datapoints ) {
	$sales    = array( 'title' => __( 'Sales', 'wpec-statusboard-txt' ), 'color' => 'orange' );
	$earnings = array( 'title' => __( 'Earnings', 'wpec-statusboard-txt' ), 'color' => 'green' );

	foreach ( $datapoints as $datapoint ) :
		$sales['datapoints'][] = array( 'title' => date( apply_filters( 'wpec_statusboard_date_format', 'n\/j' ), strtotime( $datapoint->date ) ), 'value' => $datapoint->sales );
		$earnings['datapoints'][] = array( 'title' => date( apply_filters( 'wpec_statusboard_date_format', 'n\/j' ), strtotime( $datapoint->date ) ), 'value' => $datapoint->earnings );
	endforeach;

	return array( $sales, $earnings );
}