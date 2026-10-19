<?php
/** Prove guarded native test storage before other smoke fixtures touch Woo settings. */
if (!defined('ABSPATH') || !class_exists('WooCommerce') || str_ends_with((string)wp_parse_url(home_url(), PHP_URL_HOST), 'yorohq.com')) {
    throw new RuntimeException('Disposable WooCommerce required.');
}
require_once dirname(__DIR__).'/contracts/helpers/native-fulfillment-ci-storage.php';
yoro_native_fulfillment_ci_storage();
echo "Native fulfillment storage precondition: existing storage or exact isolated fresh initialization proven.\n";
