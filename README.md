# SAWE Download URL Manager

A WordPress plugin for managing and bulk-updating base URLs for WooCommerce downloadable product files.

## Features

- **Scan** all WooCommerce products and variations for downloadable files
- **Identify** all unique base directory URLs across your entire catalog
- **Simulate** a base URL change before applying — see every affected product and file in a preview table
- **Filter** simulation results by Changed / Unchanged by clicking the summary counts
- **Apply** changes with a confirmation dialog — updates both product meta and customer download permissions
- **Cancel** after simulating to reset and start over without making changes
- **Revert** the last applied change — stored persistently in `wp_options` and available until a new change is applied

## Use Case

Ideal for migrating downloadable files to a new directory (e.g., from a custom path to `woocommerce_uploads/`) without manually editing hundreds of products. Works with both products and variations, and updates `wp_woocommerce_downloadable_product_permissions` so existing customer download links continue to work.

## Installation

1. Download the latest release zip
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Navigate to **WooCommerce → Download URL Manager**

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+

## Changelog

### 1.1.0
- Added Cancel button to reset simulation without applying
- Added Revert Last Change — persists across page loads, clears after revert
- Simulation result counts are now clickable filters (Changed / Unchanged / All)

### 1.0.0
- Initial release
- Scan, simulate, and apply base URL remapping
- Updates product meta and customer download permissions
- HPOS compatible
