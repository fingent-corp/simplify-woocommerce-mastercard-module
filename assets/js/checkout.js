/*
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
const simplifySettings = window.wc.wcSettings.getSetting( 'simplify_commerce_data', {} ),
    simplifyLabel = window.wp.htmlEntities.decodeEntities( simplifySettings.title ) || window.wp.i18n.__( 'Mastercard Gateway - Simplify', 'simplify_commerce' ),
    simplifyContent = () => {
        return window.wp.htmlEntities.decodeEntities( simplifySettings.description || '' );
    },
    Mastercard_Simplify_Block_Gateway = {
        name: 'simplify_commerce',
        label: simplifyLabel,
        content: Object( window.wp.element.createElement )( simplifyContent, null ),
        edit: Object( window.wp.element.createElement )( simplifyContent, null ),
        canMakePayment: () => true,
        ariaLabel: simplifyLabel,
        supports: {
            features: simplifySettings.supports,
        },
    };
window.wc.wcBlocksRegistry.registerPaymentMethod( Mastercard_Simplify_Block_Gateway );