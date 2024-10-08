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
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

define( 'MPGS_SIMPLIFY_MODULE_VERSION', '2.4.3' );
define( 'MPGS_SIMPLIFY_SDK_VERSION', '1.7.0' );

class WC_Gateway_Simplify_Commerce extends WC_Payment_Gateway_CC {
    const ID = 'simplify_commerce';

    const TXN_MODE_PURCHASE  = 'purchase';
    const TXN_MODE_AUTHORIZE = 'authorize';

    const INTEGRATION_MODE_MODAL    = 'modal';
    const INTEGRATION_MODE_EMBEDDED = 'embedded';

    const SIP_HOST   = 'www.simplify.com';
    const SIP_CUSTOM = 'custom';

    const HF_FIXED      = 'fixed';
    const HF_PERCENTAGE = 'percentage';

    /**
     * @var string
     */
    protected $sandbox;

    /**
     * @var string
     */
    protected $modal_color;

    /**
     * @var string
     */
    protected $public_key;

    /**
     * @var string
     */
    protected $private_key;

    /**
     * @var string
     */
    protected $txn_mode;

    /**
     * @var bool
     */
    protected $is_modal_integration_model;

    /**
     * @var string
     */
    protected $logging_level;

    /**
     * @var string
     */
    protected $hash;

    /**
     * Handling fees
     *
     * @var bool
     */
    protected $hf_enabled = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id                   = self::ID;
        $this->method_title         = __(
            'Mastercard Payment Gateway Services - Simplify',
            'woocommerce-gateway-simplify-commerce'
        );
        $this->method_description   = __(
            'Take payments via the Simplify payment gateway - uses simplify.js to create card tokens and the Mastercard Payment Gateway Services - Simplify SDK. Requires SSL when sandbox is disabled.',
            'woocommerce-gateway-simplify-commerce'
        );
        $this->new_method_label     = __(
            'Use a new card',
            'woocommerce-gateway-simplify-commerce'
        );
        $this->has_fields           = true;
        $this->supports             = array(
            'subscriptions',
            'products',
            'subscription_cancellation',
            'subscription_reactivation',
            'subscription_suspension',
            'subscription_amount_changes',
            'subscription_payment_method_change', // Subscriptions 1.n compatibility
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'subscription_date_changes',
            'multiple_subscriptions',
            'refunds',
            'pre-orders'
        );
        $this->view_transaction_url = 'https://www.simplify.com/commerce/app#/payment/%s';

        // Load the form fields
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get setting values
        $this->title                      = $this->get_option( 'title' );
        $this->description                = $this->get_option( 'description' );
        $this->enabled                    = $this->get_option( 'enabled' );
        $this->modal_color                = $this->get_option( 'modal_color', '#333333' );
        $this->sandbox                    = $this->get_option( 'sandbox' );
        $this->txn_mode                   = $this->get_option( 'txn_mode', self::TXN_MODE_PURCHASE );
        $this->public_key                 = $this->sandbox === 'no' ? $this->get_option( 'public_key' ) : $this->get_option( 'sandbox_public_key' );
        $this->private_key                = $this->sandbox === 'no' ? $this->get_option( 'private_key' ) : $this->get_option( 'sandbox_private_key' );
        $this->is_modal_integration_model = $this->get_option( 'integration_mode' ) === self::INTEGRATION_MODE_MODAL;
        $this->logging_level              = $this->get_debug_logging_enabled() ? true : false;
        $this->hash                       = hash( 'sha256', $this->public_key . $this->private_key );
        $this->hf_enabled                 = $this->get_option( 'hf_enabled', false );

        $this->init_simplify_sdk();

        // Hooks
        add_action(
            sprintf( "woocommerce_update_options_payment_gateways_%s", $this->id ),
            array( $this, 'process_admin_options' )
        );
        add_action(
            sprintf( "woocommerce_receipt_%s", $this->id ),
            array( $this, 'receipt_page' )
        );
        add_action(
            sprintf( "woocommerce_api_wc_gateway_%s", $this->id ),
            array( $this, 'return_handler' )
        );
        add_action(
            sprintf( "woocommerce_order_action_%s_capture_payment", $this->id ),
            array( $this, 'capture_authorized_order' )
        );
        add_action(
            sprintf( "woocommerce_order_action_%s_void_payment", $this->id ),
            array( $this, 'void_authorized_order' )
        );

        add_action( 
            'admin_enqueue_scripts', 
            array( $this, 'admin_scripts' ) 
        );

        add_action(
            'wp_ajax_download_log',
            array( $this, 'download_decrypted_log' )
        );

        add_filter(
            'woocommerce_admin_order_should_render_refunds',
            array( $this, 'admin_order_should_render_refunds' ), 10, 3
        );
        add_action(
            'woocommerce_cart_calculate_fees',
            array( $this, 'add_handling_fee' )
        );
    }

    /**
     * @return void
     */

    public function admin_scripts() {
        if ( 'woocommerce_page_wc-settings' !== get_current_screen()->id ) {
            return;
        }

        wp_enqueue_script( 'woocommerce_simplify_admin', plugins_url( 'assets/js/mastercard-admin.js', WC_SIMPLIFY_COMMERCE_FILE ),
            array(), time(), true );
    }

    /**
     * Check if debug logging is enabled.
     *
     * @return bool True if debug logging is enabled, false otherwise.
     */
    protected function get_debug_logging_enabled() {
        if ( 'yes' === $this->get_option( 'debug', false ) ) {

            $filename = WP_CONTENT_DIR . '/mastercard_simplify.log';
            if ( !is_writable( $filename ) ) {
                @chmod( $filename, 0644 );
            }

            if ( ! file_exists( $filename ) ) {
                file_put_contents( $filename, '' );
                chmod( $filename, 0644 );
            }
            
            return true;
        }

        return false;
    }

    /**
     * Determines whether the admin order page should render refunds.
     *
     * This function is used to check if refunds should be displayed on the admin order page. 
     * It can be used to add custom logic or conditions for rendering refunds in the order details view.
     *
     * @param bool $render_refunds Indicates whether refunds should be rendered.
     * @param int $order_id The ID of the order being viewed.
     * @param WC_Order $order The order object for which the refunds are being checked.
     * @return bool Updated value of $render_refunds indicating whether refunds should be rendered.
     */
    public function admin_order_should_render_refunds( $render_refunds, $order_id, $order ) {
        $auth_code = $order->get_meta( '_simplify_authorization' );
        if ( $auth_code ) {
            if( 
                ( 'simplify_commerce' === $order->get_payment_method() && 'refunded' === $order->get_status() ) || 
                ( 'simplify_commerce' === $order->get_payment_method() && empty( get_post_meta( $order_id, '_simplify_order_captured', true ) ) ) 
            ) {
                return false;
            }
        }

        return $render_refunds;
    }

    /**
     * @throws Exception
     */
    public function capture_authorized_order() {
        try {
            $order = new WC_Order( $_REQUEST['post_ID'] );
            if ( $order->get_payment_method() != $this->id ) {
                throw new Exception( 'Wrong payment method' );
            }
            if ( $order->get_status() != 'processing' ) {
                throw new Exception( 'Wrong order status, must be \'processing\'' );
            }
            if ( $order->get_meta( '_simplify_order_captured' ) !== '0' ) {
                throw new Exception( 'Order already captured' );
            }

            $authCode = $order->get_meta( '_simplify_authorization' );
            if ( ! $authCode ) {
                throw new Exception( 'Invalid or missing authorization code' );
            }

            $data = array(
                'authorization' => $authCode,
                'reference'     => $order->get_id(),
                'currency'      => strtoupper( $order->get_currency() ),
                'amount'        => $this->get_total( $order ),
                'description'   => sprintf(
                    __( 'Order #%s', 'woocommerce-gateway-simplify-commerce' ),
                    $order->get_order_number()
                ),
            );
            $this->log( 'Capture Request', json_encode( $data ) );
            $payment = Simplify_Payment::createPayment( $data );
            $this->log( 'Capture Response', $payment );

            if ( $payment->paymentStatus === 'APPROVED' ) {
                $this->process_capture_order_status( $order, $payment->id );
                $order->add_order_note(
                    sprintf(
                        __( 'Gateway captured amount %s (ID: %s)', 'woocommerce-gateway-simplify-commerce' ),
                        $order->get_total(),
                        $payment->id
                    )
                );
                if ( wp_get_referer() || 'yes' !== WC_Gateway_Simplify_Commerce_Loader::is_hpos() ) {
                    wp_safe_redirect( wp_get_referer() );
                } else {
                    $return_url = add_query_arg( array(
                        'page'    => 'wc-orders',
                        'action'  => 'edit',
                        'id'      => $order->get_id(),
                        'message' => 1
                    ), admin_url( 'admin.php' ) );
                    wp_safe_redirect( $return_url );
                }
                exit;
            } else {
                throw new Exception( 'Capture declined' );
            }

        } catch ( Simplify_ApiException $e ) { 

            if ( 'system' !== $e->getErrorCode() && $this->is_payment_already_captured( $e ) ) {
                $this->process_capture_order_status( $order );
                $order->add_order_note(
                    __( 'Payment is already captured.', 'woocommerce-gateway-simplify-commerce' )
                );

            } else {
                $order->add_order_note(
                    __( $e->getMessage(), 'woocommerce-gateway-simplify-commerce' )
                );
                wp_die( $e->getMessage() . '<br>Ref: ' . $e->getReference() . '<br>Code: ' . $e->getErrorCode(),
                    __( 'Gateway Failure' ) );              
            }

        } catch ( Exception $e ) {
            wp_die( $e->getMessage(), __( 'Payment Process Failure' ) );
        }
    }

    /**
     * @param Simplify_ApiException $e
     *
     * @return bool
     */
    protected function is_payment_already_captured( Simplify_ApiException $e ) {
        $field_errors = $e->getFieldErrors(); 
        if( $field_errors ) {
            $error_codes  = array_map( function ( Simplify_FieldError $field_error ) {
                return $field_error->getErrorCode();
            }, $field_errors );

            return in_array( 'payment.already.captured', $error_codes );
        } else {
            return null;
        }
    }

    /**
     * @param WC_order $order
     * @param string $capture_id
     */
    public function process_capture_order_status( $order, $capture_id = null ) {
        if ( $capture_id ) {
            $order->add_meta_data( '_simplify_capture', $capture_id );
        }
        $order->update_meta_data( '_simplify_order_captured', '1' );
        $order->save_meta_data();

        add_filter(
            'woocommerce_valid_order_statuses_for_payment_complete',
            array( $this, 'add_valid_order_statuses' )
        );
        $order->payment_complete();
        remove_filter(
            'woocommerce_valid_order_statuses_for_payment_complete',
            array( $this, 'add_valid_order_statuses' )
        );

        $order->payment_complete( $capture_id );
    }

    /**
     * @param array $statuses
     *
     * @return array|string[]
     */
    public function add_valid_order_statuses( $statuses ) {
        $statuses[] = 'processing';

        return $statuses;
    }

    /**
     * @throws Exception
     */
    public function void_authorized_order() {
        try {
            $this->init_simplify_sdk(); // re init static variables
            $order = new WC_Order( $_REQUEST['post_ID'] );
            if ( $order->get_payment_method() != $this->id ) {
                throw new Exception( 'Wrong payment method' );
            }
            if ( $order->get_status() != 'processing' ) {
                throw new Exception( 'Wrong order status, must be \'processing\'' );
            }
            if ( $order->get_meta( '_simplify_order_captured' ) !== '0' ) {
                throw new Exception( 'Order already reversed' );
            }

            $authCode = $order->get_meta( '_simplify_authorization' );
            if ( ! $authCode ) {
                throw new Exception( 'Invalid or missing authorization code' );
            }

            $authTxn = Simplify_Authorization::findAuthorization( $authCode );
            $authTxn->deleteAuthorization();
            $order->update_status( 'cancelled', sprintf( __( 'Gateway reverse authorization (ID: %s)',
                        'woocommerce-gateway-simplify-commerce' ),
                        $authCode ) );

            if ( wp_get_referer() || 'yes' !== WC_Gateway_Simplify_Commerce_Loader::is_hpos() ) {
                wp_safe_redirect( wp_get_referer() );
            } else {
                $return_url = add_query_arg( array(
                    'page'    => 'wc-orders',
                    'action'  => 'edit',
                    'id'      => $order->get_id(),
                    'message' => 1
                ), admin_url( 'admin.php' ) );
                wp_safe_redirect( $return_url );
            }
            exit;

        } catch ( Simplify_ApiException $e ) {
            wp_die( $e->getMessage() . '<br>Ref: ' . $e->getReference() . '<br>Code: ' . $e->getErrorCode(),
                __( 'Gateway Failure' ) );

        } catch ( Exception $e ) {
            wp_die( $e->getMessage(), __( 'Payment Process Failure' ) );
        }
    }

    /**
     * Init Simplify SDK.
     */
    protected function init_simplify_sdk() {
        // Include lib
        require_once( 'Simplify.php' );

        Simplify::$publicKey  = $this->public_key;
        Simplify::$privateKey = $this->private_key;

        try {
            // try to extract version from main plugin file
            $plugin_path    = dirname( __FILE__, 2 ) . '/woocommerce-simplify-payment-gateway.php';
            $plugin_data    = get_file_data( $plugin_path, array( 'Version' => 'Version' ) );
            $plugin_version = $plugin_data['Version'] ?: 'Unknown';
        } catch ( Exception $e ) {
            $plugin_version = 'UnknownError';
        }

        Simplify::$userAgent = 'SimplifyWooCommercePlugin/' . WC()->version . '/' . $plugin_version;
    }

    /**
     * Admin Panel Options.
     * - Options for bits like 'title' and availability on a country-by-country basis.
     */
    public function admin_options() {
        ?>
        <h3><?php echo $this->method_title ?></h3>

        <?php $this->checks(); ?>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
            <script type="text/javascript">
                var PAYMENT_CODE = "<?php echo $this->id ?>";
                jQuery('#woocommerce_' + PAYMENT_CODE + '_sandbox').on('change', function () {
                    var sandbox = jQuery('#woocommerce_' + PAYMENT_CODE + '_sandbox_public_key, #woocommerce_' + PAYMENT_CODE + '_sandbox_private_key').closest('tr'),
                        production = jQuery('#woocommerce_' + PAYMENT_CODE + '_public_key, #woocommerce_' + PAYMENT_CODE + '_private_key').closest('tr');

                    if (jQuery(this).is(':checked')) {
                        sandbox.show();
                        production.hide();
                    } else {
                        sandbox.hide();
                        production.show();
                    }
                }).change();

                jQuery('#woocommerce_' + PAYMENT_CODE + '_mode').on('change', function () {
                    var color = jQuery('#woocommerce_' + PAYMENT_CODE + '_modal_color').closest('tr');
                    var supportedCardTypes = jQuery('#woocommerce_' + PAYMENT_CODE + '_supported_card_types').closest('tr');

                    if ('standard' === jQuery(this).val()) {
                        color.hide();
                        supportedCardTypes.show();
                    } else {
                        color.show();
                        supportedCardTypes.hide();
                    }
                }).change();
            </script>
        </table>
        <?php
    }

    /**
     * Check if SSL is enabled and notify the user.
     */
    public function checks() {
        if ( 'no' === $this->enabled ) {
            return;
        }

        // PHP Version
        if ( version_compare( phpversion(), '7.4', '<' ) ) {
            echo sprintf(
                "<div class=\"error\"><p>%s</p></div>",
                sprintf(
                    __(
                        'Gateway Error: Simplify commerce requires PHP 7.4 and above. You are using version %s.',
                        'woocommerce-gateway-simplify-commerce'
                    ),
                    phpversion()
                )
            );
        } // Check required fields
        elseif ( ! $this->public_key || ! $this->private_key ) {
            echo '<div class="error"><p>' .
                 __(
                     'Gateway Error: Please enter your public and private keys',
                     'woocommerce-gateway-simplify-commerce'
                 ) .
                 '</p></div>';
        }
    }

    /**
     * Check if this gateway is enabled.
     *
     * @return bool
     */
    public function is_available() {
        if ( 'yes' !== $this->enabled ) {
            return false;
        }

        if ( ! $this->public_key || ! $this->private_key ) {
            return false;
        }

        return true;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        $download_url = add_query_arg(
            array(
                'action' => 'download_log',
                '_wpnonce' => wp_create_nonce( 'mpgs_download_log' )
        ), admin_url( 'admin-ajax.php' ) );

        $this->form_fields = array(
            'heading'            => array(
                'title'       => null,
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: 1. MPGS Simplify module vesion, 2. MPGS Simplify SDK version. */
                    __( 'Plugin version: %1$s<br />SDK version: %2$s', 'woocommerce-gateway-simplify-commerce' ),
                    MPGS_SIMPLIFY_MODULE_VERSION,
                    MPGS_SIMPLIFY_SDK_VERSION
                ),
            ),
            'enabled'             => array(
                'title'       => __( 'Enable/Disable', 'woocommerce-gateway-simplify-commerce' ),
                'label'       => __(
                    'Enable Mastercard Payment Gateway Services - Simplify',
                    'woocommerce-gateway-simplify-commerce'
                ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title'               => array(
                'title'       => __( 'Title', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'text',
                'description' => __(
                    'This controls the title which the user sees during checkout.',
                    'woocommerce-gateway-simplify-commerce'
                ),
                'default'     => __( 'Pay with Card', 'woocommerce-gateway-simplify-commerce' ),
                'desc_tip'    => true
            ),
            'description'         => array(
                'title'       => __( 'Description', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'text',
                'description' => __(
                    'This controls the description which the user sees during checkout.',
                    'woocommerce-gateway-simplify-commerce'
                ),
                'default'     => 'Pay with your card via Mastercard Payment Gateway Services - Simplify.',
                'desc_tip'    => true
            ),
            'modal_color'         => array(
                'title'       => __( 'Color', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'color',
                'description' => __(
                    'Set the color of the buttons and titles on the modal dialog.',
                    'woocommerce-gateway-simplify-commerce'
                ),
                'default'     => '#a46497',
                'desc_tip'    => true
            ),
            'gateway_url'        => array(
                'title'   => __( 'Gateway', 'woocommerce-gateway-simplify-commerce' ),
                'type'    => 'select',
                'options' => array(
                    self::SIP_HOST      => __( 'Simplify URL', 'woocommerce-gateway-simplify-commerce' ),
                    self::SIP_CUSTOM    => __( 'Custom URL', 'woocommerce-gateway-simplify-commerce' ),
                ),
                'default' => self::SIP_HOST,
            ),
            'custom_gateway_url' => array(
                'title'       => __( 'Custom Gateway Host', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'text',
                'description' => __( 'Enter only the hostname without https prefix. For example www.simplify.com.',
                    'woocommerce-gateway-simplify-commerce' )
            ),
            'txn_mode'            => array(
                'title'       => __( 'Transaction Mode', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'select',
                'options'     => array(
                    self::TXN_MODE_PURCHASE  => __( 'Payment', 'woocommerce-gateway-simplify-commerce' ),
                    self::TXN_MODE_AUTHORIZE => __( 'Authorization', 'woocommerce-gateway-simplify-commerce' )
                ),
                'default'     => self::TXN_MODE_PURCHASE,
                'description' => __(
                    'In "Payment" mode, the customer is charged immediately. In "Authorization" mode, the transaction is only authorized and the capturing of funds is a manual process that you do using the Woocommerce admin panel. You will need to capture the authorization typically within a week of an order being placed. If you do not, you will lose the payment and will be unable to capture it again even though you might have shipped the order. Please contact your gateway for more details.',
                    'woocommerce-gateway-simplify-commerce'
                ),
            ),
            'integration_mode'    => array(
                'title'       => __( 'Integration Mode', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'select',
                'options'     => array(
                    self::INTEGRATION_MODE_EMBEDDED => __( 'Embedded Form', 'woocommerce-gateway-simplify-commerce' ),
                    self::INTEGRATION_MODE_MODAL    => __( 'Modal Form', 'woocommerce-gateway-simplify-commerce' )
                ),
                'default'     => self::INTEGRATION_MODE_EMBEDDED,
                'description' => __(
                    'In the "Embedded Form" mode, the form to input payment details will be displayed as a part of the Checkout Page. In the "Modal Form" mode, the form to input payment details will be hidden until the payment confirmation moment, then appear over the screen.',
                    'woocommerce-gateway-simplify-commerce'
                ),
            ),
            'debug'              => array(
                'title'       => __( 'Debug Logging', 'woocommerce-gateway-simplify-commerce' ),
                'label'       => __( 'Enable Debug Logging', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'checkbox',
                'description' => sprintf(
                    /* translators: Gateway API Credentials */
                    __( 'All communications with the Simplify Mastercard Gateway are encrypted and logged in the ./wp-content/mastercard_simplify.log. The decrypted log file can be <a href="%s">downloaded</a> here.', 'woocommerce-gateway-simplify-commerce' ),
                    $download_url
                ),
                'default'     => 'no',
                
            ),
            'sandbox'             => array(
                'title'       => __( 'Sandbox', 'woocommerce-gateway-simplify-commerce' ),
                'label'       => __( 'Enable Sandbox Mode', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'checkbox',
                'description' => __(
                    'Place the payment gateway in sandbox mode using sandbox API keys (real payments will not be taken).',
                    'woocommerce-gateway-simplify-commerce'
                ),
                'default'     => 'yes'
            ),
            'sandbox_public_key'  => array(
                'title'       => __( 'Sandbox Public Key', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'text',
                'description' => __(
                    'Get your API keys from your merchant account: Account Settings > API Keys.',
                    'woocommerce-gateway-simplify-commerce'
                ),
                'default'     => '',
                'desc_tip'    => true
            ),
            'sandbox_private_key' => array(
                'title'       => __( 'Sandbox Private Key', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'text',
                'description' => __(
                    'Get your API keys from your merchant account: Account Settings > API Keys.',
                    'woocommerce-gateway-simplify-commerce'
                ),
                'default'     => '',
                'desc_tip'    => true
            ),
            'public_key'          => array(
                'title'       => __( 'Public Key', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'text',
                'description' => __(
                    'Get your API keys from your merchant account: Account Settings > API Keys.',
                    'woocommerce-gateway-simplify-commerce'
                ),
                'default'     => '',
                'desc_tip'    => true
            ),
            'private_key'         => array(
                'title'       => __( 'Private Key', 'woocommerce-gateway-simplify-commerce' ),
                'type'        => 'text',
                'description' => __(
                    'Get your API keys from your merchant account: Account Settings > API Keys.',
                    'woocommerce-gateway-simplify-commerce'
                ),
                'default'     => '',
                'desc_tip'    => true
            ),
            'handling_fee'      => array(
                'title'       => __( 'Handling Fee', 'mastercard' ),
                'type'        => 'title',
                'description' => __( 'The handling amount for the order, including taxes on the handling.', 'mastercard' ),
            ),
            'hf_enabled'            => array(
                'title'       => __( 'Enable/Disable', 'mastercard' ),
                'label'       => __( 'Enable', 'mastercard' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'handling_text'      => array(
                'title'       => __( 'Handling Fee Text', 'mastercard' ),
                'type'        => 'text',
                'description' => __( 'Display text for handling fee.', 'mastercard' ),
                'default'     => '',
                'css'         => 'min-height: 33px;'
            ),
            'hf_amount_type'     => array(
                'title'   => __( 'Applicable Amount Type', 'mastercard' ),
                'type'    => 'select',
                'options' => array(
                    self::HF_FIXED      => __( 'Fixed', 'mastercard' ),
                    self::HF_PERCENTAGE => __( 'Percentage', 'mastercard' ),
                ),
                'default' => self::HF_FIXED,
            ),
            'handling_fee_amount' => array(
                'title'       => __( 'Amount', 'mastercard' ),
                'type'        => 'text',
                'description' => __( 'The total amount for handling fee; Eg: 10.00 or 10%.', 'mastercard' ),
                'default'     => '',
                'css'         => 'min-height: 33px;'
            )
        );
    }

    /**
     * Returns the POSTed data, to be used to save the settings.
     * @return array
     */
    public function get_post_data() {
        foreach ( $this->form_fields as $form_field_key => $form_field_value ) {
            if ( $form_field_value['type'] == "select_card_types" ) {
                $form_field_key_select_card_types           = $this->plugin_id . $this->id . "_" . $form_field_key;
                $select_card_types_values                   = array();
                $_POST[ $form_field_key_select_card_types ] = $select_card_types_values;
            }
        }

        if ( ! empty( $this->data ) && is_array( $this->data ) ) {
            return $this->data;
        }

        return $_POST;
    }

    /**
     * Payment form on checkout page.
     */
    public function payment_fields() {
        $description = $this->get_description();

        if ( 'yes' == $this->sandbox ) {
            $description .= sprintf(
                " %s",
                sprintf(
                    __(
                        'TEST MODE ENABLED. Use the following test cards: %s',
                        'woocommerce-gateway-simplify-commerce'
                    ),
                    '<a href="https://www.simplify.com/commerce/docs/testing/test-card-numbers">https://www.simplify.com/commerce/docs/testing/test-card-numbers</a>'
                )
            );
        }

        if ( $description ) {
            echo wpautop( wptexturize( trim( $description ) ) );
        }
    }

    /**
     * Process standard payments.
     *
     * @param WC_Order $order
     * @param string $cart_token
     *
     * @return array
     * @uses   Simplify_BadRequestException
     * @uses   Simplify_ApiException
     */
    protected function process_standard_payments( $order, $cart_token = '' ) {
        try {
            if ( empty( $cart_token ) ) {
                $error_msg = __(
                    'Please make sure your card details have been entered correctly and that your browser supports JavaScript.',
                    'woocommerce-gateway-simplify-commerce'
                );

                if ( 'yes' == $this->sandbox ) {
                    $error_msg .= sprintf(
                        " %s",
                        __(
                            'Developers: Please make sure that you\'re including jQuery and there are no JavaScript errors on the page.',
                            'woocommerce-gateway-simplify-commerce'
                        )
                    );
                }

                throw new Simplify_ApiException( $error_msg );
            }

            // We need to figure out if we want to charge the card token (new unsaved token, no customer, etc)
            // or the customer token (just saved method, previously saved method)
            $pass_tokens = array();

            if ( ! empty ( $cart_token ) ) {
                $pass_tokens['token'] = $cart_token;
            }

            $payment_response = $this->do_payment( $order, $this->get_total( $order ), $pass_tokens );

            if ( is_wp_error( $payment_response ) ) {
                throw new Simplify_ApiException( $payment_response->get_error_message() );
            } else {
                // Remove cart
                WC()->cart->empty_cart();

                // Return thank you page redirect
                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url( $order )
                );
            }

        } catch ( Simplify_ApiException $e ) {
            if ( $e instanceof Simplify_BadRequestException && $e->hasFieldErrors() && $e->getFieldErrors() ) {
                foreach ( $e->getFieldErrors() as $error ) {
                    wc_add_notice( $error->getFieldName() . ': "' . $error->getMessage() . '" (' . $error->getErrorCode() . ')',
                        'error' );
                }
            } else {
                wc_add_notice( $e->getMessage(), 'error' );
            }

            return array(
                'result'   => 'fail',
                'redirect' => ''
            );
        }
    }

    /**
     * do payment function.
     *
     * @param WC_order $order
     * @param int $amount (default: 0)
     * @param array $token
     *
     * @return bool|WP_Error
     * @uses  Simplify_BadRequestException
     */
    public function do_payment( $order, $amount = 0, $token = array() ) {
        if ( ! is_array( $token ) ) {
            $token = array( 'token' => $token );
        }

        if ( $this->get_total( $order ) < 50 ) {
            return new WP_Error(
                'simplify_error',
                __(
                    'Sorry, the minimum allowed order total is 0.50 to use this payment method.',
                    'woocommerce-gateway-simplify-commerce'
                )
            );
        }

        if ( (int) $amount !== $this->get_total( $order ) ) {
            return new WP_Error( 'simplify_error',
                __( 'Amount mismatch.', 'woocommerce' ) );
        }

        try {
            // Create customer
            $customer = Simplify_Customer::createCustomer( array(
                'email'     => $order->get_billing_email(),
                'name'      => trim( $order->get_formatted_billing_full_name() ),
                'reference' => $order->get_id()
            ) );

            // Charge the customer
            $order_builder = new Mastercard_Simplify_CheckoutBuilder( $order );
            $data = array(
                'amount'      => $this->get_total( $order ), // In cents. Rounding to avoid floating point errors.
                'description' => sprintf(
                    __( 'Order #%s', 'woocommerce-gateway-simplify-commerce' ),
                    $order->get_order_number()
                ),
                'currency'    => strtoupper( get_woocommerce_currency() ),
                'reference'   => $order->get_id(),
                'order'       => $order_builder->getOrder(),
            );

            if ( is_object( $customer ) && '' != $customer->id ) {
                $data['customer'] = wc_clean( $customer->id );
            }

            $data    = array_merge( $data, $token ); 
            $this->log( 'Payment Request', json_encode( $data ) );
            $payment = Simplify_Payment::createPayment( $data );
            $this->log( 'Payment Response', $payment );

        } catch ( Exception $e ) {
            $error_message = $e->getMessage();

            if ( $e instanceof Simplify_BadRequestException && $e->hasFieldErrors() && $e->getFieldErrors() ) {
                $error_message = $err_msg = '';
                foreach ( $e->getFieldErrors() as $error ) {
                    $error_message .= ' ' . $error->getFieldName() . ': "' . $error->getMessage() . '" (' . $error->getErrorCode() . ')';
                    $err_msg = $error->getMessage();
                }
            }

            $order->add_order_note( sprintf( __( 'Gateway payment error 1: %s', 'woocommerce-gateway-simplify-commerce' ),
                $error_message ) );

            return new WP_Error( 'simplify_payment_declined', $err_msg, array( 'status' => $e->getCode() ) );
        }

        if ( 'APPROVED' == $payment->paymentStatus ) {
            // Payment complete
            $order->payment_complete( $payment->id );

            // Add order note
            $order->add_order_note(
                sprintf(
                    __( 'Gateway payment approved (ID: %s, Auth Code: %s)',
                        'woocommerce-gateway-simplify-commerce'
                    ),
                    $payment->id,
                    $payment->authCode
                )
            );

            return true;
        } else {
            $order->add_order_note( __( 'Gateway payment declined', 'woocommerce-gateway-simplify-commerce' ) );

            return new WP_Error(
                'simplify_payment_declined',
                __(
                    'Payment was declined by your gateway - please try another card.',
                    'woocommerce-gateway-simplify-commerce'
                )
            );
        }
    }

    /**
     * Return Gateway URL
     * 
     * @since 2.4.0
     * 
     * @return string $gateway_url
     */
    protected function get_gateway_url() {

        $gateway_url = $this->get_option( 'gateway_url', self::SIP_HOST );

        if( ! $gateway_url ) {
            $gateway_url = "www.simplify.com";
        } else if ( $gateway_url === self::SIP_CUSTOM ) {
            $gateway_url = $this->get_option( 'custom_gateway_url' );
        }

        return $gateway_url;
    }

    /**
     * Process standard payments.
     *
     * @param WC_Order $order
     *
     * @return array
     */
    protected function process_hosted_payments( $order ) {
        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url( true )
        );
    }

    /**
     * Process the payment.
     *
     * @param int $order_id
     *
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        return $this->process_hosted_payments( $order );
    }

    /**
     * Hosted payment args.
     *
     * @param WC_Order $order
     *
     * @return array
     */
    protected function get_hosted_payments_args( $order ) {
        $args = apply_filters( 'woocommerce_simplify_commerce_hosted_args', array(
            'sc-key'        => $this->public_key,
            'customer-name' => sprintf( "%s %s", $order->get_billing_first_name(), $order->get_billing_last_name() ),
            'amount'        => $this->get_total( $order ),
            'currency'      => strtoupper( get_woocommerce_currency() ),
            'reference'     => $order->get_id(),
            'description'   => sprintf( __( 'Order #%s', 'woocommerce-gateway-simplify-commerce' ), $order->get_order_number() ),
            'receipt'       => 'false',
            'color'         => $this->modal_color,
            'redirect-url'  => WC()->api_request_url( 'WC_Gateway_Simplify_Commerce' ),
            'operation'     => 'create.token',
        ), $order->get_id() );

        return $args;
    }

    protected function attempt_transliteration( $field ) {
        $encode = mb_detect_encoding( $field );
        if ( $encode !== 'ASCII' ) {
            if ( function_exists( 'transliterator_transliterate' ) ) {
                $field = transliterator_transliterate( 'Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove', $field );
            } else {
                // fall back to iconv if intl module not available
                $field = remove_accents( $field );
                $field = iconv( $encode, 'ASCII//TRANSLIT//IGNORE', $field );
                $field = str_ireplace( '?', '', $field );
                $field = trim( $field );
            }
        }

        return $field;
    }

    /**
     * @param string|int|float $totalAmount
     *
     * @return int
     */
    protected function get_total_amount( $total_amount ) {
        $price_decimals   = wc_get_price_decimals();

        switch ( $price_decimals ) {
            case '0':
            case '1':
            case '2':
                $price_multiplier = 100;
                break;
            case '3':
                $price_multiplier = 1000;
                break;        
            default:
                $price_multiplier = 100;
                break;
        }

        return (int) round( (float) $total_amount * $price_multiplier );
    }

    /**
     * @param WC_Order $order
     *
     * @return int
     */
    protected function get_total( $order ) {
        return $this->get_total_amount( $order->get_total() );
    }

    /**
     * This function processes the admin options.
     *
     * @return array $saved Admin Options.
     */
    public function process_admin_options() {
        $saved = parent::process_admin_options();
        if( 'simplify_commerce' === $this->id ) {
            static $error_added = false;
            if( isset( $this->settings['hf_amount_type'] ) && 'percentage' === $this->settings['hf_amount_type'] ) {
                if ( absint( $this->settings['handling_fee_amount'] ) > 100 ) {
                    if ( ! $error_added ) {
                        WC_Admin_Settings::add_error( __( 'The maximum allowable percentage is 100.', 'woocommerce-gateway-simplify-commerce' ) );
                        $error_added = true;
                    }
                    $this->update_option( 'handling_fee_amount', 100 );
                }
            }
        }

        return $saved;
    }

    /**
     * Receipt page.
     *
     * @param int $order_id
     */
    public function receipt_page( $order_id ) {
        if ( $this->is_modal_integration_model ) {
            return $this->get_modal_receipt_page( $order_id );
        } else {
            return $this->get_embedded_receipt_page( $order_id );
        }
    }

    /**
     * Receipt Page for Modal Integration Mode.
     *
     * @param int $order_id
     */
    protected function get_modal_receipt_page( int $order_id ) {
        $order = wc_get_order( $order_id );
        echo "<p>" .
             __(
                 'Thank you for your order, please click the button below to pay with credit card using Mastercard Payment Gateway Services - Simplify.',
                 'woocommerce-gateway-simplify-commerce'
             ) .
             "</p>";

        $args        = $this->get_hosted_payments_args( $order );
        $button_args = array();
        foreach ( $args as $key => $value ) {
            $value = $this->attempt_transliteration( $value );

            if ( ! $value ) {
                continue;
            }

            $button_args[] = sprintf(
                "data-%s=\"%s\"",
                esc_attr( $key ),
                esc_attr( $value )
            );
        }

        $gateway_url = $this->get_gateway_url();
        $clean_gateway_url = preg_replace( '/^https:\/\//i', '', $gateway_url );

        echo '<script type="text/javascript" src="https://' . $clean_gateway_url . '/commerce/simplify.pay.js"></script>
			<button class="button alt" id="simplify-payment-button" ' . implode( ' ',
                $button_args ) . '>' . __( 'Pay Now',
                'woocommerce-gateway-simplify-commerce' ) . '</button> <a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Cancel order &amp; restore cart',
                'woocommerce-gateway-simplify-commerce' ) . '</a>
			<script>
				var intervalId = setInterval(function () {
					if (window.SimplifyCommerce) {
						jQuery("#simplify-payment-button").trigger("click");
						clearInterval(intervalId);
					}
				}, 100);
			</script>
			';
    }

    /**
     * Receipt Page for Embedded Integration Mode.
     *
     * @param int $order_id
     */
    protected function get_embedded_receipt_page( int $order_id ) {
        $order = wc_get_order( $order_id );
        echo '<p>' .
             __(
                 'Thank you for your order, please click the button below to pay with credit card using Mastercard Payment Gateway Services - Simplify.',
                 'woocommerce-gateway-simplify-commerce'
             ) .
             '</p>';

        $args = $this->get_hosted_payments_args( $order );

        $iframe_args = array();
        foreach ( $args as $key => $value ) {
            $value = $this->attempt_transliteration( $value );
            if ( ! $value ) {
                continue;
            }
            $iframe_args[] = sprintf(
                "data-%s=\"%s\"",
                esc_attr( $key ),
                esc_attr( $value )
            );
        }

        // TEMPLATE VARS
        $gateway_url = $this->get_gateway_url();
        $clean_gateway_url = preg_replace( '/^https:\/\//i', '', $gateway_url );
        $redirect_url = WC()->api_request_url( 'WC_Gateway_Simplify_Commerce' );
        $is_purchase  = $this->txn_mode === self::TXN_MODE_PURCHASE;
        $public_key   = $this->public_key;
        // TEMPLATE VARS

        require plugin_basename( 'embedded-template.php' );
    }

    /**
     * Return handler for Hosted Payments.
     */
    public function return_handler() {
        @ob_clean();
        header( 'HTTP/1.1 200 OK' ); 

        // Transaction mode = Payment/Purchase
        if ( isset( $_REQUEST['reference'] ) && isset( $_REQUEST['cardToken'] ) && $this->txn_mode === self::TXN_MODE_PURCHASE ) {
            $order_id = absint( $_REQUEST['reference'] );
            $order    = wc_get_order( $order_id );

            $order_complete = $this->do_payment( $order, $_REQUEST['amount'], $_REQUEST['cardToken'] );

            if ( ! $order_complete | $order_complete instanceof WP_Error ) {
                $order->update_status( 'failed',
                    __( 'Payment was declined by your gateway.', 'woocommerce-gateway-simplify-commerce' )
                );

                if( $order_complete->errors['simplify_payment_declined'][0] != '' ) {

                    $error_message = $order_complete->errors['simplify_payment_declined'][0];

                    wc_add_notice(
                        __( $error_message, 'woocommerce-gateway-simplify-commerce' ),
                        'error'
                    );
                } else {
                    wc_add_notice(
                        __( 'Your payment was declined.', 'woocommerce-gateway-simplify-commerce' ),
                        'error'
                    );
                }   

                wp_redirect( wc_get_page_permalink( 'cart' ) );
                exit();
            }
        }

        // Transaction mode = Authorize
        if ( isset( $_REQUEST['reference'] ) && isset( $_REQUEST['cardToken'] ) && $this->txn_mode === self::TXN_MODE_AUTHORIZE ) {
            $order_id = absint( $_REQUEST['reference'] );
            $order    = wc_get_order( $order_id );

            $order_complete = $this->authorize( $order, $_REQUEST['cardToken'], $_REQUEST['amount'] );  

            if ( ! $order_complete ) {
                $order->update_status( 'failed',
                    __( 'Authorization was declined by your gateway.', 'woocommerce-gateway-simplify-commerce' )
                );

                wc_add_notice(
                    __( 'Payment was declined by your gateway - please try another card.', 'woocommerce-gateway-simplify-commerce' ),
                    'error'
                );

                wp_redirect( wc_get_page_permalink( 'cart' ) );
                exit();
            }
        }

        wp_redirect( $this->get_return_url( $order ) );
        exit();
    }

    /**
     * @param WC_Order $order
     * @param string $card_token
     * @param string $amount
     *
     * @return bool
     */
    protected function authorize( $order, $card_token, $amount ) {

        if ( (int) $amount !== $this->get_total( $order ) ) {
            wc_add_notice( 'Amount mismatch', 'error' );
            wp_redirect( wc_get_page_permalink( 'cart' ) );
        }

        try {
            // Create customer
            $customer = Simplify_Customer::createCustomer( array(
                'email'     => $order->get_billing_email(),
                'name'      => trim( $order->get_formatted_billing_full_name() ),
                'reference' => $order->get_id()
            ) );

            $order_builder = new Mastercard_Simplify_CheckoutBuilder( $order );

            $data          = array(
                'amount'      => $amount,
                'token'       => $card_token,
                'reference'   => $order->get_id(),
                'currency'    => strtoupper( $order->get_currency() ),
                'description' => sprintf(
                    __( 'Order #%s', 'woocommerce-gateway-simplify-commerce' ),
                    $order->get_order_number()
                ),
                'order'       => $order_builder->getOrder(),
            );
            $this->log( 'Authorize Request', json_encode( $data ) );
            if ( is_object( $customer ) && '' != $customer->id ) {
                $data['customer'] = wc_clean( $customer->id );
            }

            $authorization = Simplify_Authorization::createAuthorization( $data );
            $this->log( 'Authorize Response', $authorization );

            return $this->process_authorization_order_status(
                $order,
                $authorization->id,
                $authorization->paymentStatus,
                $authorization->authCode,
                $authorization->captured
            );

        } catch ( Exception $e ) {

            $error_message = $e->getMessage();

            if ( $e instanceof Simplify_BadRequestException && $e->hasFieldErrors() && $e->getFieldErrors() ) {
                $error_message = $err_msg = '';
                foreach ( $e->getFieldErrors() as $error ) {
                    $error_message .= ' ' . $error->getFieldName() . ': "' . $error->getMessage() . '" (' . $error->getErrorCode() . ')';
                    $err_msg = $error->getMessage();
                }
            }

            $order->update_status( 'failed',
                __( 'Authorization was declined by your gateway.', 'woocommerce-gateway-simplify-commerce' )
            );

            $order->add_order_note( sprintf( __( 'Gateway payment error 2: %s', 'woocommerce-gateway-simplify-commerce' ),
                $error_message ) );

            wc_add_notice( 
                sprintf( __( 'Gateway payment error 3: %s', 'woocommerce-gateway-simplify-commerce' ), $error_message ),
                'error'
            );

            wp_redirect( wc_get_page_permalink( 'cart' ) );
            exit();
        }       
    }

    /**
     * Process the order status.
     *
     * @param WC_Order $order
     * @param string $payment_id
     * @param string $status
     * @param string $auth_code
     * @param bool $is_capture
     *
     * @return bool
     */
    public function process_authorization_order_status( $order, $payment_id, $status, $auth_code, $is_capture = false ) {
        if ( 'APPROVED' === $status ) {
            $order->add_meta_data( '_simplify_order_captured', $is_capture ? '1' : '0' );
            $order->add_meta_data( '_simplify_authorization', $payment_id );

            if ( $is_capture ) {
                // Payment was captured, so call payment complete.
                $order->payment_complete( $payment_id );

                // Add order note
                $order->add_order_note( sprintf( __( 'Gateway payment approved (ID: %s, Auth Code: %s)',
                    'woocommerce-gateway-simplify-commerce' ), $payment_id, $auth_code ) );
            } else {
                // Payment is authorized only. Must be captured at a later time.
                $order->update_status( 'processing' );

                // Add order note
                $order->add_order_note( sprintf( __( 'Gateway authorization approved (ID: %s, Auth Code: %s)',
                    'woocommerce-gateway-simplify-commerce' ), $payment_id, $auth_code ) );
            }

            // Remove cart
            WC()->cart->empty_cart();

            return true;
        }

        return false;
    }

    /**
     * Process refunds.
     * WooCommerce 2.2 or later.
     *
     * @param int $order_id
     * @param float $amount
     * @param string $reason
     *
     * @return bool|WP_Error
     * @uses   Simplify_BadRequestException
     * @uses   Simplify_ApiException
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        try {
            $order = wc_get_order( $order_id );

            $payment_id =
                get_post_meta( $order_id, '_transaction_id', true ) ?:
                    get_post_meta( $order_id, '_simplify_capture', true );

            if ( ! $payment_id ) {
                throw new Simplify_ApiException(
                    __(
                        'It is not possible to refund this order using WooCommerce UI. Please, proceed with a refund in the Gateway UI.',
                        'woocommerce-gateway-simplify-commerce'
                    )
                );
            }

            $defaultRefundReason = sprintf(
                __( 'Refund for Order #%s', 'woocommerce-gateway-simplify-commerce' ),
                $order->get_order_number()
            );

            $refund_data = array(
                'amount'    => $this->get_total_amount( $amount ),
                'payment'   => $payment_id,
                'reason'    => $reason ?: $defaultRefundReason,
                'reference' => $order_id,
            );
            $this->log( 'Refund Request', json_encode( $refund_data ) );
            $refund = Simplify_Refund::createRefund( $refund_data );
            $this->log( 'Refund Response', $refund );

            if ( 'APPROVED' === $refund->paymentStatus ) {
                $order->add_order_note(
                    sprintf(
                        __( 'Gateway refund approved (ID: %s, Amount: %s)', 'woocommerce-gateway-simplify-commerce' ),
                        $refund->id,
                        $amount
                    )
                );

                return true;
            } else {
                throw new Simplify_ApiException(
                    __( 'Refund was declined.', 'woocommerce-gateway-simplify-commerce' )
                );
            }

        } catch ( Simplify_ApiException $e ) {
            if ( $e instanceof Simplify_BadRequestException && $e->hasFieldErrors() && $e->getFieldErrors() ) {
                foreach ( $e->getFieldErrors() as $error ) {
                    return new WP_Error( 'simplify_refund_error',
                        $error->getFieldName() . ': "' . $error->getMessage() . '" (' . $error->getErrorCode() . ')' );
                }
            } else {
                return new WP_Error( 'simplify_refund_error', $e->getMessage() );
            }
        }

        return false;
    }

    /**
     * Logs a message with a specific text.
     *
     * @param string $text   The text to include in the log.
     * @param mixed  $message The message or data to be logged.
     */
    public function log( $text, $message ) {
        if( $this->logging_level ) { 
            $logger  = new Mastercard_Simplify_Api_Logger( $this->hash );
            $message = date( 'Y-m-d g:i a' ) . ' : ' . $text . ' :- ' . $message;
            $logger->write_encrypted_log( $message );
        }
    }

    /**
     * Handles the download of the decrypted log file.
     *
     * This function initiates the process to allow users to download
     * a decrypted log file. It ensures proper file access and
     * sets appropriate headers for file download.
     */
    public function download_decrypted_log() {
        if ( isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'mpgs_download_log' ) ) {
            $logger = new Mastercard_Simplify_Api_Logger( $this->hash );
            $logger->read_decrypted_log();
            wp_die();
        } else {
            wp_safe_redirect( admin_url( 'page=wc-settings&tab=checkout&section=simplify_commerce' ) );
            wp_die();
        }
    }

    /**
     * Adds a handling fee to the WooCommerce cart calculation.
     * 
     * This ensures that the handling fee is added during the cart calculation process.
     */
    public function add_handling_fee() {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        
        if ( isset( $this->hf_enabled ) && 'yes' === $this->hf_enabled ){
            $handling_text = $this->get_option( 'handling_text' );
            $amount_type   = $this->get_option( 'hf_amount_type' );
            $handling_fee  = $this->get_option( 'handling_fee_amount' ) ? $this->get_option( 'handling_fee_amount' ) : 0;

            if ( self::HF_PERCENTAGE === $amount_type ) {
                $surcharge = (float)( WC()->cart->cart_contents_total ) * ( (float) $handling_fee / 100 );
            } else {
                $surcharge = $handling_fee;
            }

            WC()->cart->add_fee( $handling_text, $surcharge, true, '' );
        }
    }
}
