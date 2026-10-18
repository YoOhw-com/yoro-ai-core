<?php

use Automattic\WooCommerce\Utilities\DiscountsUtil;
use Automattic\WooCommerce\Utilities\NumberUtil;

if (!defined('ABSPATH')) { exit; }

/** Coupon validation uses the quoted customer, never the operator's email address. */
if(class_exists('WC_Discounts')) {
final class YoRo_Order_Edit_Discounts extends WC_Discounts
{
    private array $recordedUsage;
    public function __construct(WC_Order $order,array $recordedUsage=[])
    {
        parent::__construct($order);$this->recordedUsage=$recordedUsage;
    }
    /** Replace one proven recorded source-order use; tentative holds and every other use remain counted. */
    protected function validate_coupon_usage_limit($coupon)
    {
        if(isset($this->recordedUsage[$coupon->get_code()])){$coupon=clone $coupon;$coupon->set_usage_count(max(0,$coupon->get_usage_count()-1));}
        return parent::validate_coupon_usage_limit($coupon);
    }
    protected function validate_coupon_user_usage_limit($coupon,$user_id=0)
    {
        $id=$user_id?:$this->get_object()->get_customer_id('edit');$credit=$this->recordedUsage[$coupon->get_code()]??null;
        if($id&&$credit&&$credit['customer_id']===$id&&$credit['recorded_identity_usage']>0&&$coupon->get_usage_limit_per_user()>0){$coupon=clone $coupon;$coupon->set_usage_limit_per_user($coupon->get_usage_limit_per_user()+1);}
        return parent::validate_coupon_user_usage_limit($coupon,$user_id);
    }
    protected function validate_coupon_allowed_emails($coupon)
    {
        $restrictions=$coupon->get_email_restrictions();
        if(!$restrictions) {return true;}
        $order=$this->get_object();$emails=[$order->get_billing_email('edit')];
        if($order->get_customer_id('edit')>0) {$customer=new WC_Customer($order->get_customer_id('edit'));$emails[]=$customer->get_email('edit');}
        $emails=array_values(array_unique(array_filter(array_map(static fn($value)=>strtolower(sanitize_email($value)),$emails))));
        if(!DiscountsUtil::is_coupon_emails_allowed($emails,$restrictions)) {throw new Exception('The coupon is unavailable for the selected customer.',WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED);}
        return true;
    }
}

}

/** Native arithmetic only. Every order/item here is freshly constructed with ID zero. */
final class YoRo_Order_Edit_Quote
{
    public static function decimal($value,int $places=6): string
    {
        $result=wc_format_decimal($value,$places);
        if(!is_string($result)||!preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D',$result)) {throw new RuntimeException('The quoted amount exceeds the supported bounds.');}
        return $result;
    }

    /** Six displayed signed components can differ from the native rounded sum by at most three minor units. */
    private static function rounding_adjustment(array $totals,int $places): string
    {
        $units=static function(string $amount)use($places): int {
            $parts=explode('.', $amount,2);
            return (int)($parts[0].str_pad($parts[1]??'', $places,'0'));
        };
        $sum=$units($totals['subtotal'])-$units($totals['discount_total']);
        foreach(['shipping_total','fee_total','cart_tax','shipping_tax']as$field){$sum+=$units($totals[$field]);}
        $delta=$units($totals['total'])-$sum;
        if(abs($delta)>3){throw new RuntimeException('Native rounding exceeds the supported quotation bound.');}
        $digits=str_pad((string)abs($delta),$places+1,'0',STR_PAD_LEFT);
        return ($delta<0?'-':'').($places?substr($digits,0,-$places).'.'.substr($digits,-$places):$digits);
    }

    private static function tax(array $rates,$amount,bool $inclusive=false): array
    {
        return wc_tax_enabled()&&$rates?WC_Tax::calc_tax($amount,$rates,$inclusive):[];
    }

    private static function tax_rows(array $total,array $subtotal=[]): array
    {
        $rows=[];$ids=array_unique(array_merge(array_keys($total),array_keys($subtotal)));sort($ids,SORT_NUMERIC);
        foreach($ids as$id) {$rows[]=['rate_id'=>(int)$id,'total'=>self::decimal($total[$id]??0),'subtotal'=>self::decimal($subtotal[$id]??0)];}
        return $rows;
    }

    private static function rounded_tax($value)
    {
        return get_option('woocommerce_tax_round_at_subtotal')==='yes'?$value:wc_round_tax_total($value);
    }

    private static function guest_credit(array $state,WC_Coupon $coupon): int
    {
        $credit=$state['coupon_usage_credit'][$coupon->get_code()]??null;
        return $credit&&$credit['customer_id']===0&&$credit['email']===strtolower($state['billing']['email'])&&$credit['recorded_identity_usage']>0?1:0;
    }

    /** State is normalized by YoRo_Order_Edit; this method never accepts or loads a live order. */
    public static function calculate(array $state): array
    {
        $order=new WC_Order();$order->set_currency($state['currency']);$order->set_customer_id($state['customer_id']);
        $order->set_address($state['billing'],'billing');$order->set_address($state['shipping'],'shipping');
        $order->set_prices_include_tax($state['prices_include_tax']);
        $location=$order->get_taxable_location();$native=[];$discountItems=[];$ratesBySlot=[];$subtotals=[];$subtotalTax=0;$taxClasses=[];
        foreach($state['line_items']as$index=>$line) {
            $product=wc_get_product($line['product_id']);if(!$product instanceof WC_Product) {throw new RuntimeException('The quoted product no longer exists.');}
            $item=new WC_Order_Item_Product();$item->set_product($product);$item->set_quantity($line['quantity']);
            $class=$product->get_tax_class('edit');$taxable=$product->get_tax_status('edit')==='taxable'&&!$state['vat_exempt'];
            $rates=$taxable&&wc_tax_enabled()?WC_Tax::find_rates(array_merge($location,['tax_class'=>$class])):[];
            $unit=$line['unit_price'];
            if($unit===null) {
                $unit=$product->get_price('edit');if($unit==='') {throw new RuntimeException('The quoted product has no price.');}
                $unit=wc_get_price_excluding_tax($product,['qty'=>1,'price'=>$unit,'order'=>$order]);
            }
            $subtotal=wc_format_decimal((float)$unit*$line['quantity']);$item->set_subtotal($subtotal);$item->set_total($subtotal);
            $taxes=self::tax($rates,$subtotal);$item->set_taxes(['total'=>$taxes,'subtotal'=>$taxes]);$order->add_item($item);
            $slot=$index+1;$native[$slot]=['item'=>$item,'reference_id'=>$line['item_id'],'product'=>$product];$ratesBySlot[$slot]=$rates;
            $discountItem=new stdClass();$discountItem->key=$slot;$discountItem->object=$item;$discountItem->product=$product;$discountItem->quantity=$line['quantity'];
            $discountItem->price=wc_add_number_precision((float)$subtotal+($state['prices_include_tax']?array_sum($taxes):0));$discountItems[$slot]=$discountItem;
            $subtotals[]=wc_add_number_precision((float)$item->get_subtotal('edit'));$subtotalTax+=array_sum($taxes);
            if($taxable) {$taxClasses[]=$class;}
        }
        $order->set_cart_tax($subtotalTax);$discounts=new YoRo_Order_Edit_Discounts($order,$state['coupon_usage_credit']??[]);$discounts->set_items($discountItems);$couponObjects=[];
        foreach($state['coupon_codes']as$code) {
            $coupon=new WC_Coupon($code);if(!$coupon->get_id()||$coupon->get_status('edit')!=='publish'||!in_array($coupon->get_discount_type('edit'),['percent','fixed_cart','fixed_product'],true)) {throw new RuntimeException('The selected coupon is unsupported.');}
            if($coupon->get_individual_use('edit')&&count($state['coupon_codes'])>1) {throw new RuntimeException('An individual-use coupon cannot be combined.');}
            if($state['customer_id']===0&&$coupon->get_usage_limit_per_user('edit')>0&&$coupon->get_data_store()->get_usage_by_email($coupon,$order->get_billing_email('edit'))-self::guest_credit($state,$coupon)>=$coupon->get_usage_limit_per_user('edit')) {throw new RuntimeException('The guest coupon usage limit has been reached.');}
            $applied=$discounts->apply_coupon($coupon);if(is_wp_error($applied)) {throw new RuntimeException('The selected coupon is invalid for this order.');}$couponObjects[$code]=$coupon;
        }
        $byItem=$discounts->get_discounts_by_item();$allDiscounts=$discounts->get_discounts();$byCoupon=$discounts->get_discounts_by_coupon();$lineRows=[];$cartTaxes=[];$rawSubtotalTax=0;$rawTotalTax=0;$lineTotals=[];
        foreach($native as$slot=>$entry) {
            $item=$entry['item'];$amount=$byItem[$slot]??0;$rates=$ratesBySlot[$slot];
            if($state['prices_include_tax']) {$amount-=array_sum(self::tax($rates,$amount,true));}
            $item->set_total(max(0,(float)$item->get_subtotal('edit')-$amount));$taxes=self::tax($rates,$item->get_total('edit'));$subtotalTaxes=self::tax($rates,$item->get_subtotal('edit'));
            $item->set_taxes(['total'=>$taxes,'subtotal'=>$subtotalTaxes]);
            foreach($taxes as$id=>$value) {$cartTaxes[$id]=($cartTaxes[$id]??0)+self::rounded_tax($value);}
            $rawSubtotalTax+=array_sum($subtotalTaxes);$rawTotalTax+=array_sum($taxes);$lineTotals[]=wc_add_number_precision((float)$item->get_total('edit'));
            $lineRows[]=['item_id'=>$entry['reference_id'],'product_id'=>$entry['product']->get_id(),'name'=>mb_strcut(wp_strip_all_tags($item->get_name('edit')),0,200,'UTF-8'),'quantity'=>(int)$item->get_quantity('edit'),
                'unit_price'=>self::decimal((float)$item->get_subtotal('edit')/$item->get_quantity('edit')),'subtotal'=>self::decimal($item->get_subtotal('edit')),'subtotal_tax'=>self::decimal($item->get_subtotal_tax('edit')),
                'total'=>self::decimal($item->get_total('edit')),'total_tax'=>self::decimal($item->get_total_tax('edit'))];
        }
        $couponRows=[];
        foreach($byCoupon as$code=>$amount) {
            $discountTax=0;foreach($allDiscounts[$code]as$slot=>$discount) {$tax=self::rounded_tax(array_sum(self::tax($ratesBySlot[$slot],$discount,$state['prices_include_tax'])));$discountTax+=$tax;if($state['prices_include_tax']) {$amount-=$tax;}}
            $couponRows[]=['code'=>$code,'discount'=>self::decimal($amount),'discount_tax'=>self::decimal($discountTax)];
        }
        $shippingClass=get_option('woocommerce_shipping_tax_class','');
        if($shippingClass==='inherit') {$classes=array_values(array_intersect(array_merge([''],WC_Tax::get_tax_class_slugs()),$taxClasses));$shippingClass=$classes[0]??($state['line_items']===[]?'':false);}
        $shippingRates=$shippingClass!==false&&!$state['vat_exempt']&&wc_tax_enabled()?WC_Tax::find_shipping_rates(array_merge($location,['tax_class'=>$shippingClass])):[];
        $shippingRows=[];$shippingTaxes=[];$shippingTotal=0;$shippingNative=[];
        foreach($state['shipping_lines']as$line) {
            $item=new WC_Order_Item_Shipping();$item->set_method_id($line['method_id']);$item->set_method_title($line['method_title']);$item->set_total($line['total']);
            $taxes=self::tax($shippingRates,$line['total']);$item->set_taxes(['total'=>$taxes]);$shippingNative[]=['item'=>$item,'reference_id'=>$line['item_id']];
            foreach($taxes as$id=>$value) {$shippingTaxes[$id]=($shippingTaxes[$id]??0)+self::rounded_tax($value);}
            $shippingTotal+=NumberUtil::round((float)$line['total'],wc_get_price_decimals());
            $shippingRows[]=[...$line,'total'=>self::decimal($item->get_total('edit')),'total_tax'=>self::decimal($item->get_total_tax('edit'))];
        }
        $feeRows=[];$feeTotal=0;$feeNative=[];
        foreach($state['fee_lines']as$line) {
            $item=new WC_Order_Item_Fee();$item->set_name($line['name']);$item->set_amount($line['total']);$item->set_total($line['total']);$item->set_tax_class($line['tax_class']);$item->set_tax_status($line['tax_status']);
            $rates=$line['tax_status']==='taxable'&&!$state['vat_exempt']&&wc_tax_enabled()?WC_Tax::find_rates(array_merge($location,['tax_class'=>$line['tax_class']])):[];
            $taxes=self::tax($rates,$line['total']);$item->set_taxes(['total'=>$taxes]);$feeNative[]=['item'=>$item,'reference_id'=>$line['item_id']];
            foreach($taxes as$id=>$value) {$cartTaxes[$id]=($cartTaxes[$id]??0)+self::rounded_tax($value);}$feeTotal+=(float)$line['total'];
            $feeRows[]=[...$line,'total'=>self::decimal($item->get_total('edit')),'total_tax'=>self::decimal($item->get_total_tax('edit'))];
        }
        $taxRows=[];$ids=array_unique(array_merge(array_keys($cartTaxes),array_keys($shippingTaxes)));sort($ids,SORT_NUMERIC);
        foreach($ids as$id) {$taxRows[]=['rate_id'=>(int)$id,'label'=>mb_strcut(wp_strip_all_tags(WC_Tax::get_rate_label($id)),0,100,'UTF-8'),'compound'=>WC_Tax::is_compound($id),'total'=>self::decimal($cartTaxes[$id]??0),'shipping_tax_total'=>self::decimal($shippingTaxes[$id]??0)];}
        $subtotal=wc_remove_number_precision(WC_Order::get_rounded_items_total($subtotals));$total=wc_remove_number_precision(WC_Order::get_rounded_items_total($lineTotals));$dp=wc_get_price_decimals();
        $totals=['subtotal'=>self::decimal($subtotal,$dp),'discount_total'=>self::decimal(NumberUtil::round($subtotal-$total,$dp),$dp),
            'discount_tax'=>self::decimal(wc_round_tax_total($rawSubtotalTax-$rawTotalTax),$dp),'shipping_total'=>self::decimal($shippingTotal,$dp),
            'shipping_tax'=>self::decimal(array_sum($shippingTaxes),$dp),'fee_total'=>self::decimal($feeTotal,$dp),'cart_tax'=>self::decimal(array_sum($cartTaxes),$dp),
            'total'=>self::decimal(NumberUtil::round($total+$feeTotal+$shippingTotal+array_sum($cartTaxes)+array_sum($shippingTaxes),$dp),$dp)];
        $totals['rounding_adjustment']=self::rounding_adjustment($totals,$dp);
        $quote=['currency'=>$state['currency'],'decimal_places'=>$dp,'prices_include_tax'=>$state['prices_include_tax'],'line_items'=>$lineRows,'coupon_lines'=>$couponRows,'shipping_lines'=>$shippingRows,'fee_lines'=>$feeRows,'tax_lines'=>$taxRows,'totals'=>$totals];
        return ['quote'=>$quote,'line_items'=>$native,'shipping_items'=>$shippingNative,'fee_items'=>$feeNative,'coupons'=>$couponObjects];
    }
}
