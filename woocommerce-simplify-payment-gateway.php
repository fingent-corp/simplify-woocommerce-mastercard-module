<?php
/**
 * Plugin Name: Mastercard Payment Gateway Services - Simplify
 * Plugin URI: https://github.com/fingent-corp/simplify-woocommerce-mastercard-module/
 * Description: Mastercard Payment Gateway Services - Simplify plugin from Mastercard lets you to take card payments directly on your WooCommerce store. Requires PHP 8.1+ & WooCommerce 7.3+
 * Author: Fingent Global Solutions Pvt. Ltd.
 * Author URI: https://www.fingent.com/
 * Text Domain: woocommerce-gateway-simplify-commerce
 * Version: 2.4.3
 * Requires at least: 5.6.0
 * Tested up to: 6.6.1
 * Requires PHP: 7.4
 * php version 8.1
 *
 * WC requires at least: 7.6
 * WC tested up to: 9.1.4
 * 
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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Required minimums
 */
define( 'WC_SIMPLIFY_COMMERCE_MIN_PHP_VER', '7.4.0' );
define( 'WC_SIMPLIFY_COMMERCE_MIN_WC_VER', '7.6.0' );
define( 'WC_SIMPLIFY_COMMERCE_FILE', __FILE__ );

if ( ! class_exists( 'WC_Gateway_Simplify_Commerce_Loader' ) ) {
	class WC_Gateway_Simplify_Commerce_Loader {
		/**
		 * @var WC_Gateway_Simplify_Commerce_Loader The reference the *Singleton* instance of this class
		 */
		private static $instance;

		/**
		 * Returns the *Singleton* instance of this class.
		 *
		 * @return WC_Gateway_Simplify_Commerce_Loader The *Singleton* instance.
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Private clone method to prevent cloning of the instance of the
		 * *Singleton* instance.
		 *
		 * @return void
		 */
		public function __clone() {
		}

		/**
		 * Private unserialize method to prevent unserializing of the *Singleton*
		 * instance.
		 *
		 * @return void
		 */
		public function __wakeup() {
		}

		/** @var bool whether or not we need to load code for / support subscriptions */
		private $subscription_support_enabled = false;

		/**
		 * Notices (array)
		 * @var array
		 */
		public $notices = array();

		/**
		 * Protected constructor to prevent creating a new instance of the
		 * *Singleton* via the `new` operator from outside of this class.
		 */
		protected function __construct() {
			add_action( 'admin_init', array( $this, 'check_environment' ) );
			add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
			add_action( 'plugins_loaded', array( $this, 'init' ) );
			add_action( 'before_woocommerce_init', array( $this, 'declare_cart_checkout_blocks_compatibility' ) );

			if( ! $this->is_order_pay_page() ) {
				add_action( 'woocommerce_blocks_loaded', array( $this, 'woocommerce_simplify_mastercard_woocommerce_block_support' ), 99 );
			}
		}

		/**
		 * Init the plugin after plugins_loaded so environment variables are set.
		 */
		public function init() {

			define( 'MPGS_SIMPLIFY_PLUGIN_FILE', __FILE__ );
			define( 'MPGS_SIMPLIFY_PLUGIN_BASENAME', plugin_basename( MPGS_SIMPLIFY_PLUGIN_FILE ) );
			define( 'MPGS_SIMPLIFY_ROOT_FOLDER', plugin_basename( dirname( MPGS_SIMPLIFY_PLUGIN_FILE ) ) );

			require_once plugin_basename( '/includes/class-simplify-checkout-builder.php' );	
			require_once plugin_basename( '/includes/class-gateway-notification.php' );	
			require_once plugin_basename( '/includes/class-simplify-api-logger.php' );

			// Don't hook anything else in the plugin if we're in an incompatible environment
			if ( self::get_environment_warning() ) {
				return;
			}

			// Init the gateway itself
			$this->init_gateways();

			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
			add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );

			add_filter( 'woocommerce_order_actions', function ( $actions ) {		
				$order_id = self::get_order_id();
				$order = new WC_Order( $order_id );

				if ( $order && $order->get_payment_method() == WC_Gateway_Simplify_Commerce::ID
				     && $order->get_meta( '_simplify_order_captured' ) === '0'
				     && $order->get_status() == 'processing'
				) {
					$actions[ WC_Gateway_Simplify_Commerce::ID . '_capture_payment' ] = __(
						'Capture Authorized Amount',
						'woocommerce-gateway-simplify-commerce'
					);
					$actions[ WC_Gateway_Simplify_Commerce::ID . '_void_payment' ]    = __(
						'Void',
						'woocommerce-gateway-simplify-commerce'
					);
				}

				return $actions;
			} );

			add_action(
				'wp_enqueue_scripts',
				array( $this, 'enqueue_scripts' )
			);		
		}

		/**
		 * Allow this class and other classes to add slug keyed notices (to avoid duplication)
		 */
		public function add_admin_notice( $slug, $class, $message ) {
			$this->notices[ $slug ] = array(
				'class'   => $class,
				'message' => $message
			);
		}

		/**
		 * The primary sanity check, automatically disable the plugin on activation if it doesn't
		 * meet minimum requirements.
		 *
		 * Based on http://wptavern.com/how-to-prevent-wordpress-plugins-from-activating-on-sites-with-incompatible-hosting-environments
		 */
		public static function activation_check() {
			$environment_warning = self::get_environment_warning( true );
			if ( $environment_warning ) {
				deactivate_plugins( plugin_basename( __FILE__ ) );
				wp_die( $environment_warning );
			}
		}

		/**
		 * Get the plugin url.
		 *
		 * @return string
		 */
		public static function plugin_url() {
			return untrailingslashit( plugins_url( '/', MPGS_SIMPLIFY_PLUGIN_BASENAME ) );
		}

		/**
		 * The backup sanity check, in case the plugin is activated in a weird way,
		 * or the environment changes after activation.
		 */
		public function check_environment() {
			$environment_warning = self::get_environment_warning();
			if ( $environment_warning && is_plugin_active( plugin_basename( __FILE__ ) ) ) {
				deactivate_plugins( plugin_basename( __FILE__ ) );
				$this->add_admin_notice( 'bad_environment', 'error', $environment_warning );
				if ( isset( $_GET['activate'] ) ) {
					unset( $_GET['activate'] );
				}
			}
		}

		/**
		 * Return WooCommerce order id.
		 *
		 * @return int Order id. 
		 */
		public static function get_order_id() {
			if( 'yes' !== self::is_hpos() )
				$order_id = isset( $_REQUEST['post'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['post'] ) ) : null;
			else
				$order_id = isset( $_REQUEST['id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['id'] ) ) : null;

			return $order_id;
		}

		/**
		 * Confirm whether HPOS has been enabled or not.
		 *
		 * @return bool HPOS. 
		 */
		public static function is_hpos() {
			if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
				return 'yes';
			} else {
				return 'no';
			}
		}

		/**
		 * Checks the environment for compatibility problems.  Returns a string with the first incompatibility
		 * found or false if the environment has no problems.
		 */
		static function get_environment_warning( $during_activation = false ) {

			if ( defined( 'WC_VERSION' ) ) {
				if ( version_compare( phpversion(), WC_SIMPLIFY_COMMERCE_MIN_PHP_VER, '<' ) ) {
					if ( $during_activation ) {
						$message = __(
							'The plugin could not be activated. The minimum PHP version required for this plugin is %1$s. You are running %2$s.',
							'woocommerce-gateway-simplify-commerce', 'woocommerce-gateway-simplify-commerce'
						);
					} else {
						$message = __(
							'The Mastercard Payment Gateway Services - Simplify plugin has been deactivated. The minimum PHP version required for this plugin is %1$s. You are running %2$s.',
							'woocommerce-gateway-simplify-commerce'
						);
					}

					return sprintf( esc_html( $message ), WC_SIMPLIFY_COMMERCE_MIN_PHP_VER, phpversion() );
				}

				if ( version_compare( WC_VERSION, WC_SIMPLIFY_COMMERCE_MIN_WC_VER, '<' ) ) {
					if ( $during_activation ) {
						$message = __(
							'The plugin could not be activated. The minimum WooCommerce version required for this plugin is %1$s. You are running %2$s.',
							'woocommerce-gateway-simplify-commerce', 'woocommerce-gateway-simplify-commerce'
						);
					} else {
						$message = __(
							'The Mastercard Payment Gateway Services - Simplify plugin has been deactivated. The minimum WooCommerce version required for this plugin is %1$s. You are running %2$s.',
							'woocommerce-gateway-simplify-commerce'
						);
					}

					return sprintf( esc_html( $message ), WC_SIMPLIFY_COMMERCE_MIN_WC_VER, WC_VERSION );
				}
			} else {
				$message = __(
						'Kindly ensure the WooCommerce plugin is active before activating the Mastercard Payment Gateway Services - Simplify plugin.', 'woocommerce-gateway-simplify-commerce'
						);
				return sprintf( esc_html( $message ) );
			}

			if ( ! function_exists( 'curl_init' ) ) {
				if ( $during_activation ) {
					return __(
						'The plugin could not be activated. cURL is not installed.',
						'woocommerce-gateway-simplify-commerce'
					);
				}

				return __(
					'The Mastercard Payment Gateway Services - Simplify plugin has been deactivated. cURL is not installed.',
					'woocommerce-gateway-simplify-commerce'
				);
			}

			return false;
		}

		/**
		 * Adds plugin action links
		 *
		 * @since 2.4.0
		 */
		public function plugin_action_links( $links ) {
			$plugin_links = [
				'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=simplify_commerce' ) . '">' .
					__( 'Settings', 'woocommerce-gateway-simplify-commerce' ) .
				'</a>',
				'<a href="https://mpgs.fingent.wiki/simplify-commerce/simplify-commerce-payment-gateway-for-woocommerce/getting-started/">' .
					__( 'Docs', 'woocommerce-gateway-simplify-commerce' ) .
				'</a>',
				'<a href="https://mpgs.fingent.wiki/target/woocommerce-mastercard-payment-gateway-services/installation/">' .
					__( 'Support', 'woocommerce-gateway-simplify-commerce' ) .
				'</a>',
			];

			return array_merge( $plugin_links, $links );
		}

		/**
		 * Show row meta on the plugin screen.
		 *
		 * @param mixed $links Plugin Row Meta.
		 * @param mixed $file  Plugin Base file.
		 *
		 * @return array
		 */
		public static function plugin_row_meta( $links, $file ) {
			if ( MPGS_SIMPLIFY_PLUGIN_BASENAME !== $file ) {
				return $links;
			}

			/**
			 * The MPGS documentation URL.
			 *
			 * @since 2.4.0
			 */
			$docs_url = apply_filters( 'mastercard_docs_url', 'https://mpgs.fingent.wiki/simplify-commerce/simplify-commerce-payment-gateway-for-woocommerce/getting-started/' );

			/**
			 * The Mastercard Support URL.
			 *
			 * @since 2.4.0
			 */
			$support_url = apply_filters( 'mastercard_support_url', 'https://mpgsfgs.atlassian.net/servicedesk/customer/portals/' );

			$row_meta = array(
				'docs'    => '<a href="' . esc_url( $docs_url ) . '" aria-label="' . esc_attr__( 'View mastercard documentation', 'mastercard-payment-gateway-services' ) . '">' . esc_html__( 'Docs', 'mastercard-payment-gateway-services' ) . '</a>',
				'support' => '<a href="' . esc_url( $support_url ) . '" aria-label="' . esc_attr__( 'Visit mastercard support', 'mastercard-payment-gateway-services' ) . '">' . esc_html__( 'Support', 'mastercard-payment-gateway-services' ) . '</a>',
			);

			return array_merge( $links, $row_meta );
		}


		/**
		 * Display any notices we've collected thus far (e.g. for connection, disconnection)
		 */
		public function admin_notices() {
			foreach ( (array) $this->notices as $notice_key => $notice ) {
				echo '<div class="' . esc_attr( sanitize_html_class( $notice['class'] ) ) . '"><p>';
				echo wp_kses(
					$notice['message'], array( 'a' => array( 'href' => array() ) )
				);
				echo "</p></div>";
			}
		}

		/**
		 * Initialize the gateway. Called very early - in the context of the plugins_loaded action
		 *
		 * @since 2.2.0
		 */
		public function init_gateways() {
			if ( class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' ) ) {
				$this->subscription_support_enabled = true;
			}

			if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
				return;
			}

			require_once( plugin_basename( 'includes/class-payment-gateway.php' ) );

			load_plugin_textdomain(
				'woocommerce-gateway-simplify-commerce',
				false,
				trailingslashit( dirname( plugin_basename( __FILE__ ) ) )
			);

			add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );

			if ( $this->subscription_support_enabled ) {
				require_once( plugin_basename( 'includes/class-subscription-addon.php' ) );
			}
		}

		/**
		 * Add the gateways to WooCommerce
		 *
		 * @since 2.2.0
		 */
		public function add_gateways( $methods ) {
			if ( $this->subscription_support_enabled ) {
				$methods[] = 'WC_Addons_Gateway_Simplify_Commerce';
			} else {
				$methods[] = 'WC_Gateway_Simplify_Commerce';
			}

			return $methods;
		}

		/**
		 * What rolls down stairs
		 * alone or in pairs,
		 * and over your neighbor's dog?
		 * What's great for a snack,
		 * And fits on your back?
		 * It's log, log, log
		 *
		 * @since 2.2.0
		 */
		public function log( $context, $message ) {
			if ( empty( $this->log ) ) {
				$this->log = new WC_Logger();
			}

			$this->log->add( 'woocommerce-gateway-simplify-commerce', $context . " - " . $message );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( $context . " - " . $message );
			}
		}

		/**
		 * Adds Styles on the Checkout Page
		 */
		public function enqueue_scripts() {
			if ( ! is_checkout() ) {
				return;
			}

			wp_enqueue_style(
				'simplify_checkout_styles',
				plugin_dir_url( __FILE__ ) . 'public/css/styles.css'
			);
		}

		/**
		 * Function to declare compatibility with cart_checkout_blocks feature.
		 *
		 * @since 2.4.2
		 * @return void
		 */
		public function declare_cart_checkout_blocks_compatibility() { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		}

		/**
		 * Function to register the Mastercard payment method type.
		 *
		 * @since 2.4.2
		 * @return void
		 */
		public function woocommerce_simplify_mastercard_woocommerce_block_support() {
			if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
				return;
			}
			
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-gateway-blocks-support.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new MC_Gateway_Simplify_Blocks_Support() );
				}
			);
		}

		/**
		 * Is_order_pay_page - Returns true when viewing the order received page.
		 *
		 * @return bool
		 */
		public function is_order_pay_page() {
			return isset( $_GET['key'] ) && strpos( $_SERVER['REQUEST_URI'], 'order-pay' ) !== false;
		}
	}
}

$GLOBALS['wc_gateway_simplify_commerce_loader'] = WC_Gateway_Simplify_Commerce_Loader::get_instance();
register_activation_hook( __FILE__, array( 'WC_Gateway_Simplify_Commerce_Loader', 'activation_check' ) );