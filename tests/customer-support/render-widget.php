<?php
/** Disposable source-rendered fixture. No WordPress, API, provider, database or installation. */
define('ABSPATH', __DIR__);
function get_option($name, $default = null) { return ['enabled' => true, 'revision' => 1, 'content_ids' => [11], 'contact' => 'Contact: hello@example.test']; }
function rest_url($path) { return 'https://fixture.test/'.$path; }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES); }
function esc_html__($value, $domain) { return esc_html($value); }
function esc_attr__($value, $domain) { return esc_html($value); }
require dirname(__DIR__, 2).'/includes/customer-support/class-yoro-customer-support.php';
echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer Support source fixture</title><style>body{font:18px system-ui;margin:40px;background:#f6f7f6}';
readfile(dirname(__DIR__, 2).'/assets/css/yoro-support.css');
echo '</style><main><h1>Public storefront fixture</h1><p>Disposable UI evidence. Responses below are synthetic DTOs.</p><a href="#content">Storefront keyboard target</a></main>';
YoRo_Customer_Support::widget();
echo '<script>window.fixtureRequests=[];window.fetch=async function(url,options){const data=JSON.parse(options.body);window.fixtureRequests.push({url,body:data}); await new Promise(r=>setTimeout(r,80));return {ok:true,json:async()=>url.endsWith("session")?{csrf:"synthetic-csrf"}:{turn_id:data.turn_id,answer:{text:"Thông tin giao hàng: 3 ngày theo FAQ công khai. Public delivery takes three days.",sources:[{title:"Public Shipping FAQ",url:window.location.origin+"/faq"}]}}};};</script><script>';
readfile(dirname(__DIR__, 2).'/assets/js/yoro-support.js');
echo '</script></html>';
