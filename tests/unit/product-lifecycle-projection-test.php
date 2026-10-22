<?php
/** Standalone raw legacy-note projection; no WordPress, database or network. */
define('ABSPATH', __DIR__);
function current_user_can(...$args) {return true;}
class WC_Product {
    public array $fields=['status'=>'publish','featured'=>false,'catalog_visibility'=>'visible','weight'=>'','length'=>'','width'=>'','height'=>'','shipping_class_id'=>0,'upsell_ids'=>[],'cross_sell_ids'=>[],'purchase_note'=>'','sold_individually'=>false];
    public function __construct(string $note){$this->fields['purchase_note']=$note;}
    public function __call($method,$args){$field=substr($method,4);return $this->fields[$field]??(in_array($field,['category_ids','tag_ids','gallery_image_ids','attributes','downloads'],true)?[]:'');}
    public function get_id(){return 7;}
    public function get_parent_id(){return 0;}
    public function is_type($types){return true;}
    public function is_downloadable(){return false;}
}
require dirname(__DIR__,2).'/includes/actions/class-yoro-product-lifecycle.php';
$checks=0;$assert=static function($ok,$why)use(&$checks){++$checks;if(!$ok){throw new RuntimeException($why);}};$projections=[];
foreach(['<b>Thanks for buying</b>',str_repeat('x',2001),str_repeat('é',1001),"Invalid UTF-8 \xc0"]as$note){
    $product=new WC_Product($note);$raw=YoRo_Product_Lifecycle::fingerprint($product);$projected=YoRo_Product_Lifecycle::details($product);
    $assert($projected['fields']['purchase_note']===''&&$projected['truncated_fields']===['purchase_note'],'Unsupported note projects a bounded unavailable value.');
    $assert(!in_array('purchase_note',$projected['editable_fields'],true)&&in_array('featured',$projected['editable_fields'],true),'Only the unsupported field is removed from editing.');
    $assert($product->fields['purchase_note']===$note&&YoRo_Product_Lifecycle::fingerprint($product)===$raw,'Raw value and source fingerprint survive projection unchanged.');
    $assert(array_diff_key($projected['fields'],['purchase_note'=>true])===array_diff_key($raw,['purchase_note'=>true]),'Unrelated product facts remain exact.');
    $projections[]=$projected;
}
$valid=new WC_Product("Exact plain\nnote");$projected=YoRo_Product_Lifecycle::details($valid);$assert($projected['fields']['purchase_note']==="Exact plain\nnote"&&in_array('purchase_note',$projected['editable_fields'],true)&&$projected['truncated_fields']===[],'Supported plain notes remain exact and editable.');
echo json_encode(['result'=>'passed','assertions'=>$checks,'projections'=>$projections],JSON_THROW_ON_ERROR),"\n";
