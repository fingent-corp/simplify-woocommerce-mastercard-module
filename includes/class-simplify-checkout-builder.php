<?php
/**
 * Copyright (c) 2023-2026 Mastercard
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
	exit; // Exit if accessed directly.
}

/**
 * Main class of the Mastercard Simplify Checkout Builder
 *
 * Represents a gateway service for processing Mastercard transactions.
 */
class Mastercard_Simplify_CheckoutBuilder {
	/**
	 * WooCommerce Order
	 *
	 * @var WC_Order
	 */
	protected $order = null;

	/**
	 * Mastercard_Model_AbstractBuilder constructor.
	 *
	 * @param array $order WC_Order.
	 */
	public function __construct( $order ) {
		$this->order = $order;
	}

	/**
	 * A function that checks if a value is safe and within a specified limit.
	 *
	 * @param string $value - The value to be checked.
	 * @param number $limited - The limit to compare the value against.
	 *
	 * @return boolean Returns true if the value is safe and within the limit, otherwise returns false.
	 */
	public static function safe( $value, $limited = 0 ) {
		if ( '' === $value ) {
			return null;
		}

		if ( $limited > 0 && strlen( $value ) > $limited ) {
			return substr( $value, 0, $limited );
		}

		return $value;
	}

	/**
	 * Retrieves the billing information.
	 *
	 * @return array The billing information.
	 */
	public function getBilling() { // phpcs:ignore

		if ( $this->orderIsVirtual( $this->order ) ) {
			return null;
		}

		$billing = array();

		if( WC()->countries->get_base_address() ) {
			$billing['shippingFromAddress']['line1'] = self::safe( WC()->countries->get_base_address(), 100 );
		}

		if( WC()->countries->get_base_address_2() ) {
			$billing['shippingFromAddress']['line2'] = self::safe( WC()->countries->get_base_address_2(), 100 );
		}

		if( WC()->countries->get_base_city() ) {
			$billing['shippingFromAddress']['city'] = self::safe( WC()->countries->get_base_city(), 100 );
		}

		if( WC()->countries->get_base_postcode() ) {
			$billing['shippingFromAddress']['zip'] = self::safe( WC()->countries->get_base_postcode(), 10 );
		}

		if( WC()->countries->get_base_country() ) {
			$billing['shippingFromAddress']['country'] = self::safe( WC()->countries->get_base_country(), 20 );
		}

		if( WC()->countries->get_base_state() ) {
			$billing['shippingFromAddress']['state'] = self::safe( WC()->countries->get_base_state(), 20 );
		}

		return $billing;
	}

	/**
	 * Determines if an order is virtual.
	 *
	 * @param array $order WC_Order.
	 *
	 * @return bool
	 */
	public function orderIsVirtual( $order ) { // phpcs:ignore
		if ( empty( $this->order->get_shipping_address_1() ) ) {
			return true;
		}

		if ( empty( $this->order->get_shipping_first_name() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Retrieves the shipping information.
	 *
	 * @return array|null
	 */
	public function getShipping() { // phpcs:ignore
		if ( $this->orderIsVirtual( $this->order ) ) {
			return null;
		}

		$shipping = array();

		if( $this->order->get_shipping_address_1() ) {
			$shipping['shippingAddress']['line1'] = self::safe( $this->order->get_shipping_address_1(), 100 );
		}

		if( $this->order->get_shipping_address_2() ) {
			$shipping['shippingAddress']['line2'] = self::safe( $this->order->get_shipping_address_2(), 100 );
		}

		if( $this->order->get_shipping_city() ) {
			$shipping['shippingAddress']['city'] = self::safe( $this->order->get_shipping_city(), 100 );
		}

		if( $this->order->get_shipping_postcode() ) {
			$shipping['shippingAddress']['zip'] = self::safe( $this->order->get_shipping_postcode(), 10 );
		}

		if( $this->order->get_shipping_country() ) {
			$shipping['shippingAddress']['country'] = self::safe( $this->order->get_shipping_country(), 10 );
		}

		if( $this->order->get_shipping_state() ) {
			$shipping['shippingAddress']['state'] = self::safe( $this->order->get_shipping_state(), 20 );
		}

		return $shipping;
	}

	/**
	 * Credit or debit card being used to apply the payment to.
	 *
	 * @return array|null
	 */
	public function getCardInfo() { // phpcs:ignore
		if ( $this->orderIsVirtual( $this->order ) ) {
			return null;
		}

		$card_info = array();

		if( $this->order->get_billing_first_name() ) {
			$card_info['name'] = self::safe( $this->order->get_billing_first_name(), 50 ) . ' ' . self::safe( $this->order->get_billing_last_name(), 50 );
		}

		if( $this->order->get_shipping_address_1() ) {
			$card_info['addressLine1'] = self::safe( $this->order->get_shipping_address_1(), 100 );
		}

		if( $this->order->get_shipping_address_2() ) {
			$card_info['addressLine2'] = self::safe( $this->order->get_shipping_address_2(), 100 );
		}

		if( $this->order->get_shipping_city() ) {
			$card_info['addressCity'] = self::safe( $this->order->get_shipping_city(), 100 );
		}

		if( $this->order->get_shipping_postcode() ) {
			$card_info['addressZip'] = self::safe( $this->order->get_shipping_postcode(), 10 );
		}

		if( $this->order->get_shipping_country() ) {
			$card_info['addressCountry'] = self::safe( $this->order->get_shipping_country(), 10 );
		}

		if( $this->order->get_shipping_state() ) {
			$card_info['addressState'] = self::safe( $this->order->get_shipping_state(), 10 );
		}

		return $card_info;
	}

	/**
	 * Retrieves the customer information.
	 *
	 * @return array
	 */
	public function getCustomer() { // phpcs:ignore
		return array(
			'email' => self::safe( $this->order->get_billing_email(), 100 ),
			'name'  => self::safe( $this->order->get_billing_first_name(), 50 ) . ' ' . self::safe( $this->order->get_billing_last_name(), 50 ),
		);
	}

	/**
	 * Retrieves the customer information of the order.
	 *
	 * @return array
	 */
	public function getOrderCustomer() { // phpcs:ignore
		return array(
			'customerEmail' => self::safe( $this->order->get_billing_email(), 100 ),
			'customerName'  => self::safe( $this->order->get_billing_first_name(), 50 ) . ' ' . self::safe( $this->order->get_billing_last_name(), 50 )
		);
	}

	/**
	 * Retrieves the order information.
	 *
	 * @return array
	 */
	public function getOrder() { // phpcs:ignore

		$customer = $this->getOrderCustomer() ? $this->getOrderCustomer() : array();
		$billing  = $this->getBilling() ? $this->getBilling() : array();
		$shipping = $this->getShipping() ? $this->getShipping() : array();

		return array_merge( $customer, $billing, $shipping );
	}

	/**
	 * Formatted price.
	 *
	 * @param float $price Unformatted price.
	 * @return string
	 */
	public function formattedPrice( $price ) { // phpcs:ignore

		$original_price = $price;
		$args           = array(
			'currency'          => '',
			'decimal_separator' => wc_get_price_decimal_separator(),
			'decimals'          => wc_get_price_decimals(),
			'price_format'      => get_woocommerce_price_format(),
		);
		$price          = apply_filters( 'formatted_mastercard_price', number_format( $price, $args['decimals'], $args['decimal_separator'], '' ), $price, $args['decimals'], $args['decimal_separator'], '', $original_price );

		return $price;
	}
}
