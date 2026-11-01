<?php
/** Disposable source-rendered fixture. Synthetic transport only, no API/provider/email/site DB. */
define( 'ABSPATH', __DIR__ );
function get_option( $name, $default = null ) { return [ 'enabled' => true, 'revision' => 1, 'content_ids' => [ 11 ], 'contact' => 'Contact: hello@example.test', 'protocol' => 2,
    'preferences' => [ 'features' => [ 'orders' => true, 'quote' => true, 'coupons' => true, 'payments' => true ], 'appearance' => [ 'name' => 'Northline support', 'welcome' => 'A little help, every step of the way.' ] ] ]; }
function rest_url( $path ) { return 'https://fixture.invalid/' . $path; }
function esc_url( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_attr( $value ) { return esc_html( $value ); }
function __( $value, $domain = '' ) { return $value; }
function esc_html__( $value, $domain ) { return esc_html( $value ); }
function esc_attr__( $value, $domain ) { return esc_html( $value ); }
require dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-customer-support.php';
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Chat Support v2 · source fixture</title><style>body{font:18px system-ui;margin:40px;background:#f2f1ed;color:#293f43}main{max-width:650px}h1{font-size:50px;line-height:1.05}p{line-height:1.7}';
readfile( dirname( __DIR__, 2 ) . '/assets/css/yoro-support.css' );
echo '</style><main><p>NORTHLINE / EVERYDAY ESSENTIALS</p><h1>Made for your<br>everyday adventures.</h1><p>Source-rendered disposable storefront fixture. All responses are synthetic DTOs. This is UI/protocol evidence, not an installed-store or AI-quality test.</p><a href="#content">Storefront keyboard target</a><p><button id="fixture-fail">Fail next transport</button> <button id="fixture-expire">Expire private access</button></p></main>';
YoRo_Customer_Support::widget();
?>
<script>
window.fixtureRequests=[]; window.fixtureFailure=false; window.fixtureVerified=false;
document.getElementById('fixture-fail').onclick=()=>{window.fixtureFailure=true;};
const later=()=>new Date(Date.now()+300000).toISOString();
const quoteData=()=>({items:[{name:'Trail daypack',quantity:1,total:'49.00'}],currency:'USD',subtotal:'49.00',discount:'0.00',shipping:'5.00',tax:'0.00',total:'54.00',quoted_at:new Date().toISOString(),notice:'Estimate. Checkout recalculates.',coupon:{state:'none',message:''},payment_state:'available',payment_methods:['Bank transfer'],shipping_rates:[{id:'flat_rate:1',label:'Standard',cost:'5.00',package:0,selected:true},{id:'flat_rate:2',label:'Express',cost:'10.00',package:0,selected:false}]});
const orderData=()=>({reference:'1024',date:'2026-11-01',status:'Processing',payment:'Paid',items:[{name:'Trail daypack',quantity:1,total:'49.00'}],currency:'USD',total:'54.00',refund:'0.00 refunded; not a return request.',tracking:[{carrier:'Stored carrier',number:'SYNTH123456',url:'',status:'Stored tracking; not carrier-verified.'}]});
window.fetch=async(url,options)=>{
 const data=JSON.parse(options.body); window.fixtureRequests.push({url,body:data}); await new Promise(r=>setTimeout(r,40));
 let body,ok=true;
 if(url.endsWith('session')) body={csrf:'synthetic-csrf',protocol:2,capabilities:{content:true,products:true,quote:true,coupons:true,payments:true,orders:true},verification:{verified:window.fixtureVerified,expires_at:later(),email_hint:'b***@example.test'}};
 else if(url.endsWith('verification')) {
  if(data.action==='send') body={challenge_id:'synthetic-challenge',email_hint:'b***@example.test',expires_at:later(),resend_seconds:1};
  else if(data.action==='confirm') { if(data.code!=='123456'){ok=false;body={code:'support_verification_invalid'};}else{window.fixtureVerified=true;body={verified:true,expires_at:later(),email_hint:'b***@example.test'};} }
  else if(data.action==='revoke'){window.fixtureVerified=false;body={verified:false};}
  else body={verified:window.fixtureVerified,expires_at:later(),email_hint:'b***@example.test'};
 }
 else if(url.endsWith('context')) {
  if(data.kind==='products') body={products:[{id:47,name:'Trail daypack',kind:'simple',price:'49.00 USD',options:[]}]};
  else if(data.kind==='orders'){if(!window.fixtureVerified){ok=false;body={code:'support_verification_required'};}else body={orders:[{reference:'synthetic-opaque-order',number:'1024',date:'2026-11-01',status:'Processing',total:'54.00',currency:'USD'}],page:1,has_next:false};}
  else {const order=data.kind==='order';body={context_token:order?'synthetic-order-context':'synthetic-quote-context',private:order,expires_at:later(),card:{kind:order?'order':data.kind,title:order?'Order #1024':'Current checkout estimate',data:order?orderData():quoteData()}};}
 }
 else if(window.fixtureFailure){window.fixtureFailure=false;ok=false;body={code:'support_unavailable'};}
 else body={turn_id:data.turn_id,status:'completed',private:data.context_token==='synthetic-order-context',answer:{text:data.context_token==='synthetic-order-context'?'Your recorded order is processing. The stored tracking does not establish live delivery.':'Our approved store guide has the information you need. This answer uses synthetic facts.',sources:[{title:'Approved store guide',url:window.location.origin+'/guide'}],products:data.context_token?[]:[{id:'product:47',title:'Trail daypack',url:window.location.origin+'/product/daypack',price:'49.00',currency:'USD',stock:'instock',sku:'TRAIL47',options:['Capacity: 12 L']}]}};
 return {ok,json:async()=>body};
};
document.getElementById('fixture-expire').onclick=()=>{window.fixtureVerified=false;document.dispatchEvent(new Event('visibilitychange'));};
</script><script><?php readfile( dirname( __DIR__, 2 ) . '/assets/js/yoro-support.js' ); ?></script></html>
