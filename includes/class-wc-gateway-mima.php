<?php


/**
 * Mima Payment Gateway class. Handles processing payments via Mima
 */
class WC_Gateway_Mima extends WC_Payment_Gateway {
	/**
	 * Mima Production API base url
	 */
	public const MIMA_API = 'https://api.trymima.com/v1';

	/**
	 * Mima Test API base url
	 */
	public const MIMA_TEST_API = 'https://dev.trymima.com/v1';

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

	/**
     * Mima secret key.
     *
     * @var string
     */
    public $secret_key;

	/**
	 * Constructor
	 */
	public function __construct() {
        $this->id = 'mima';
        $this->method_title = __('Mima Payment Gateway', 'woo-mima');
        $this->method_description = "Mima Payment Gateway.";
        $this->title = 'Pay with Mima';
        $this->has_fields = true;

        $this->init_form_fields();
        $this->init_settings();

        $this->public_key = $this->get_option('public_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->testmode = $this->get_option('testmode', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

	/**
	 * Mima Admin settings fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
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
            'secret_key' => array(
                'title' => 'Secret Key',
                'type' => 'password',
                'label' => 'Enter your mima business secret key.',
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

	/**
	 * Process Mima Payment
	 *
	 * @param $order_id
	 *
	 * @return array|string[]
	 */
	public function process_payment($order_id) {
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
	        'callBackUrl' => get_site_url(null, '/') . '?wc-ajax=' . WC_MIMA_WEBHOOK_VERSION
        );

        // Encode payload as JSON
        $payload_json = json_encode($payload);

		if ($this->testmode) {
            update_post_meta($order_id, '_mima_test', $this->testmode);
		}

        // Perform the POST request to the payment provider URL
        $args = array(
            'body' => $payload_json,
            'headers' => [
				'Content-Type' => 'application/json',
				'publicKey' => $this->public_key,
            ],
            'timeout' => 30,
            'redirection' => 5,
            'blocking' => true,
            'sslverify' => true, // Only use for testing purposes. Use true in production.
        );

        $response = wp_remote_post($this->get_mima_url('/invoices/checkout'), $args);

        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
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

	/**
	 * Get Mima API base URL.
	 *
	 * @param string $path
	 *
	 * @return string
	 */
	private function get_mima_url(string $path): string {
		$base = $this->testmode === 'no'
			? self::MIMA_API
			: self::MIMA_TEST_API;

		return $base . $path;
	}

	/**
	 * @return mixed|string|null
	 */
	public function get_icon() {
		$icon = '<img src="' . WC_HTTPS::force_https_url(
			plugins_url('assets/images/logo.png', WC_MIMA_MAIN_FILE)
		) . '" alt="Pay with Mima" style="width: 300px;"/>';
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Process incoming webhook
	 *
	 * @param $signature
	 * @param $payload
	 *
	 * @return array
	 */
	public function process_webhook($signature, $payload): array {
		if ($signature === hash_hmac('sha512', $payload, $this->secret_key, false)) {
			$data = json_decode($payload, true);

			if (!empty($data['reference'])) {
				$reference = sanitize_text_field($data['reference']);
				$this->process_transaction($reference);
			}
		}

		// Response send doesn't matter as it's not consumed.
		return [
			'status' => true,
			'message' => 'Webhook processed',
		];
	}

	/**
	 * Process a Mima transaction.
	 *
	 * @param $reference
	 * @param $schedule_retry
	 *
	 * @return void
	 */
	public function process_transaction( $reference, $schedule_retry = true ) {
		$reference_details = $this->get_reference_details($reference);

		if ($reference_details['status'] === 'unavailable' && $schedule_retry) {
			// Verification service unavailable. Retry verification in an hour
			wp_schedule_single_event(time() + 3600, 'mima_retry_transaction', [$reference]);
			return;
		}

		if (in_array($reference_details['status'], ['success', 'failed'])) {
			$order = wc_get_order((int)$reference_details['orderId']);

			if (
				!$order // Order not found
				|| $order->get_status() !== 'pending' // Only process pending orders
			) {
				return;
			}

			if ($reference_details['status'] === 'success') {
				$order->payment_complete($reference);
				$order->add_order_note("Order payment received by Mima Payment (Reference: $reference)");
				return;
			}

			$order->update_status('failed', "Order payment failed by Mima Payment");
		}
	}

	/**
	 * @param string $reference
	 *
	 * @return string[]
	 */
	private function get_reference_details(string $reference): array {
		$result = wp_remote_get(
			$this->get_mima_url("/banking/plugin/verify-order/$reference"),
			[
				'headers' => [
					'mimaSignature' => hash_hmac('sha512', $reference, $this->secret_key, false),
				],
			]
		);

		if (!empty($result['body'])) {
			$body = json_decode($result['body'], true);

			if (!is_null($body) && !empty($body['status']) && !empty($body['orderId'])) {
				return $body;
			}
		}

		// Can not decode response | No response found
		return [
			'status' => 'unavailable',
		];
	}
}
