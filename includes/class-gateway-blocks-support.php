<?php
/**
 * Copyright (c) 2019-2026 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Class MC_Gateway_Simplify_Blocks_Support.
 *
 * @since 2.4.2
 */
final class MC_Gateway_Simplify_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * Gateway variable.
	 *
	 * @var string
	 */
	private $gateway;

	/**
	 * Gateway name.
	 *
	 * @var string
	 */
	protected $name = 'simplify_commerce';

	/**
	 * MC_Gateway_Simplify_Blocks_Support constructor.
	 *
	 * @throws Exception If there's a problem connecting to the gateway.
     */
    public function __construct() {}

    /**
     * Initialize MC_Gateway_Simplify_Blocks_Support.
     *
     * @return void
     */
	public function initialize() {
        $this->settings = get_option( 'woocommerce_' . WC_Gateway_Simplify_Commerce::ID . '_settings', array() );
        $this->gateway  = new WC_Gateway_Simplify_Commerce();
    }

    /**
     * Confirm whether the Mastercard Gateway is currently active.
     *
     * @return boolean
     */
    public function is_active() {
        return $this->gateway->is_available();
    }
    /**
     * Confirm whether the Mastercard Gateway is currently active.
     *
     * @return array New value but with defaults initially filled in for missing settings.
     */
    public function get_payment_method_script_handles() {
        wp_register_script(
            'simplify_commerce-blocks-integration',
            WC_Gateway_Simplify_Commerce_Loader::plugin_url() . '/assets/js/checkout.js',
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ),
            MPGS_SIMPLIFY_MODULE_VERSION,
            true
        );
        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'simplify_commerce-blocks-integration' );
        }

        return array( 'simplify_commerce-blocks-integration' );
    }
    /**
     * Prepare the setup for the Mastercard payment gateway data.
     *
     * @return array New payment gateway data but with defaults initially filled in for missing settings.
     */
    public function get_payment_method_data() {
        return array(
            'title'       => $this->gateway->title,
            'description' => $this->gateway->description,
        );
    }
}
