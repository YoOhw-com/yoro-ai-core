<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Native totals input with no persistent cart/session/cookie hooks. */
class YoRo_Support_Quote_Cart extends WC_Cart {
    public $support_shipping;

    public function __construct() {
        $this->fees_api = new WC_Cart_Fees();
        $this->support_shipping = new WC_Shipping();
    }

    public function get_cart() {
        return array_filter( $this->get_cart_contents() );
    }

    public function calculate_totals() {
        $this->set_totals();
        $this->fees_api->remove_all_fees();
        if ( ! $this->is_empty() ) {
            new WC_Cart_Totals( $this );
        }
    }

    public function calculate_shipping() {
        $this->set_shipping_total( 0 );
        $this->set_shipping_tax( 0 );
        $this->set_shipping_taxes( [] );
        $this->shipping_methods = [];
        $this->has_calculated_shipping = false;
        $chosen = WC()->session->get( 'chosen_shipping_methods', [] );
        if ( ! $this->needs_shipping() || ! $this->show_shipping() ) {
            if ( $chosen ) { throw new UnexpectedValueException( 'Shipping package changed.' ); }
            return [];
        }
        $this->has_calculated_shipping = true;
        $packages = $this->support_shipping->calculate_shipping( $this->get_shipping_packages() );
        if ( array_diff_key( $chosen, $packages ) ) { throw new UnexpectedValueException( 'Shipping package changed.' ); }
        $counts = []; $previous = [];
        foreach ( $packages as $index => $package ) {
            if ( isset( $chosen[ $index ] ) && ! isset( $package['rates'][ $chosen[ $index ] ] ) ) { throw new UnexpectedValueException( 'Shipping selection changed.' ); }
            $counts[ $index ] = count( $package['rates'] ); $previous[ $index ] = array_keys( $package['rates'] );
        }
        WC()->session->set( 'shipping_method_counts', $counts );
        WC()->session->set( 'previous_shipping_methods', $previous );
        $this->shipping_methods = $this->get_chosen_shipping_methods( $packages );
        $taxes = [];
        foreach ( $this->shipping_methods as $rate ) {
            foreach ( $rate->get_taxes() as $id => $amount ) { $taxes[ $id ] = ( $taxes[ $id ] ?? 0 ) + $amount; }
        }
        $this->set_shipping_total( array_sum( wp_list_pluck( $this->shipping_methods, 'cost' ) ) );
        $this->set_shipping_tax( array_sum( $taxes ) );
        $this->set_shipping_taxes( $taxes );
        return $this->shipping_methods;
    }
}
