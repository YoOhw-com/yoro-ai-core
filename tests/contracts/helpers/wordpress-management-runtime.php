<?php
/** Exact ordinary WordPress facts layered on the existing real runner fixture. */
function yoro_wordpress_management_fixture_operation( $public, array $intent ) {
    $action = YoRo_Early_Access_WordPress_Management::wire_action( $public );
    $source = YoRo_Early_Access_WordPress_Management::prepare( $action, $intent );
    if ( is_wp_error( $source ) ) { throw new RuntimeException( $public . ': ' . $source->get_error_message() ); }
    $operation = yoro_native_write_fixture_envelope( $action, $source['params'] + [ 'expected_version' => $source['version'] ] );
    $operation['params'] = $source['params'];
    $facts =& $operation['extensions']['yoro.mcp_early_access_commerce_write'];
    $facts['expected_version'] = $source['version']; $facts['wordpress_management_version'] = 1;
    $facts['wordpress_intent'] = $intent; $facts['wordpress_required_scope'] = YoRo_Early_Access_WordPress_Management::definition()['actions'][$public]['scope'];
    $facts['params_hash'] = YoRo_Early_Access_Product_Read::params_hash( $source['params'] );
    return $operation;
}
