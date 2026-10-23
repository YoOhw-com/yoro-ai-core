<?php

if (!defined('ABSPATH')) { exit; }

/** Ephemeral authored composition and synchronous native mail through the journaled lane. */
final class YoRo_Order_Email
{
    public const ReadActions = ['orders.get_email_context'];
    public const WriteActions = ['orders.send_authored_email','orders.send_native_email','orders.add_customer_note'];
    public const Actions = self::ReadActions;
    public const ManifestExtension = 'yoro.early_access_order_email';
    public static function manifest(): array
    {
        return ['version' => 3, 'context_profiles' => ['email_facts_v1','customer_service_v1'], 'actions' => [...self::Actions, ...self::WriteActions], 'max_targets' => 1,
            'requires_source_read' => true, 'requires_confirmation' => true, 'result_version' => 1, 'recipient' => 'current_order_billing', 'composer' => 'yoro_bounded_email'];
    }

    public static function object(array $properties, ?array $required = null): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required ?? array_keys($properties), 'additionalProperties' => false];
    }

    public static function properties(string $action): array
    {
        $authored = ['subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000], 'body_format' => ['type' => 'string', 'enum' => ['plain_text', 'safe_html']]];

        return match ($action) {
            self::Actions[0] => ['history_access'=>['type'=>'string','enum'=>['authorized','unavailable']], 'policy_content_ids'=>['type'=>'array','maxItems'=>3,'items'=>['type'=>'integer','minimum'=>1]], 'policy_access'=>['type'=>'string','enum'=>['authorized','unavailable_authority','not_selected']], 'context_profile' => ['type' => 'string', 'enum' => ['email_facts_v1','customer_service_v1']], ...$authored, 'template' => ['type' => 'string', 'enum' => ['order_details', 'processing', 'completed', 'customer_note']], 'note' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000]],
            'orders.send_authored_email' => $authored,
            'orders.send_native_email' => ['template' => ['type' => 'string', 'enum' => ['order_details', 'processing', 'completed']]],
            'orders.add_customer_note' => ['note' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000]],
            default => self::invalid(),
        };
    }

    public static function changes(mixed $changes, string $action): array
    {
        $properties = self::properties($action);
        if (! is_array($changes) || ($changes !== [] && array_is_list($changes)) || array_diff(array_keys($changes), array_keys($properties))
            || ($action !== self::Actions[0] && array_diff(array_keys($properties), ['body_format'], array_keys($changes)))) {
            self::invalid();
        }
        foreach ($changes as $field => $value) {
            if ($field === 'policy_content_ids') {
                if (!is_array($value)||!array_is_list($value)||count($value)>3||count(array_unique($value,SORT_REGULAR))!==count($value)||count(array_filter($value,static fn($id)=>is_int($id)&&$id>0))!==count($value)) {self::invalid();}
                continue;
            }
            $property = $properties[$field];
            if (! is_string($value) || trim($value) === '' || strlen($value) > ($property['maxLength'] ?? 200)
                || preg_match('//u', $value) !== 1 || preg_match($field === 'body' && ($changes['body_format'] ?? 'plain_text') === 'safe_html' ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : (in_array($field, ['body', 'note'], true) ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]/' : '/[\x00-\x1f\x7f<>]/'), $value)
                || (isset($property['enum']) && ! in_array($value, $property['enum'], true))
                || (isset($property['pattern']) && preg_match('~\A'.substr($property['pattern'], 1, -1).'\z~D', $value) !== 1)) {
                self::invalid();
            }
        }
        if (($changes['body_format'] ?? 'plain_text') === 'safe_html' && isset($changes['body'])) {
            self::safeHtml($changes['body']);
        }
        if ($action === self::Actions[0] && (isset($changes['subject']) !== isset($changes['body']) || (isset($changes['body_format']) && !isset($changes['body'])))) {
            self::invalid();
        }
        if ((isset($changes['policy_content_ids'])||isset($changes['policy_access'])||isset($changes['history_access']))&&($changes['context_profile']??null)!=='customer_service_v1') {self::invalid();}
        if (($changes['context_profile']??null)==='customer_service_v1' && (!isset($changes['policy_access'],$changes['policy_content_ids'],$changes['history_access']) || (($changes['policy_access']==='authorized')!==($changes['policy_content_ids']!==[])))) {self::invalid();}
        if (isset($changes['context_profile']) && (isset($changes['template']) || isset($changes['note']))) { self::invalid(); }
        ksort($changes, SORT_STRING);

        return $changes;
    }

    public static function result_schema(string $action): array
    {
        $object = self::object(...);
        $id = ['type' => 'integer', 'minimum' => 1];
        $text = ['type' => 'string', 'maxLength' => 200];
        $version = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
        $draftId = ['type' => 'string', 'pattern' => '^draft_[a-f0-9-]{36}$'];
        $date = ['type' => 'string', 'format' => 'date-time'];
        if (in_array($action, self::WriteActions, true)) {
            return $object(['order_id' => $id, 'record_id' => ['type' => 'string', 'maxLength' => 80], 'outcome' => ['type' => 'string', 'enum' => ['mailer_accepted', 'note_created']],
                'content_hash' => $version, 'current_version' => $version, 'inbox_delivery_verified' => ['type' => 'boolean', 'enum' => [false]]]);
        }

        $legacy = $object(['order_id' => $id, 'order_number' => $text, 'recipient' => ['type' => 'string', 'maxLength' => 254], 'version' => $version,
            'template' => ['type' => 'string', 'enum' => ['order_details', 'processing', 'completed', 'customer_note']], 'native_eligible' => ['type' => 'boolean'],
            'subject' => $text, 'html_body' => ['type' => 'string', 'maxLength' => 24000],
            'composer' => $object(['subject' => $text, 'body' => ['type' => 'string', 'maxLength' => 12000], 'body_format' => ['type' => 'string', 'enum' => ['plain_text', 'safe_html']]]),
            'drafts' => ['type' => 'array', 'maxItems' => 20, 'items' => $object(['id' => $draftId, 'subject' => $text, 'version' => $version])],
            'draft' => ['type' => ['object', 'null'], 'properties' => ['id' => $draftId, 'subject' => $text, 'body' => ['type' => 'string', 'maxLength' => 12000], 'version' => $version], 'required' => ['id', 'subject', 'body', 'version'], 'additionalProperties' => false],
            'history' => ['type' => 'array', 'maxItems' => 20, 'items' => $object(['id' => ['type' => 'string', 'maxLength' => 80], 'subject' => $text,
                'outcome' => ['type' => 'string', 'enum' => ['attempting', 'mailer_accepted', 'failed', 'unknown']], 'content_hash' => $version, 'occurred_at' => $date])],
            'settings' => $object(['from_name' => $text, 'from_address' => ['type' => 'string', 'maxLength' => 254], 'enabled' => ['type' => 'boolean'],
                'content_type' => ['type' => 'string', 'enum' => ['text/html', 'text/plain', 'multipart/alternative']], 'configured_not_deliverability' => ['type' => 'boolean', 'enum' => [true]]])]);
        $profile = $object([...$legacy['properties'], 'context_profile' => ['type'=>'string','enum'=>['email_facts_v1','customer_service_v1']], 'facts' => self::facts_schema(), 'service_context'=>self::service_schema()]);
        // Existing Commerce schema keywords describe both strict shapes; the negotiated
        // params/result correspondence is additionally enforced by the consuming API.
        return [...$profile, 'required'=>$legacy['required']];
    }

    private static function invalid(): never { throw new InvalidArgumentException('Use exact authored content and the current order billing recipient.'); }

    public static function input_properties(string $action): array
    {
        $id=['type'=>'integer','minimum'=>1];
        if(in_array($action,self::ReadActions,true)) { return ['order_id'=>$id,...self::properties($action)]; }
        return ['order_id'=>$id,'changes'=>self::object(self::properties($action),array_values(array_diff(array_keys(self::properties($action)),['body_format']))),'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
            'recipient'=>['type'=>'string','maxLength'=>254],...(in_array($action,['orders.send_native_email','orders.add_customer_note'],true)?['subject'=>['type'=>'string','maxLength'=>200],'html_body'=>['type'=>'string','maxLength'=>24000]]:[])];
    }

    public static function register(): void
    {
        foreach([...self::ReadActions,...self::WriteActions] as $action) {
            $write=in_array($action,self::WriteActions,true);$properties=self::input_properties($action);$input=[];
            foreach($properties as $field=>$property){$input[$field]=$property['type'];}
            YoRo_Action_Registry::register($action,['label'=>str_replace('_',' ',substr($action,7)),
                'description'=>'One current order billing recipient and exact version-bound content. Mailer acceptance does not prove inbox delivery.',
                'category'=>'orders','permission'=>$write?'yoro_execute_actions':'yoro_view_store_data',
                'callback'=>$write?static fn($params)=>self::write((array)$params,$action):[self::class,'read'],
                'preview_callback'=>$write?static fn($params)=>self::preview((array)$params,$action):[self::class,'read'],
                'risk_level'=>$write?'medium':'low','write_action'=>$write,'requires_confirmation'=>$write,
                'effect'=>$write?(str_contains($action,'draft')?'mutate':'communicate'):'read','external_side_effect'=>$write&&!str_contains($action,'draft'),
                'reversibility'=>'none','target_type'=>'order','max_items'=>1,'input_schema'=>$input,'canonical_input_schema'=>$properties,
                'required_params'=>$write?array_keys($properties):['order_id'],'concurrency_strategy'=>$write?'optimistic_version':'','capabilities'=>['orders',$write?'write':'read']]);
        }
    }

    public static function valid_params(string $action,$params,$facts=[]): bool
    {
        if(!is_array($params)||!is_int($params['order_id']??null)||$params['order_id']<1){return false;}
        if(in_array($action,self::ReadActions,true)){
            if(array_diff(array_keys($params),array_keys(self::input_properties($action)))){return false;}
            try {self::changes(array_diff_key($params,['order_id'=>true]),$action);return true;}catch(Throwable $error){return false;}
        }
        if(!in_array($action,self::WriteActions,true)||array_diff(array_keys($params),array_keys(self::input_properties($action)))||count($params)!==count(self::input_properties($action))
            ||!is_string($params['expected_version']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$params['expected_version'])||!is_email($params['recipient']??'')){return false;}
        try {self::changes($params['changes']??null,$action);return true;}catch(Throwable $error){return false;}
    }

    private static function order(int $id,bool $write=false): WC_Order
    {
        $order=wc_get_order($id);
        if(!$order instanceof WC_Order||$order->get_type()!=='shop_order'||!current_user_can('manage_woocommerce')||!current_user_can('edit_shop_order',$id)
            ||($write&&!current_user_can('yoro_execute_actions'))||!is_email($order->get_billing_email('edit'))){self::invalid();}
        return $order;
    }

    private static function history(WC_Order $order): array
    {
        $rows=$order->get_meta('_yoro_observed_order_mail',true);
        return array_slice(is_array($rows)?array_values($rows):[],0,20);
    }

    private static function native(WC_Order $order,string $template,string $note=''): array
    {
        $classes=['customer_note'=>'WC_Email_Customer_Note','order_details'=>'WC_Email_Customer_Invoice','processing'=>'WC_Email_Customer_Processing_Order','completed'=>'WC_Email_Customer_Completed_Order'];
        $emails=WC()->mailer()->get_emails();$source=$emails[$classes[$template]??'']??null;
        $eligible=$source instanceof WC_Email&&(in_array($template,['order_details','customer_note'],true)||$order->get_status('edit')===$template)
            &&($template==='order_details'||$source->is_enabled())&&!apply_filters('woocommerce_defer_transactional_emails',false)
            &&apply_filters('woocommerce_mail_callback','wp_mail',$source)==='wp_mail'&&false===has_filter('pre_wp_mail');
        if(!$source instanceof WC_Email){self::invalid();}
        $email=clone $source;$email->object=$order;if($template==='customer_note'){$email->customer_note=$note;}$email->recipient=$order->get_billing_email('edit');
        $email->placeholders['{order_date}']=wc_format_datetime($order->get_date_created());
        $email->placeholders['{order_number}']=$order->get_order_number();
        $email->setup_locale();
        try {
            $subject=wp_specialchars_decode($email->get_subject());$raw=$email->get_content();
            $html=apply_filters('woocommerce_mail_content',$email->style_inline($raw));$headers=$email->get_headers();
            $eligible=$eligible&&$email->get_recipient()===$order->get_billing_email('edit')&&!preg_match('/(?:^|[\r\n])(?:Cc|Bcc):/i',$headers)
                &&$email->get_attachments()===[]&&$email->get_content_type()==='text/html'&&strlen($html)<=24000&&strlen($subject)<=200;
        } finally {$email->restore_locale();}
        return ['email'=>$email,'subject'=>$subject,'raw'=>$raw,'html_body'=>$html,'headers'=>$headers,'eligible'=>$eligible];
    }

    /** Build the exact authored content inside the site's Woo header/footer and inline styles. */
    private static function authored(WC_Order $order, string $subject, string $body, string $format = 'plain_text'): array
    {
        // Woo loads WC_Email through its official mailer initializer on cold requests.
        if (!WC()->mailer() || !class_exists('WC_Email', false)) { self::invalid(); }
        $email = new WC_Email(); $email->object = $order; $email->recipient = $order->get_billing_email('edit'); $email->email_type = 'html';
        $email->setup_locale(); $level = ob_get_level();
        try {
            $content = $format === 'safe_html' ? self::safeHtml($body) : '<div style="white-space:pre-wrap">'.htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div>';
            ob_start();
            do_action('woocommerce_email_header', htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $email);
            echo '<div id="yoro-authored-email-body">'.$content.'</div>';
            do_action('woocommerce_email_footer', $email);
            $raw = ob_get_clean();
            $html = apply_filters('woocommerce_mail_content', $email->style_inline($raw)); $headers = $email->get_headers();
            self::verify_authored_content($html, $body, $format);
            $html = self::checklist_entities($html);
            if (strlen($html) > 24000 || !str_contains($raw, $content) || $email->get_content_type() !== 'text/html'
                || $email->get_recipient() !== $order->get_billing_email('edit') || preg_match('/(?:^|[\r\n])(?:Cc|Bcc):/i', $headers)
                || $email->get_attachments() !== [] || apply_filters('woocommerce_defer_transactional_emails', false)
                || apply_filters('woocommerce_mail_callback', 'wp_mail', $email) !== 'wp_mail' || false !== has_filter('pre_wp_mail')) { self::invalid(); }
            return ['email'=>$email, 'raw'=>$raw, 'html_body'=>$html, 'headers'=>$headers];
        } finally {
            while (ob_get_level() > $level) { ob_end_clean(); }
            $email->restore_locale();
        }
    }

    /** Keep checklist glyphs as text when WordPress's mail filter staticizes emoji. */
    private static function checklist_entities(string $html): string
    {
        return str_replace(['☐','☑'], ['&#9744;','&#9745;'], $html);
    }

    /** Site filters may style the content, but cannot replace the approved authored text or formatting. */
    private static function verify_authored_content(string $html, string $body, string $format): void
    {
        $document=new DOMDocument('1.0','UTF-8'); $previous=libxml_use_internal_errors(true);
        try {$ok=$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);} finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
        $matches=(new DOMXPath($document))->query('//*[@id="yoro-authored-email-body"]');
        if(!$ok||$matches->length!==1){self::invalid();}
        $container=$matches->item(0);
        if($format==='plain_text') {
            if($container->childNodes->length!==1||!$container->firstChild instanceof DOMElement||$container->firstChild->tagName!=='div'||str_replace(["\r\n","\r"],"\n",$container->textContent)!==str_replace(["\r\n","\r"],"\n",$body)){self::invalid();}
            foreach($container->firstChild->childNodes as $node){if(!$node instanceof DOMText){self::invalid();}}
            return;
        }
        $expected=new DOMDocument('1.0','UTF-8'); $previous=libxml_use_internal_errors(true);
        try {$expected->loadHTML('<?xml encoding="UTF-8"><html><body>'.$body.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);} finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
        $tree=function($node)use(&$tree):array{
            if($node instanceof DOMText){return ['text',$node->data];}
            if(!$node instanceof DOMElement){self::invalid();}
            $attributes=[];foreach($node->attributes as $attribute){if($attribute->name!=='style'){$attributes[$attribute->name]=$attribute->value;}}ksort($attributes);
            $children=[];foreach($node->childNodes as $child){$children[]=$tree($child);}
            return [$node->tagName,$attributes,$children];
        };
        $actualChildren=[];foreach($container->childNodes as $node){$actualChildren[]=$tree($node);}
        $expectedChildren=[];foreach($expected->getElementsByTagName('body')->item(0)->childNodes as $node){$expectedChildren[]=$tree($node);}
        if($actualChildren!==$expectedChildren){self::invalid();}
    }


    /** Strict opt-in projection; prose is untrusted data, never instructions. */
    public static function facts_schema(): array
    {
        $o=self::object(...); $text=['type'=>'string','maxLength'=>200]; $nullableText=['type'=>['string','null'],'maxLength'=>200];
        $money=['type'=>['string','null'],'maxLength'=>80,'pattern'=>'^-?[0-9]+(?:\.[0-9]+)?$'];
        $date=$o(['value'=>['type'=>['string','null'],'format'=>'date-time'],'timezone'=>$text,'precision'=>['type'=>'string','enum'=>['second','unknown']]]);
        $unknown=['type'=>'null']; $count=['type'=>'integer','minimum'=>0];
        return $o(['profile'=>['type'=>'string','enum'=>['email_facts_v1']],'source'=>['type'=>'string','enum'=>['woocommerce_order']],
            'observed_at'=>['type'=>'string','format'=>'date-time'],'text_trust'=>['type'=>'string','enum'=>['untrusted_data']],
            'order'=>$o(['id'=>['type'=>'integer','minimum'=>1],'status'=>$text,'status_label'=>$text,'currency'=>['type'=>'string','maxLength'=>10],
                'created_at'=>$date,'paid_at'=>$date,'completed_at'=>$date,
                'payment'=>$o(['method'=>$nullableText,'method_title'=>$nullableText,'paid_recorded'=>['type'=>'boolean'],'gateway_settlement_verified'=>['type'=>'boolean','enum'=>[false]]]),
                'totals'=>$o(array_fill_keys(['subtotal','discount','shipping','tax','total','refunded'],$money))]),
            'customer'=>$o(['scope'=>['type'=>'string','enum'=>['this_order_billing_contact']],'name'=>$text,'email'=>['type'=>'string','maxLength'=>254],
                'customer_id'=>['type'=>['integer','null'],'minimum'=>1],'type'=>['type'=>'string','enum'=>['guest','registered']],
                'email_syntax_valid'=>['type'=>'boolean','enum'=>[true]],'identity_verified'=>['type'=>'boolean','enum'=>[false]],'inbox_verified'=>['type'=>'boolean','enum'=>[false]]]),
            'items'=>['type'=>'array','maxItems'=>20,'items'=>$o(['item_id'=>['type'=>'integer','minimum'=>1],'name'=>$text,'quantity'=>$money,
                'subtotal'=>$money,'total'=>$money,'tax'=>$money,'product_id'=>['type'=>['integer','null'],'minimum'=>1],'variation_id'=>['type'=>['integer','null'],'minimum'=>1],
                'variation_attributes'=>['type'=>'array','maxItems'=>10,'items'=>['type'=>'string','maxLength'=>400]],
                'catalog_availability'=>['type'=>'string','enum'=>['public_current','unavailable']], 'catalog_name'=>$nullableText,'catalog_sku'=>['type'=>['string','null'],'maxLength'=>128],
                'catalog_type'=>$nullableText,'catalog_virtual'=>['type'=>['boolean','null']],'catalog_downloadable'=>['type'=>['boolean','null']]])],
            'coverage'=>$o(['items_returned'=>$count,'items_total'=>$count,'items_truncated'=>['type'=>'boolean'],'variation_attributes_truncated'=>['type'=>'boolean'],
                'refunds_loaded'=>$count,'refunds_total'=>$count,'refunds_truncated'=>['type'=>'boolean'],
                'customer_history'=>['type'=>'string','enum'=>['not_loaded']],'private_notes'=>['type'=>'string','enum'=>['not_loaded']],
                'catalog_scope'=>['type'=>'string','enum'=>['public_current_for_returned_items']],'historical_product_classification'=>['type'=>'string','enum'=>['unknown']]]),
            'tracking'=>['type'=>'array','maxItems'=>20,'items'=>$o(['source'=>['type'=>'string','enum'=>['native_fulfillment','order_tracking_number']], 'carrier'=>$nullableText,'tracking_number'=>$text])],
            'fulfillment'=>$o(['source'=>['type'=>'string','enum'=>['stored_order_tracking']], 'tracking_truncated'=>['type'=>'boolean'],'coverage'=>['type'=>'string','enum'=>['supported_storage_only','unavailable']],
                'carrier_verified'=>['type'=>'boolean','enum'=>[false]],'dispatched_at'=>$unknown,'delivered_at'=>$unknown,'shipping_required'=>$unknown]),
            'store'=>$o(['name'=>$text,'locale'=>['type'=>'string','maxLength'=>40],'timezone'=>$text]),
            'policy'=>$o(['wording'=>$unknown,'estimated_delivery_at'=>$unknown])]);
    }

    public static function service_schema(): array
    {
        $o=self::object(...);$id=['type'=>'integer','minimum'=>1];$text=['type'=>'string','maxLength'=>200];
        $date=['type'=>['string','null'],'format'=>'date-time'];$version=['type'=>'string','pattern'=>'^[a-f0-9]{64}$'];
        return $o(['profile'=>['type'=>'string','enum'=>['customer_service_v1']],'source_version'=>$version,'context_digest'=>$version,'observed_at'=>['type'=>'string','format'=>'date-time'],
            'text_trust'=>['type'=>'string','enum'=>['untrusted_data']],
            'related_orders'=>['type'=>'array','maxItems'=>5,'items'=>$o(['order_id'=>$id,'status'=>$text,'currency'=>['type'=>'string','maxLength'=>10],
                'total'=>['type'=>'string','maxLength'=>80],'created_at'=>$date])],
            'history_coverage'=>$o(['availability'=>['type'=>'string','enum'=>['available','unavailable']],
                'match_basis'=>['type'=>'string','enum'=>['customer_id','email','unavailable']],'complete'=>['type'=>'boolean'],
                'identity_verified'=>['type'=>'boolean','enum'=>[false]],'lifetime_total'=>['type'=>'null']]),
            'refunds'=>['type'=>'array','maxItems'=>5,'items'=>$o(['refund_id'=>$id,'amount'=>['type'=>['string','null'],'maxLength'=>80],'created_at'=>$date])],
            'refund_coverage'=>$o(['returned'=>['type'=>'integer','minimum'=>0,'maximum'=>5],'total'=>['type'=>'integer','minimum'=>0],
                'truncated'=>['type'=>'boolean'],'settlement_verified'=>['type'=>'boolean','enum'=>[false]]]),
            'communication_coverage'=>$o(['source'=>['type'=>'string','enum'=>['current_order_yoro_history']],
                'returned'=>['type'=>'integer','minimum'=>0,'maximum'=>20],'limit'=>['type'=>'integer','enum'=>[20]],
                'complete'=>['type'=>'boolean','enum'=>[false]],'inbox_verified'=>['type'=>'boolean','enum'=>[false]]]),
            'policies'=>['type'=>'array','maxItems'=>3,'items'=>$o(['content_id'=>$id,'title'=>$text,'version'=>$version,
                'modified_at'=>$date,'excerpt'=>['type'=>'string','maxLength'=>2000],'truncated'=>['type'=>'boolean']])],
            'policy_coverage'=>$o(['availability'=>['type'=>'string','enum'=>['available','not_selected','unavailable_authority','unavailable']],
                'complete'=>['type'=>'boolean'],'wording_inferred'=>['type'=>'boolean','enum'=>[false]]])]);
    }

    private static function service_context(WC_Order $order,array $selectors,string $version,array $history): array
    {
        $orders=[];$coverage=['availability'=>'unavailable','match_basis'=>'unavailable','complete'=>false,'identity_verified'=>false,'lifetime_total'=>null];
        if ($selectors['history_access']==='authorized' && class_exists('YoRo_Customer_History_Report')) {
            $customer=(int)$order->get_customer_id('edit');
            $selector=$customer>0?['customer_id'=>$customer]:['guest_order_id'=>$order->get_id(),'match'=>'email'];
            $read=YoRo_Customer_History_Report::execute_read(['action'=>'customers.get_order_history','params'=>['selector'=>$selector,'limit'=>5]]);
            if (!is_wp_error($read)&&($read['success']??false)===true&&isset($read['data']['coverage'],$read['data']['orders'])) {
                foreach (array_slice($read['data']['orders'],0,5) as $row) {
                    $orders[]=['order_id'=>$row['id'],'status'=>self::fact_text($row['status']),'currency'=>self::fact_text($row['currency'],10),
                        'total'=>$row['total'],'created_at'=>$row['created_at']??null];
                }
                $coverage=['availability'=>'available','match_basis'=>$read['data']['coverage']['match_basis'],
                    'complete'=>$read['data']['coverage']['complete']===true,'identity_verified'=>false,'lifetime_total'=>null];
            }
        }
        $refunds=$order->get_refunds();$recorded=[];
        foreach (array_slice($refunds,0,5) as $refund) {
            $created=$refund->get_date_created('edit');
            $recorded[]=['refund_id'=>$refund->get_id(),'amount'=>self::fact_decimal($refund->get_amount('edit')),
                'created_at'=>$created instanceof DateTimeInterface?$created->format('c'):null];
        }
        $policies=[];$access=$selectors['policy_access'];$complete=$access==='authorized';
        if ($access==='authorized') {
            foreach ($selectors['policy_content_ids'] as $id) {
                $excerpt=class_exists('YoRo_Early_Access_WordPress_Read')?YoRo_Early_Access_WordPress_Read::policy_excerpt($id):null;
                if ($excerpt===null) {$complete=false;continue;}
                $policies[]=$excerpt;
            }
        }
        $context=['profile'=>'customer_service_v1','source_version'=>$version,'observed_at'=>gmdate('c'),'text_trust'=>'untrusted_data',
            'related_orders'=>$orders,'history_coverage'=>$coverage,'refunds'=>$recorded,
            'refund_coverage'=>['returned'=>count($recorded),'total'=>count($refunds),'truncated'=>count($refunds)>5,'settlement_verified'=>false],
            'communication_coverage'=>['source'=>'current_order_yoro_history','returned'=>count($history),'limit'=>20,'complete'=>false,'inbox_verified'=>false],
            'policies'=>$policies,'policy_coverage'=>['availability'=>$access==='authorized'?($complete?'available':'unavailable'):$access,'complete'=>$complete,'wording_inferred'=>false]];
        return [...$context,'context_digest'=>YoRo_Early_Access_Product_Read::params_hash($context)];
    }

    private static function fact_text(mixed $value, int $max=200): string
    {
        if (!is_scalar($value)) { return ''; }
        $text=strip_tags(html_entity_decode((string)$value,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        $text=preg_replace('/[\x00-\x1f\x7f]/u',' ',$text) ?? '';
        return trim(mb_strcut($text,0,$max,'UTF-8'));
    }

    private static function fact_decimal(mixed $value): ?string
    {
        // Woo stored monetary getters return decimal strings. Never round a float into evidence.
        if ((!is_string($value)&&!is_int($value)) || strlen((string)$value)>80 || !preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D',(string)$value)) { return null; }
        return (string)$value;
    }

    /** Nonnegative refund amounts are summed as decimal digits, without floating-point rounding. */
    private static function fact_sum(array $amounts): ?string
    {
        $scale=0;
        foreach ($amounts as $amount) { if (self::fact_decimal($amount)===null || str_starts_with($amount,'-')) { return null; } $scale=max($scale,strlen(explode('.',$amount)[1]??'')); }
        $sum='0';
        foreach ($amounts as $amount) {
            $parts=explode('.',$amount); $digits=$parts[0].str_pad($parts[1]??'',$scale,'0'); $carry=0; $result='';
            for ($a=strlen($sum)-1,$b=strlen($digits)-1;$a>=0||$b>=0||$carry;$a--,$b--) { $n=($a>=0?(int)$sum[$a]:0)+($b>=0?(int)$digits[$b]:0)+$carry; $result=($n%10).$result; $carry=intdiv($n,10); }
            $sum=ltrim($result,'0')?:'0';
        }
        if ($scale) { $sum=str_pad($sum,$scale+1,'0',STR_PAD_LEFT); $sum=substr($sum,0,-$scale).'.'.substr($sum,-$scale); }
        return strlen($sum)<=80?$sum:null;
    }

    private static function facts(WC_Order $order): array
    {
        $timezone=wp_timezone()->getName();
        $date=static function($value)use($timezone):array {
            return ['value'=>$value instanceof DateTimeInterface ? (new DateTimeImmutable('@'.$value->getTimestamp()))->setTimezone(wp_timezone())->format('c') : null,
                'timezone'=>$timezone,'precision'=>$value instanceof DateTimeInterface?'second':'unknown'];
        };
        $all=$order->get_items('line_item'); $items=[]; $attributesTruncated=false;
        foreach (array_slice($all,0,20,true) as $item) {
            $productId=(int)$item->get_product_id(); $variationId=(int)$item->get_variation_id();
            $catalog=['availability'=>'unavailable','name'=>null,'sku'=>null,'type'=>null,'virtual'=>null,'downloadable'=>null];
            $product=($variationId?:$productId)>0?wc_get_product($variationId?:$productId):false; $attributes=[];
            if ($product instanceof WC_Product && $product->get_status('edit')==='publish' && (bool)$variationId===($product->get_type()==='variation') && in_array($product->get_type(),['simple','variable','variation'],true) && (string)get_post_field('post_password',$product->get_id())==='' && ($variationId || is_post_publicly_viewable($product->get_id()))) {
                $parent=$variationId?wc_get_product($product->get_parent_id('edit')):null;
                if (!$variationId || ($parent instanceof WC_Product && $parent->get_id()===$productId && $parent->get_status('edit')==='publish' && is_post_publicly_viewable($parent->get_id()) && (string)get_post_field('post_password',$parent->get_id())==='')) {
                    $catalog=['availability'=>'public_current','name'=>self::fact_text($product->get_name('edit')),'sku'=>self::fact_text($product->get_sku('edit'),128),
                        'type'=>self::fact_text($product->get_type()),'virtual'=>(bool)$product->get_virtual('edit'),'downloadable'=>(bool)$product->get_downloadable('edit')];
                    // Read only the public parent's declared variation attributes, never arbitrary line metadata.
                    if ($variationId) {
                        foreach ($parent->get_attributes('edit') as $attribute) {
                            if (!$attribute instanceof WC_Product_Attribute || !$attribute->get_variation()) { continue; }
                            if (count($attributes)>=10) { $attributesTruncated=true; break; }
                            $name=$attribute->get_name(); $value=$item->get_meta($name,true,'edit');
                            if (is_string($value)&&$value!=='') { $attributes[]=self::fact_text($name,100).': '.self::fact_text($value); }
                        }
                    }
                }
            }
            $items[]=['item_id'=>(int)$item->get_id(),'name'=>self::fact_text($item->get_name('edit')),'quantity'=>self::fact_decimal($item->get_quantity('edit')),
                'subtotal'=>self::fact_decimal($item->get_subtotal('edit')),'total'=>self::fact_decimal($item->get_total('edit')),'tax'=>self::fact_decimal($item->get_total_tax('edit')),
                'product_id'=>$productId?:null,'variation_id'=>$variationId?:null,'variation_attributes'=>$attributes,...array_combine(array_map(static fn($key)=>'catalog_'.$key,array_keys($catalog)),array_values($catalog))];
        }
        $refunds=$order->get_refunds(); $refundAmounts=[];
        foreach (array_slice($refunds,0,20) as $refund) { $refundAmounts[]=$refund->get_amount('edit'); }
        $tracking=[];$trackingTruncated=false;$trackingSupported=false;
        $contactDigits=array_map(static fn($phone)=>preg_replace('/[^0-9]/','',(string)$phone),[$order->get_billing_phone('edit'),$order->get_shipping_phone('edit')]);
        $appendTracking=static function($number,$carrier,string $source)use(&$tracking,&$trackingTruncated,$contactDigits):void {
            if (!is_string($number)||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9-]{4,62}[A-Za-z0-9]\z/D',$number)||!preg_match('/[0-9]/',$number)) { $trackingTruncated=true;return; }
            $digits=str_replace('-','',$number);
            if (ctype_digit($digits)&&in_array($digits,$contactDigits,true)) { $trackingTruncated=true;return; }
            if (count($tracking)>=20) { $trackingTruncated=true;return; }
            $label=null;
            foreach (['UPS','USPS','FedEx','DHL','DPD','GLS','Royal Mail','Canada Post','Australia Post'] as $known) { if (is_string($carrier)&&strcasecmp($known,$carrier)===0) { $label=$known;break; } }
            $tracking[]=['source'=>$source,'carrier'=>$label,'tracking_number'=>$number];
        };

        if (class_exists('YoRo_Order_Management')) {
            $state=YoRo_Order_Management::tracking_fingerprint($order);$trackingSupported=$state['supported'];$trackingTruncated=!$state['complete'];
            foreach ($state['records'] as $record) {
                if (($record['tracking_number']??'')==='') {continue;}
                $appendTracking($record['tracking_number'],$record['carrier'],'native_fulfillment');
            }
        }
        $legacy=$order->get_meta('_tracking_number',true,'edit');
        if ($legacy!=='') { $appendTracking($legacy,null,'order_tracking_number');$trackingSupported=true; }
        $trackingTruncated=$trackingTruncated||count($tracking)>20; $tracking=array_slice($tracking,0,20);
        $customerId=(int)$order->get_customer_id('edit'); $paid=$order->get_date_paid('edit');
        return ['profile'=>'email_facts_v1','source'=>'woocommerce_order','text_trust'=>'untrusted_data',
            'order'=>['id'=>$order->get_id(),'status'=>self::fact_text($order->get_status('edit')),'status_label'=>self::fact_text(wc_get_order_status_name($order->get_status('edit'))),
                'currency'=>self::fact_text($order->get_currency('edit'),10),'created_at'=>$date($order->get_date_created('edit')),'paid_at'=>$date($paid),'completed_at'=>$date($order->get_date_completed('edit')),
                'payment'=>['method'=>self::fact_text($order->get_payment_method('edit'))?:null,'method_title'=>self::fact_text($order->get_payment_method_title('edit'))?:null,'paid_recorded'=>$paid instanceof DateTimeInterface,'gateway_settlement_verified'=>false],
                'totals'=>['subtotal'=>self::fact_sum(array_map(static fn($item)=>$item->get_subtotal('edit'),array_values($all))),'discount'=>self::fact_decimal($order->get_discount_total('edit')),
                    'shipping'=>self::fact_decimal($order->get_shipping_total('edit')),'tax'=>self::fact_decimal($order->get_total_tax('edit')),'total'=>self::fact_decimal($order->get_total('edit')),
                    'refunded'=>count($refunds)>20?null:self::fact_sum($refundAmounts)]],
            'customer'=>['scope'=>'this_order_billing_contact','name'=>self::fact_text(trim($order->get_billing_first_name('edit').' '.$order->get_billing_last_name('edit'))),
                'email'=>$order->get_billing_email('edit'),'customer_id'=>$customerId?:null,'type'=>$customerId?'registered':'guest','email_syntax_valid'=>true,'identity_verified'=>false,'inbox_verified'=>false],
            'items'=>$items,'coverage'=>['items_returned'=>count($items),'items_total'=>count($all),'items_truncated'=>count($all)>20,'variation_attributes_truncated'=>$attributesTruncated,
                'refunds_loaded'=>count($refundAmounts),'refunds_total'=>count($refunds),'refunds_truncated'=>count($refunds)>20,'customer_history'=>'not_loaded','private_notes'=>'not_loaded',
                'catalog_scope'=>'public_current_for_returned_items','historical_product_classification'=>'unknown'],
            'tracking'=>$tracking,'fulfillment'=>['source'=>'stored_order_tracking','tracking_truncated'=>$trackingTruncated,'coverage'=>$trackingSupported?'supported_storage_only':'unavailable',
                'carrier_verified'=>false,'dispatched_at'=>null,'delivered_at'=>null,'shipping_required'=>null],
            'store'=>['name'=>self::fact_text(get_option('blogname','')),'locale'=>self::fact_text(get_locale(),40),'timezone'=>$timezone],
            'policy'=>['wording'=>null,'estimated_delivery_at'=>null]];
    }

    public static function context(int $id,array $selectors=[]): array
    {
        return self::context_for_order(self::order($id),$selectors);
    }

    private static function context_for_order(WC_Order $order,array $selectors): array
    {
        self::changes($selectors,self::Actions[0]);
        $id=$order->get_id();$history=self::history($order);
        $wrapper=self::authored($order,'YoRo email wrapper binding','YoRo exact body binding');
        $template=$selectors['template']??'order_details';
        if(($template==='customer_note')!==isset($selectors['note'])){self::invalid();}
        $native=isset($selectors['template'])?self::native($order,$template,$selectors['note']??''):null;
        $email=$native['email']??$wrapper['email'];
        $data=['order_id'=>$id,'order_number'=>(string)$order->get_order_number(),'recipient'=>$order->get_billing_email('edit'),
            'template'=>$template,'native_eligible'=>$native['eligible']??false,'subject'=>$native['subject']??'','html_body'=>$native['html_body']??'',
            'drafts'=>[],'draft'=>null,'history'=>$history,
            'settings'=>['from_name'=>(string)$email->get_from_name(),'from_address'=>(string)$email->get_from_address(),
                'enabled'=>$email->is_enabled(),'content_type'=>$email->get_content_type(),'configured_not_deliverability'=>true]];
        // Both default authored read profiles bind the same facts before exposing optional roots.
        // This lets the existing fresh legacy preparation read recheck an opt-in source exactly.
        $facts=isset($selectors['template'])?null:self::facts($order);
        $templateSources=[];
        if(function_exists('wc_locate_template')) {
            foreach(['email-header.php','email-footer.php','email-styles.php'] as $file) {
                $path=wc_locate_template('emails/'.$file);
                if(!is_string($path)||!is_file($path)||!is_readable($path)){self::invalid();}
                $templateSources[$file]=hash_file('sha256',$path);
            }
        }
        $data['version']=YoRo_Early_Access_Product_Read::params_hash(['order'=>YoRo_Order_Presenter::order_version($order),'data'=>$data,
            'authored_wrapper'=>$wrapper['html_body'],'authored_headers'=>$wrapper['headers'],'template_sources'=>$templateSources,...($facts===null?[]:['email_facts'=>$facts])]);
        if (in_array($selectors['context_profile']??null,['email_facts_v1','customer_service_v1'],true)) { $data['context_profile']=$selectors['context_profile'];$data['facts']=[...$facts,'observed_at'=>gmdate('c')]; }
        if (($selectors['context_profile']??null)==='customer_service_v1') { $data['service_context']=self::service_context($order,$selectors,$data['version'],$history); }
        $data['composer']=['subject'=>$selectors['subject']??'','body'=>$selectors['body']??'','body_format'=>$selectors['body_format']??'plain_text'];
        if(strlen(wp_json_encode($data))>55000){self::invalid();}
        return $data;
    }

    public static function read($params): array|WP_Error
    {
        $params=array_diff_key((array)$params,array_flip(['_yoro_confirmed','_yoro_preview']));
        try {if(!self::valid_params(self::ReadActions[0],$params)){self::invalid();}
            return self::result(self::ReadActions[0],self::context($params['order_id'],array_diff_key($params,['order_id'=>true])),true);
        } catch(Throwable $error){return new WP_Error('order_email_unavailable','Current order email context is unavailable.');}
    }

    public static function execute(array $operation): array|WP_Error
    {
        if(($operation['extensions']['yoro.mcp_early_access_product_read']['read_family']??null)!=='order_email'||!self::valid_params($operation['action']??'',$operation['params']??[])){return new WP_Error('order_email_denied','Order email read authority is unavailable.');}
        $execution=YoRo_Action_Executor::execute($operation['action'],$operation['params'],true,false,['connector_remote_agent'=>true,'workflow_id'=>$operation['workflow_id'],'operation_id'=>$operation['operation_id']]);
        return is_wp_error($execution)?$execution:YoRo_Commerce_Contract_Builder::canonical_execution_payload($execution,$operation['action']);
    }


    /** Accept only a canonical, bounded editor dialect; never silently rewrite authored text. */
    public static function safeHtml(string $html): string
    {
        if (strlen($html) > 12000 || !class_exists(\DOMDocument::class) || preg_match('/<!|<\?|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $html)) { self::invalid(); }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$ok || !$document->getElementsByTagName('body')->item(0)) { self::invalid(); }
        $nodes = 0;
        $serialize = function ($node, int $depth = 0) use (&$serialize, &$nodes): string {
            if (++$nodes > 2000 || $depth > 20) { self::invalid(); }
            if ($node instanceof \DOMText) { return htmlspecialchars($node->data, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
            if (!$node instanceof \DOMElement || !in_array($node->tagName, ['p','h1','h2','h3','br','strong','em','ul','ol','li','a'], true)) { self::invalid(); }
            $attributes = '';
            if ($node->tagName === 'a') {
                $url = $node->getAttribute('href'); $parts = parse_url($url);
                if ($node->attributes->length !== 1 || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || !filter_var($url, FILTER_VALIDATE_URL)) { self::invalid(); }
                $attributes = ' href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            } elseif ($node->attributes->length !== 0) { self::invalid(); }
            $content = ''; foreach ($node->childNodes as $child) { $content .= $serialize($child, $depth + 1); }
            if ($node->tagName === 'br') { if ($content !== '') { self::invalid(); } return '<br>'; }
            return '<'.$node->tagName.$attributes.'>'.$content.'</'.$node->tagName.'>';
        };
        $canonical = ''; foreach ($document->getElementsByTagName('body')->item(0)->childNodes as $node) { $canonical .= $serialize($node); }
        if ($canonical !== $html || trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') { self::invalid(); }
        return $html;
    }

    public static function slots(array $params): array
    {
        $slots = [];
        foreach (['subject', 'body', 'note'] as $field) {
            if (isset($params['changes'][$field])) {
                $slots[] = ['field' => $field, 'content' => $params['changes'][$field], 'content_scope' => $field === 'note' ? 'customer_note' : 'customer_email', 'provenance' => 'user_goal'];
            }
        }

        return $slots;
    }

    public static function prepare(array $params,string $action): array|WP_Error
    {
        $params=array_diff_key($params,array_flip(['_yoro_confirmed','_yoro_preview']));
        try {
            if(!self::valid_params($action,$params)){self::invalid();}
            $order=self::order($params['order_id'],true);$changes=self::changes($params['changes'],$action);
            $selectors=array_intersect_key($changes,array_flip(['draft_id','template','note']));if($action==='orders.add_customer_note'){$selectors['template']='customer_note';}$source=self::context_for_order($order,$selectors);
            if(!hash_equals($params['expected_version'],$source['version'])||$params['recipient']!==$source['recipient']){self::invalid();}
            if(in_array($action,['orders.send_native_email','orders.add_customer_note'],true)&&(!$source['native_eligible']||$params['subject']!==$source['subject']||$params['html_body']!==$source['html_body'])){self::invalid();}
            return ['order'=>$order,'source'=>$source,'changes'=>$changes,'authored'=>$action==='orders.send_authored_email'?self::authored($order,$changes['subject'],$changes['body'],$changes['body_format']??'plain_text'):null];
        } catch(Throwable $error){return new WP_Error('order_email_conflict','The current email, recipient, mail configuration or order version no longer matches the preview.');}
    }

    public static function preview(array $params,string $action): array|WP_Error
    {
        $prepared=self::prepare($params,$action);
        return is_wp_error($prepared)?$prepared:['success'=>true,'action'=>$action,'summary'=>'Exact order-bound email change.','preview'=>['changes'=>$prepared['changes'],'recipient'=>$prepared['source']['recipient']]];
    }

    public static function content_hash(array $params,string $action): string
    {
        return YoRo_Early_Access_Product_Read::params_hash(['action'=>$action,'recipient'=>$params['recipient'],'changes'=>$params['changes'],
            ...(in_array($action,['orders.send_native_email','orders.add_customer_note'],true)?['subject'=>$params['subject'],'html_body'=>$params['html_body']]:[])]);
    }

    private static function record(WC_Order $order,array $record): void
    {
        $rows=self::history($order);$rows=array_values(array_filter($rows,static fn($row)=>$row['id']!==$record['id']));array_unshift($rows,$record);
        $order->update_meta_data('_yoro_observed_order_mail',array_slice($rows,0,20));$order->save_meta_data();
        $fresh=wc_get_order($order->get_id());$fresh->read_meta_data(true);
        $persisted=$fresh->get_meta('_yoro_observed_order_mail',true);
        if(!is_array($persisted)||($persisted[0]??null)!==$record){throw new RuntimeException('The observed mail record was not persisted.');}
    }

    private static function send(array $params,string $action,array $prepared,?int &$noteId=null): bool
    {
        $source=$prepared['source'];$recipient=$source['recipient'];$changes=$params['changes'];
        $note=$action==='orders.add_customer_note';$native=in_array($action,['orders.send_native_email','orders.add_customer_note'],true)?self::native($prepared['order'],$changes['template']??'customer_note',$changes['note']??''):null;
        $subject=$native?$source['subject']:$changes['subject'];
        $authored=$prepared['authored'];$mail=$native??$authored;
        $html=$native?$source['html_body']:$authored['html_body'];
        // Native send() does not clean its filters if a site hook throws. Track only
        // callbacks introduced by this send so an interrupted attempt cannot leak them.
        $mailObjects=[$mail['email']];
        if($note){$mailObjects[]=WC()->mailer()->get_emails()['WC_Email_Customer_Note'];}
        $cleanup=[];
        foreach($mailObjects as $email){foreach(['wp_mail_from'=>'get_from_address','wp_mail_from_name'=>'get_from_name','wp_mail_content_type'=>'get_content_type']as$hook=>$method){if(false===has_filter($hook,[$email,$method])){$cleanup[]=[$hook,$email,$method];}}}
        $calls=0;$observed=null;
        $guard=static function($atts)use($recipient,$subject,$html,&$calls){
            if(++$calls!==1||(($atts['to']??null)!==[$recipient]&&($atts['to']??null)!==$recipient)||($atts['subject']??null)!==$subject||($atts['message']??null)!==$html||!empty($atts['attachments'])
                ||preg_match('/(?:^|[\r\n])(?:Cc|Bcc):/i',is_array($atts['headers']??null)?implode("\n",$atts['headers']):(string)($atts['headers']??''))){throw new RuntimeException('Final mail arguments differ from confirmed content.');}
            return $atts;
        };
        $from=$source['settings']['from_address'];$fromName=$source['settings']['from_name'];
        $transport=static function($mailer)use($recipient,$subject,$html,$from,$fromName){
            if(array_column($mailer->getToAddresses(),0)!==[$recipient]||$mailer->getCcAddresses()!==[]||$mailer->getBccAddresses()!==[]||$mailer->getAttachments()!==[]||$mailer->Subject!==$subject||$mailer->Body!==$html||$mailer->From!==$from||$mailer->FromName!==$fromName){throw new RuntimeException('The final mail transport differs from the confirmed email.');}
        };
        $sent=static function($success,$id,$email)use(&$observed,$prepared){if($id==='customer_note'&&$email->object instanceof WC_Order&&$email->object->get_id()===$prepared['order']->get_id()){$observed=$success;}};
        $callbackGuard=static function($callback){if($callback!=='wp_mail'){throw new RuntimeException('The native mail callback changed after review.');}return $callback;};
        $checklist=static function($atts)use($recipient,$subject,$html){
            if((($atts['to']??null)===[$recipient]||($atts['to']??null)===$recipient)&&($atts['subject']??null)===$subject
                &&is_string($atts['message']??null)&&self::checklist_entities($atts['message'])===$html){$atts['message']=$html;}
            return $atts;
        };
        if(has_filter('pre_wp_mail')!==false){self::invalid();}
        if($authored){add_filter('wp_mail',$checklist,9);}
        add_filter('woocommerce_mail_callback',$callbackGuard,PHP_INT_MAX);
        add_filter('wp_mail',$guard,PHP_INT_MAX);add_action('phpmailer_init',$transport,PHP_INT_MAX);if($note){add_action('woocommerce_email_sent',$sent,PHP_INT_MAX,3);}
        try {
            if($note){$noteId=$prepared['order']->add_order_note($changes['note'],1,true);$ok=$observed;}else{$ok=$mail['email']->send($recipient,$subject,$mail['raw'],$mail['headers'],[]);}
            if($calls!==1||!is_bool($ok)){throw new RuntimeException('The mail outcome is unknown.');}
            return $ok;
        } finally {
            if($authored){remove_filter('wp_mail',$checklist,9);}
            remove_filter('woocommerce_mail_callback',$callbackGuard,PHP_INT_MAX);
            remove_filter('wp_mail',$guard,PHP_INT_MAX);remove_action('phpmailer_init',$transport,PHP_INT_MAX);
            if($note){remove_action('woocommerce_email_sent',$sent,PHP_INT_MAX);}
            foreach($cleanup as [$hook,$email,$method]){remove_filter($hook,[$email,$method]);}
            foreach($mailObjects as $email){$email->restore_locale();}
        }
    }

    public static function write(array $params,string $action): array|WP_Error
    {
        if(($params['_yoro_confirmed']??null)!==true||($params['_yoro_preview']??false)!==false){return new WP_Error('confirmation_required','Execute through the confirmed commerce executor.');}
        $params=array_diff_key($params,array_flip(['_yoro_confirmed','_yoro_preview']));
        $prepared=self::prepare($params,$action);if(is_wp_error($prepared)){return $prepared;}$order=$prepared['order'];$hash=self::content_hash($params,$action);
        $recordId='mail_'.wp_generate_uuid4();$outcome='mailer_accepted';
        try {
                $record=['id'=>$recordId,'subject'=>$params['subject']??$params['changes']['subject']??'Customer-visible order note','outcome'=>'attempting','content_hash'=>$hash,'occurred_at'=>gmdate('c')];
                self::record($order,$record);
                try{$noteId=null;$ok=self::send($params,$action,$prepared,$noteId);}catch(Throwable $error){$record['outcome']='unknown';self::record($order,$record);throw $error;}
                $record['outcome']=$ok?'mailer_accepted':'failed';self::record($order,$record);
                if(!$ok){return new WP_Error('order_mail_failed','The mailer did not accept this attempt. Inbox delivery is not verified.');}
                $outcome=$action==='orders.add_customer_note'?'note_created':'mailer_accepted';if($noteId!==null){$recordId='note_'.$noteId;}
            $fresh=self::context($order->get_id(),array_intersect_key($params['changes'],array_flip(['draft_id','template','note']))+($action==='orders.add_customer_note'?['template'=>'customer_note']:[]));
            return self::result($action,['order_id'=>$order->get_id(),'record_id'=>$recordId,'outcome'=>$outcome,'content_hash'=>$hash,'current_version'=>$fresh['version'],'inbox_delivery_verified'=>false],false);
        }catch(Throwable $error){return new WP_Error('local_execution_outcome_unknown','The local outcome is unknown. Read this order history; do not automatically resend or repeat.');}
    }

    private static function result(string $action,array $data,bool $read): array
    {
        $message=$read?'Current order-bound email context.':'Confirmed order email operation completed. Inbox delivery is not verified.';
        return ['success'=>true,'action'=>$action,'message'=>$message,'summary'=>$message,'data'=>$data,'read_only'=>$read,'undoable'=>false,'result_type'=>'order_email','display_type'=>'order_email','assistant_summary'=>$message,'extensions'=>(object)[]];
    }
}
