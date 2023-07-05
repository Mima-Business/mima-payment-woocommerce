<?php

/**
 * Plugin Name: Mima WooCommerce Payment Gateway
 * Plugin URI: https://trymima.com
 * Description: WooCommerce payment gateway for Mima
 * Version: 5.7.6
 * Author: Samule Anjorin
 * Author URI:
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * WC requires at least: 7.0
 * WC tested up to: 7.8
 * Text Domain: woo-mima
 * Domain Path: /languages
 */

define( 'WC_MIMA_MAIN_FILE', __FILE__ );

define('WC_MIMA_WEBHOOK_VERSION', 'mima-webhook-v1');

// Add the custom payment gateway to WooCommerce
function add_mima_payment_gateway($methods)
{
    $methods[] = 'WC_Gateway_Mima';
    return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_mima_payment_gateway');

function mima_init() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>' .
                sprintf(
                    'Mima requires WooCommerce to be installed and active. Click %s to install WooCommerce.',
                    '<a href="' . admin_url( 'plugin-install.php?tab=plugin-information&plugin=woocommerce&TB_iframe=true&width=772&height=539' ) . '" class="thickbox open-plugin-details-modal">here</a>'
                ) . '</strong></p></div>';
        } );
        return;
    }

    require_once dirname( __FILE__ ) . '/includes/class-wc-gateway-mima.php';

    add_filter( 'woocommerce_payment_gateways', 'add_mima_payment_gateway', 99 );
}
add_action( 'plugins_loaded', 'mima_init', 99 );

function mima_payment_plugin_enqueue_assets($hook) {
    if ('woocommerce_page_wc-settings' === $hook) {
        wp_enqueue_style('mima-payment-plugin-style', plugin_dir_url(__FILE__) . 'assets/styles.css');
        wp_enqueue_script('mima-payment-admin-script', plugin_dir_url(__FILE__) . 'assets/js/script.js', array('jquery'), '', true);
    }
}
// Enqueue the plugin's assets
add_action('admin_enqueue_scripts', 'mima_payment_plugin_enqueue_assets');

function mima_reprocess_transaction( $reference ) {
	(new WC_Gateway_Mima())->process_transaction( $reference, false );
}
add_action('mima_retry_transaction', 'mima_reprocess_transaction');

add_action( 'woocommerce_admin_order_data_after_billing_address', 'mima_order_extra_info' );
function mima_order_extra_info( $order ){
	$order_id = $order->get_id();
	echo '<p><strong>'.__('Test Mode:').'</strong> ' . get_post_meta( $order_id, '_mima_test', true ) . '</p>';
}

function mima_process_webhook() {
	global $_SERVER;

	$signature = !empty($_SERVER['HTTP_MIMASIGNATURE'])
		? $_SERVER['HTTP_MIMASIGNATURE']
		: null;

	$payload = file_get_contents( 'php://input' );

	if(is_null($signature) || is_null($payload)) {
		wp_send_json(
			[
				'status' => false,
				'message' => 'Invalid request'
			],
			400
		);
	}

	$response = (new WC_Gateway_Mima())->process_webhook($signature, $payload);
	wp_send_json($response, $response['status'] ? 200 : 400);
}
add_action('wc_ajax_' . WC_MIMA_WEBHOOK_VERSION, 'mima_process_webhook');