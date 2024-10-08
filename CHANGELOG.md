# Changelog
All notable changes to this project will be documented in this file.

## [2.4.3] - 2024-10-01
### Added 
- Implemented a feature to enable or disable debug logging. All communication data is encrypted and stored in a log file.
- Implemented the ability to process void transactions.
- Implemented the ability to add mandatory or optional extra fees on the checkout page.
### Fixed
- Fixed the compatibility issue with the WooCommerce Subscription plugin.

## [2.4.2] - 2024-05-06
### Added 
- Implemented a notification feature to alert the WordPress administrator whenever a new version is launched on GitHub.
- Enabled Gutenberg block compatibility for the WooCommerce checkout page.
- MGPS plugin compatibility with WooCommerce High-Performance Order Storage (HPOS).
- Customer details are populated in transaction details in Simplify Gateway.
### Improvements 
- Compatibility with WordPress 6.5 and WooCommerce 8.5.

## [2.4.1] - 2023-12-18
### Fixed
- Rectified the price rounding issue.

## [2.4.0] - 2023-06-30
### Improvements
- PHP 8.1 compatibility
- Compatibility with Wordpress 6.2 and WooCommerce 7.7
- Added a new setting that allows admin to input custom gateway URL.
### Changed
- Updated the SDK version to 1.7.0

## [2.3.2] - 2022-05-11
### Fixed
- “Amount mismatch” error is triggered when a user purchases a product on recent versions of WooCommerce (WordPress 5.9.3 / Woocommerce 6.4.1)

## [2.3.1] - 2021-12-01
### Fixed
- Fixed a "false" Transaction Description issue that happened for some types of transactions

## [2.3.0] - 2021-10-19
### Changed
- Add Embedded Payment Option
- Branding Update
- Add One Click Checkout implementation for hosted payment option

### Fixed
- It's impossible to Capture the payment if the Order is Virtual
- It's impossible to Refund the Payment if the plugin works in the Authorization Mode
- Fix the issue with text transformation while save the settings

## [2.2.0] - 2021-01-29

## [2.1.2] - 2021-01-14
### Fixed
- Fixing an issue where performing a partial refund through woocommerce will instead refund the full amount. 

## [2.1.1] 
### Fixed
- Patch to allow for non-latin (e.g. Greek or Arabic) characters in the checkout

## [2.1.0]
### Changed
- Added support for transaction modes, Payment and Authorization
- Added capture and reverse capability

## [2.0.0]
### Changed
- Standard integration has been removed
- Compatibility with Wordpress 5.2 and WooCommerce 3.6

## [1.4.3]
### Fixed
- Fix issue with amounts off by 1c.

## [1.4.2]
### Changed
- Adding Qatar to the supported countries list

## [1.4.1]
### Fixed
- Using hash_equals in return_handler helps prevent timing attacks.

## [1.4.0]
### Changed
- Display supported card types set by merchant

## [1.3.0]
### Changed
- Save hosted payment for subscription

## [1.2.0]
### Changed
- Adding "Australia" to the supported countries list
- Pass currency code in Hosted Payments args

## [1.1.0]
### Fixed
- Fix the card on file not working for subscription payment

## [1.0.0]
### Changed
- First version