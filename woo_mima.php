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

    // add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'tbz_woo_paystack_plugin_action_links' );

}
add_action( 'plugins_loaded', 'mima_init', 99 );

function mima_payment_plugin_enqueue_assets($hook)
{
    if ('toplevel_page_my-plugin-settings' === $hook) {
        wp_enqueue_style('mima-payment-plugin-style', plugin_dir_url(__FILE__) . 'assets/style.css');
        wp_enqueue_script('mima-payment-plugin-script', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery'), '', true);
    }
}
// Enqueue the plugin's assets
add_action('admin_enqueue_scripts', 'mima_payment_plugin_enqueue_assets');
