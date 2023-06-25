<?php

class WC_Gateway_Mima extends WC_Payment_Gateway
{
    /**
     * Is test mode active?
     *
     * @var bool
     */
    public $testmode;

    /**
     * Mima public key.
     *
     * @var string
     */
    public $public_key;

    public function __construct()
    {
        $this->id = 'mima';
        $this->method_title = __('Mima Payment Gateway', 'woo-mima');
        $this->method_description = "Mima Payment Gateway.";
        $this->title = 'Pay with Mima';
        $this->has_fields = true;

        $this->init_form_fields();
        $this->init_settings();

        $this->public_key = $this->get_option('public_key');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));

        // Handle the webhook callback
        add_action('woocommerce_api_mima_webhook', array($this, 'handle_webhook_request'));
    }
    public function register_webhook_endpoint()
    {
        register_rest_route('mima/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook_request'),
            'permission_callback' => '__return_true',
        ));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable Mima Payment Gateway',
                'default' => 'yes'
            ),
            'public_key' => array(
                'title' => 'Public Key',
                'type' => 'text',
                'label' => 'Enter your mima business public key.',
                'default' => ''
            ),
            'testmode' => array(
                'title'       => __('Test mode', 'woo-mima'),
                'label'       => __('Enable Test Mode', 'woo-mima'),
                'type'        => 'checkbox',
                'description' => __('Test mode enables you to test payments before going live. <br />Once the LIVE MODE is enabled on your Mima account uncheck this.', 'woo-mima'),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
        );
    }

    public function process_payment($order_id)
    {
        global $woocommerce;

        $order = wc_get_order($order_id);

        // Get cart contents
        $cart_items = array();

        foreach ($woocommerce->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $cart_items[] = array(
                'item' => $product->get_name(),
                'quantity' => $cart_item['quantity'],
                'unitPrice' => $product->get_price(),
            );
        }

        // Retrieve currency from the cart
        $currency = get_woocommerce_currency();

        // Get fixed shipping price
        $shipping_price = $order->get_shipping_total();

        $customer = array(
            'fullname' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'street' => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
            'state' => $order->get_billing_state(),
            'country' => $order->get_billing_country(),
	        'mobile' => $order->get_billing_phone(),
        );

		$post_code = $order->get_billing_postcode();
		if (!empty($post_code)) {
			$customer['postCode'] = $post_code;
		}

        $invoice = array(
            'currencyCode' => $currency,
            'shipping' => $shipping_price,
            'orders' => $cart_items,
	        'orderId' => (string) $order_id,
        );

        $payload = array(
            'customer' => $customer,
            'invoice' => $invoice,
            'publicKey' => $this->get_option('public_key'),
        );

        // Encode payload as JSON
        $payload_json = json_encode($payload);

        // External payment provider URL
	    $test_mode = $this->get_option('testmode', 'no');
		if ($test_mode === 'no') {
			$payment_url = 'https://api.trymima.com/v1/invoices/checkout';
		} else {
            $payment_url = 'https://api.dev.trymima.com/v1/invoices/checkout';
		}

        // Generate a unique token for identifying the transaction
        $token = md5(uniqid());

        // Save the token in the order for later verification
        update_post_meta($order_id, '_mima_payment_gateway_token', $token);

        // Perform the POST request to the payment provider URL
        $args = array(
            'body' => $payload_json,
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 30,
            'redirection' => 5,
            'blocking' => true,
            'sslverify' => true, // Only use for testing purposes. Use true in production.
        );

        $response = wp_remote_post($payment_url, $args);

        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
	        $order->update_status('processing', __('Payment accepted via Mima payment gateway.', 'woo-mima'));
            $response_data = json_decode(wp_remote_retrieve_body($response));
			return [
				'result' => 'success',
	            'redirect' => $response_data->url,
            ];
        }

        return array(
            'result' => 'failed',
        );
    }

	public function get_icon() {
		$icon = '<img src="' . WC_HTTPS::force_https_url(
			plugins_url('assets/images/logo.png', WC_MIMA_MAIN_FILE)
		) . '" alt="Pay with Mima" style="width: 300px;"/>';
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	public function handle_webhook_request($request)
    {
        // Process the webhook payload
        $payload = json_decode($request->get_body(), true);

        // Get the order ID from the payload (assuming the order ID is included in the webhook payload)
        $order_id = $payload['orderId'];

        // Retrieve the order
        $order = wc_get_order($order_id);

        // Check if the order exists
        if ($order) {
            // Update the order status based on the webhook payload
            $status = $payload['status'];

            // Handle different status values as needed
            if ($status === 'paid') {
                // Payment is successful
                $order->update_status('completed', __('Payment accepted via Mima payment gateway.', 'woo-mima'));
            } elseif ($status === 'failed') {
                // Payment failed
                $order->update_status('failed', __('Payment failed via Mima payment gateway.', 'woo-mima'));
            }

            // Save any additional data from the webhook payload to order notes or metadata
            // ...

            // Return a response to the webhook request
            return new WP_REST_Response('Webhook received successfully.', 200);
        }

        // If the order is not found, return an error response
        return new WP_Error('order_not_found', __('Order not found.', 'woo-mima'), array('status' => 404));
    }
}
