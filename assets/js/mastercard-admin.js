/*
 * Copyright (c) 2019-2023 Mastercard
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
jQuery(function ($) {
    'use strict';
    var wc_mastercard_admin = {
        init: function () {
            var gateway_url = $('#woocommerce_simplify_commerce_custom_gateway_url').parents('tr').eq(0);

            $('#woocommerce_simplify_commerce_gateway_url').on('change', function () {
                if ($(this).val() === 'custom') {
                    gateway_url.show();
                } else {
                    gateway_url.hide();
                }
            }).change();

            $( '#woocommerce_simplify_commerce_handling_fee_amount' ).before( '<span id="handling_fee_amount_label"></span>' );
            $( '#handling_fee_amount_label' ).css({ "width": "35px", "height": "31px", "line-height": "30px", "background-color": "#eaeaea", "text-align": "center", "position": "absolute", "left": "1px", "top": "1px", "border-radius": "3px 0 0 3px" }).parent().css( "position", "relative" );
            $( '#woocommerce_simplify_commerce_handling_fee_amount' ).css( "padding-left", "45px" );
            if( $( '#woocommerce_simplify_commerce_hf_amount_type' ).val() == 'fixed' ) {
                $( '#handling_fee_amount_label' ).html( wcSettings.currency.symbol );
            } else {
                $( '#handling_fee_amount_label' ).html( '%' );
            }

            $('#woocommerce_simplify_commerce_hf_amount_type').on('change', function () {
                if( $( this ).val() == 'fixed' ) {
                    $( '#handling_fee_amount_label' ).html( wcSettings.currency.symbol );
                } else {
                    $( '#handling_fee_amount_label' ).html( '%' );
                }
            }).change(); 
            $( '#woocommerce_simplify_commerce_handling_fee_amount' ).on( 'keypress', function(e) {
                var charCode = ( e.which ) ? e.which : e.keyCode;
                if ( charCode == 46 || charCode == 8 || charCode == 9 || charCode == 27 || charCode == 13 ||
                    ( charCode == 65 && ( e.ctrlKey === true || e.metaKey === true ) ) ||
                    ( charCode == 67 && ( e.ctrlKey === true || e.metaKey === true ) ) ||
                    ( charCode == 86 && ( e.ctrlKey === true || e.metaKey === true ) ) ||
                    ( charCode == 88 && ( e.ctrlKey === true || e.metaKey === true ) ) ||
                    // Allow: home, end, left, right
                    ( charCode >= 35 && charCode <= 39 ) ) {
                    return;
                }

                if ( ( charCode < 48 || charCode > 57 ) && charCode !== 46 ) {
                    e.preventDefault();
                }

                if ( charCode === 46 && $( this ).val().indexOf( '.' ) !== -1 ) {
                    e.preventDefault();
                }
            });
            $( '#woocommerce_simplify_commerce_handling_fee_amount' ).on( 'input', function() {
                var value = this.value;
                if ( !/^\d*\.?\d*$/.test( value ) ) {
                    this.value = value.substring( 0, value.length - 1 );
                }
            }); 
        }
    };
    wc_mastercard_admin.init();
});
