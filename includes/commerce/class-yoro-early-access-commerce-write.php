<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Single-target, versioned writes. Called only by the locked, fenced connector runner. */
final class YoRo_Early_Access_Commerce_Write {
    const ACTIONS = [ ...YoRo_Product_Authoring::WriteActions, ...YoRo_Fulfillment_Management::WriteActions, ...YoRo_Order_Edit::WriteActions, ...YoRo_Product_Reviews::WriteActions, ...YoRo_Product_Lifecycle::WriteActions, YoRo_Order_Batch::Action, ...YoRo_Order_Management::WriteActions, YoRo_Product_Batch::Action, ...YoRo_Coupon_Management::WriteActions, YoRo_Variable_Product::AttributeAction, YoRo_Variable_Product::CreateAction, 'products.update_details', 'products.update_stock', 'products.update_stock_status', 'orders.update_status', 'orders.add_note' ];

    public static function manifest() {
        return [ 'version' => 1, 'actions' => array_values(array_diff(self::ACTIONS, array_merge(['products.update_details',YoRo_Product_Batch::Action,YoRo_Order_Batch::Action],YoRo_Order_Management::WriteActions,YoRo_Product_Authoring::WriteActions,YoRo_Fulfillment_Management::WriteActions,YoRo_Order_Edit::WriteActions,YoRo_Product_Lifecycle::WriteActions,YoRo_Product_Reviews::WriteActions,YoRo_Variable_Product::WriteActions,YoRo_Coupon_Management::WriteActions))), 'max_targets' => 1, 'requires_source_read' => true,
            'requires_confirmation' => true, 'result_version' => 1 ];
    }

    public static function valid_params( $action, $params, $facts ) {
        if ( ! in_array( $action, self::ACTIONS, true ) || ! is_array( $params ) || ! is_array( $facts )
            || ! is_string( $facts['source_operation_id'] ?? null ) || ! preg_match( '/\Aenvelope_[a-f0-9]{64}\z/D', $facts['source_operation_id'] )
            || ! is_string( $facts['source_result_hash'] ?? null ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $facts['source_result_hash'] )
            || ! is_string( $facts['expected_version'] ?? null ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $facts['expected_version'] ) ) {
            return false;
        }
        if (in_array($action,YoRo_Product_Authoring::WriteActions,true)) {return YoRo_Product_Authoring::valid_params($action,$params)&&($params['expected_version']??null)===$facts['expected_version'];}
        if (in_array($action,YoRo_Fulfillment_Management::WriteActions,true)) {return YoRo_Fulfillment_Management::valid_params($action,$params)&&($params['expected_version']??null)===$facts['expected_version'];}
        if (in_array($action,YoRo_Order_Edit::WriteActions,true)) {return YoRo_Order_Edit::valid_params($action,$params)&&($params['expected_version']??null)===$facts['expected_version'];}
        if (in_array($action,YoRo_Product_Reviews::WriteActions,true)) { return YoRo_Product_Reviews::valid_params($action,$params) && ($params['expected_version'] ?? null) === $facts['expected_version']; }
        if (in_array($action,YoRo_Product_Lifecycle::WriteActions,true)) { return YoRo_Product_Lifecycle::valid_params($action,$params) && ($params['expected_version'] ?? null) === $facts['expected_version']; }
        if ($action === YoRo_Order_Batch::Action) { return YoRo_Order_Batch::valid_params($params) && hash_equals(YoRo_Order_Batch::version($params),$facts['expected_version']); }
        if (in_array($action,YoRo_Order_Management::WriteActions,true)) { return YoRo_Order_Management::valid_params($action,$params) && ($params['expected_version'] ?? null) === $facts['expected_version']; }
        if ($action === YoRo_Product_Batch::Action) {
            return YoRo_Product_Batch::valid_params($params) && hash_equals(YoRo_Product_Batch::version($params), $facts['expected_version']);
        }
        if (in_array($action,YoRo_Coupon_Management::WriteActions,true)) {
            $create = $action === 'coupons.create_managed';
            try { YoRo_Coupon_Management::changes($params['changes'] ?? null,$create); } catch(Throwable $error) { return false; }
            return count($params) === 3 && !array_diff(array_keys($params),['coupon_id','changes','expected_version'])
                && is_int($params['coupon_id'] ?? null) && ($create ? $params['coupon_id'] === 0 : $params['coupon_id'] > 0)
                && ($params['expected_version'] ?? null) === $facts['expected_version'];
        }
        if (in_array($action,YoRo_Variable_Product::WriteActions,true)) {
            try{YoRo_Variable_Product::changes($action,$params['changes']??null);}catch(Throwable $error){return false;}
            return count($params)===3 && !array_diff(array_keys($params),['product_id','changes','expected_version']) && is_int($params['product_id']??null)
                && $params['product_id']>0 && ($params['expected_version']??null)===$facts['expected_version'];
        }
        if ('products.update_details' === $action) {
            try { YoRo_Product_Edit::changes($params['changes'] ?? null); }
            catch (Throwable $error) { return false; }
            return 3 === count($params) && !array_diff(array_keys($params), ['product_id','changes','expected_version'])
                && is_int($params['product_id'] ?? null) && $params['product_id'] > 0 && ($params['expected_version'] ?? null) === $facts['expected_version'];
        }
        if ( str_starts_with( $action, 'products.' ) ) {
            $field = 'products.update_stock' === $action ? 'stock_quantity' : 'stock_status';
            return 3 === count( $params ) && ! array_diff( array_keys( $params ), [ 'product_id', $field, 'expected_version' ] )
                && is_int( $params['product_id'] ?? null ) && $params['product_id'] > 0
                && ( $params['expected_version'] ?? null ) === $facts['expected_version']
                && ( 'stock_quantity' === $field ? is_int( $params[$field] ?? null ) && $params[$field] >= 0 && $params[$field] <= 1000000000
                    : in_array( $params[$field] ?? null, [ 'instock', 'outofstock', 'onbackorder' ], true ) );
        }
        if ( ! is_array( $params['order_ids'] ?? null ) || array_keys( $params['order_ids'] ) !== [ 0 ]
            || ! is_int( $params['order_ids'][0] ) || $params['order_ids'][0] < 1 ) { return false; }
        if ( 'orders.add_note' === $action ) {
            $note = $params['note'] ?? null;
            return 2 === count( $params ) && ! array_diff( array_keys( $params ), [ 'order_ids', 'note' ] )
                && is_string( $note ) && '' !== trim( $note ) && mb_strlen( $note ) <= 2000 && 1 === preg_match( '//u', $note )
                && ! preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]/', $note ) && sanitize_textarea_field( $note ) === $note;
        }
        return 3 === count( $params ) && ! array_diff( array_keys( $params ), [ 'order_ids', 'status', 'expected_versions' ] )
            && in_array( $params['status'] ?? null, [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'failed' ], true )
            && ( $params['expected_versions'] ?? null ) === [ [ 'order_id' => $params['order_ids'][0], 'expected_version' => $facts['expected_version'] ] ];
    }

    public static function execute( array $operation, ?Closure $fence = null ) {
        $action = $operation['action'] ?? '';
        $params = $operation['params'] ?? [];
        $facts = $operation['extensions']['yoro.mcp_early_access_commerce_write'] ?? [];
        if ( ! self::valid_params( $action, $params, $facts ) || ! current_user_can( 'yoro_execute_actions' )
            || empty( $operation['authorization']['execution_permit'] ) ) {
            return new WP_Error( 'confirmed_commerce_denied', 'Confirmed change authority is unavailable.' );
        }
        if ($action === YoRo_Order_Batch::Action) { return YoRo_Order_Batch::execute_operation($operation,$fence); }
        if ($action === YoRo_Product_Batch::Action) { return YoRo_Product_Batch::execute_operation($operation,$fence); }
        if ( 'orders.add_note' === $action && ( $operation['user_content_slots'] ?? null ) !== [ [
            'field' => 'note', 'content' => $params['note'], 'content_scope' => 'internal_note', 'provenance' => 'user_goal',
        ] ] ) {
            return new WP_Error( 'confirmed_note_scope_invalid', 'The confirmed internal note content does not match its scoped slot.' );
        }
        if ('products.reply_review' === $action && ($operation['user_content_slots'] ?? null) !== [['field'=>'content','content'=>$params['changes']['content'],'content_scope'=>'public_review_reply','provenance'=>'user_goal']]) { return new WP_Error('confirmed_review_reply_scope_invalid','The public reply does not match its exact scoped content.'); }
        if (in_array($action,YoRo_Product_Authoring::WriteActions,true)) {
            $prepared=YoRo_Product_Authoring::prepare($params,$action);if(is_wp_error($prepared)){return $prepared;}$version=$facts['expected_version'];
        } elseif (in_array($action,YoRo_Fulfillment_Management::WriteActions,true)) {
            $prepared=YoRo_Fulfillment_Management::prepare($params,$action);if(is_wp_error($prepared)){return $prepared;}$version=$facts['expected_version'];
        } elseif (in_array($action,YoRo_Order_Edit::WriteActions,true)) {
            $prepared=YoRo_Order_Edit::prepare($params,$action);if(is_wp_error($prepared)){return $prepared;}$version=$facts['expected_version'];
        } elseif (in_array($action,YoRo_Product_Reviews::WriteActions,true)) {
            $prepared = YoRo_Product_Reviews::prepare($params,$action);
            if (is_wp_error($prepared)) { return $prepared; }
            $version = $facts['expected_version'];
        } elseif (in_array($action,YoRo_Product_Lifecycle::WriteActions,true)) {
            $prepared = YoRo_Product_Lifecycle::prepare($params,$action);
            if (is_wp_error($prepared)) { return $prepared; }
            $version = $facts['expected_version'];
        } elseif (in_array($action,YoRo_Order_Management::WriteActions,true)) {
            $prepared = YoRo_Order_Management::prepare($params,$action);
            if (is_wp_error($prepared)) { return $prepared; }
            $version = $facts['expected_version'];
        } elseif (in_array($action,YoRo_Coupon_Management::WriteActions,true)) {
            $prepared = YoRo_Coupon_Management::prepare($params,$action === 'coupons.create_managed');
            if (is_wp_error($prepared)) { return $prepared; }
            $version = $facts['expected_version']; // prepare freshly verifies the coupon/search-source version.
        } elseif ( str_starts_with( $action, 'products.' ) ) {
            $target = wc_get_product( $params['product_id'] );
            if ( ! $target || !in_array($target->get_status(), ['publish','draft'], true) || !current_user_can('edit_post',$target->get_id()) || ! in_array( $target->get_type(), in_array($action,YoRo_Variable_Product::WriteActions,true) ? ['variable'] : ('products.update_details' === $action ? [ 'simple', 'variable', 'variation' ] : [ 'simple', 'variation' ]), true )
                || ( 'products.update_stock' === $action && true !== $target->get_manage_stock( 'edit' ) )
                || ( 'products.update_stock_status' === $action && $target->get_manage_stock() ) ) {
                return new WP_Error( 'confirmed_product_unavailable', 'This product does not support the selected stock change.' );
            }
            $version = in_array($action,YoRo_Variable_Product::WriteActions,true) ? YoRo_Variable_Product::context($target)['version'] : YoRo_Commerce_Discovery_Service::product_version( $target );
        } else {
            $target = wc_get_order( $params['order_ids'][0] );
            if ( ! $target || 'shop_order' !== $target->get_type() ) { return new WP_Error( 'confirmed_order_unavailable', 'This order is unavailable.' ); }
            $version = YoRo_Order_Presenter::order_version( $target );
        }
        if ( ! hash_equals( $version, $facts['expected_version'] ) ) {
            return new WP_Error( 'confirmed_target_version_conflict', 'The target changed after its preview. Read it again before planning a new change.' );
        }
        if ( null !== $fence && ! $fence() ) {
            return new WP_Error( 'yoro_connector_runner_lock_lost', 'The connector runner lock was lost.' );
        }
        $execution = YoRo_Action_Executor::execute( $action, $params, true, false, [ 'connector_remote_agent' => true,
            'workflow_id' => $operation['workflow_id'] ?? '', 'operation_id' => $operation['operation_id'] ?? '' ] );
        if ( is_wp_error( $execution ) || ! is_array( $execution ) || empty( $execution['success'] ) ) {
            if(is_array($execution) && 'local_execution_outcome_unknown' === ($execution['error']['code'] ?? null)) {
                return new WP_Error('local_execution_outcome_unknown','Read the target again; do not repeat this change.');
            }
            return is_wp_error( $execution ) ? $execution : new WP_Error( 'confirmed_change_failed', 'The confirmed change did not complete.' );
        }
        $result = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $execution, $action );
        if ( 'orders.add_note' === $action ) {
            $ids = $execution['note_ids'] ?? [];
            if ( count( $ids ) !== 1 || ( $execution['processed'] ?? [] ) !== $params['order_ids'] ) {
                return new WP_Error( 'local_execution_outcome_unknown', 'The note outcome could not be verified. Read the order again.' );
            }
            $note = wc_get_order_note( $ids[0] );
            if ( ! $note || ! empty( $note->customer_note ) ) {
                return new WP_Error( 'local_execution_outcome_unknown', 'The internal note outcome could not be verified.' );
            }
            $result['data'] = [ 'order_ids' => $params['order_ids'], 'note_ids' => array_map( 'intval', $ids ),
                'processed' => $params['order_ids'], 'internal_only' => true ];
        }
        $message = 'orders.add_note' === $action ? 'Internal order note added.' : 'Confirmed WooCommerce change completed.';
        foreach ( [ 'message', 'summary', 'assistant_summary' ] as $field ) { $result[$field] = $message; }
        $result['undoable'] = false;
        $result['extensions'] = (object) [];
        return $result;
    }
}
