<?php

if (!defined('ABSPATH')) { exit; }

/** Exact authored drafts and synchronous native mail, only through the journaled lane. */
final class YoRo_Order_Email
{
    public const ReadActions = ['orders.get_email_context'];
    public const WriteActions = ['orders.save_authored_email_draft','orders.update_authored_email_draft','orders.send_authored_email','orders.send_native_email','orders.add_customer_note'];
    public const Actions = self::ReadActions;
    public const ManifestExtension = 'yoro.early_access_order_email';
    public static function manifest(): array
    {
        return ['version' => 1, 'actions' => [...self::Actions, ...self::WriteActions], 'max_targets' => 1,
            'requires_source_read' => true, 'requires_confirmation' => true, 'result_version' => 1, 'recipient' => 'current_order_billing', 'composer' => 'authored_text_only'];
    }

    public static function object(array $properties, ?array $required = null): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => $required ?? array_keys($properties), 'additionalProperties' => false];
    }

    public static function properties(string $action): array
    {
        $draft = ['type' => 'string', 'pattern' => '^draft_[a-f0-9-]{36}$'];
        $authored = ['subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200], 'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000]];

        return match ($action) {
            self::Actions[0] => ['draft_id' => $draft, 'template' => ['type' => 'string', 'enum' => ['order_details', 'processing', 'completed', 'customer_note']], 'note' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000]],
            'orders.save_authored_email_draft', 'orders.send_authored_email' => $authored,
            'orders.update_authored_email_draft' => ['draft_id' => $draft, ...$authored],
            'orders.send_native_email' => ['template' => ['type' => 'string', 'enum' => ['order_details', 'processing', 'completed']]],
            'orders.add_customer_note' => ['note' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000]],
            default => self::invalid(),
        };
    }

    public static function changes(mixed $changes, string $action): array
    {
        $properties = self::properties($action);
        if (! is_array($changes) || ($changes !== [] && array_is_list($changes)) || array_diff(array_keys($changes), array_keys($properties))
            || ($action !== self::Actions[0] && count($changes) !== count($properties))) {
            self::invalid();
        }
        foreach ($changes as $field => $value) {
            $property = $properties[$field];
            if (! is_string($value) || trim($value) === '' || strlen($value) > ($property['maxLength'] ?? 200)
                || preg_match('//u', $value) !== 1 || preg_match(in_array($field, ['body', 'note'], true) ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>]/' : '/[\x00-\x1f\x7f<>]/', $value)
                || (isset($property['enum']) && ! in_array($value, $property['enum'], true))
                || (isset($property['pattern']) && preg_match('~\A'.substr($property['pattern'], 1, -1).'\z~D', $value) !== 1)) {
                self::invalid();
            }
        }
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
            return $object(['order_id' => $id, 'record_id' => ['type' => 'string', 'maxLength' => 80], 'outcome' => ['type' => 'string', 'enum' => ['draft_saved', 'mailer_accepted', 'note_created']],
                'content_hash' => $version, 'current_version' => $version, 'inbox_delivery_verified' => ['type' => 'boolean', 'enum' => [false]]]);
        }

        return $object(['order_id' => $id, 'order_number' => $text, 'recipient' => ['type' => 'string', 'maxLength' => 254], 'version' => $version,
            'template' => ['type' => 'string', 'enum' => ['order_details', 'processing', 'completed', 'customer_note']], 'native_eligible' => ['type' => 'boolean'],
            'subject' => $text, 'html_body' => ['type' => 'string', 'maxLength' => 24000],
            'drafts' => ['type' => 'array', 'maxItems' => 20, 'items' => $object(['id' => $draftId, 'subject' => $text, 'version' => $version])],
            'draft' => ['type' => ['object', 'null'], 'properties' => ['id' => $draftId, 'subject' => $text, 'body' => ['type' => 'string', 'maxLength' => 12000], 'version' => $version], 'required' => ['id', 'subject', 'body', 'version'], 'additionalProperties' => false],
            'history' => ['type' => 'array', 'maxItems' => 20, 'items' => $object(['id' => ['type' => 'string', 'maxLength' => 80], 'subject' => $text,
                'outcome' => ['type' => 'string', 'enum' => ['attempting', 'mailer_accepted', 'failed', 'unknown']], 'content_hash' => $version, 'occurred_at' => $date])],
            'settings' => $object(['from_name' => $text, 'from_address' => ['type' => 'string', 'maxLength' => 254], 'enabled' => ['type' => 'boolean'],
                'content_type' => ['type' => 'string', 'enum' => ['text/html', 'text/plain', 'multipart/alternative']], 'configured_not_deliverability' => ['type' => 'boolean', 'enum' => [true]]])]);
    }

    private static function invalid(): never { throw new InvalidArgumentException('Use exact authored content and the current order billing recipient.'); }

    public static function input_properties(string $action): array
    {
        $id=['type'=>'integer','minimum'=>1];
        if(in_array($action,self::ReadActions,true)) { return ['order_id'=>$id,...self::properties($action)]; }
        return ['order_id'=>$id,'changes'=>self::object(self::properties($action)),'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
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

    private static function drafts(WC_Order $order): array
    {
        $rows=$order->get_meta('_yoro_customer_email_drafts',true);$rows=is_array($rows)?array_values($rows):[];
        if(count($rows)>20){self::invalid();}
        foreach($rows as &$row){
            self::changes(['draft_id'=>$row['id']??null,'subject'=>$row['subject']??null,'body'=>$row['body']??null],'orders.update_authored_email_draft');
            $row['version']=YoRo_Early_Access_Product_Read::params_hash(array_intersect_key($row,array_flip(['id','subject','body','updated_at'])));
        }
        return $rows;
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

    public static function context(int $id,array $selectors=[]): array
    {
        $order=self::order($id);$drafts=self::drafts($order);$history=self::history($order);$selected=null;
        if(isset($selectors['draft_id'])){foreach($drafts as $draft){if($draft['id']===$selectors['draft_id']){$selected=array_intersect_key($draft,array_flip(['id','subject','body','version']));}}
            if($selected===null){self::invalid();}}
        $template=$selectors['template']??'order_details';if(($template==='customer_note')!==isset($selectors['note'])){self::invalid();}$native=self::native($order,$template,$selectors['note']??'');
        $data=['order_id'=>$id,'order_number'=>(string)$order->get_order_number(),'recipient'=>$order->get_billing_email('edit'),
            'template'=>$template,'native_eligible'=>$native['eligible'],'subject'=>$native['subject'],'html_body'=>$native['html_body'],
            'drafts'=>array_map(static fn($row)=>array_intersect_key($row,array_flip(['id','subject','version'])),$drafts),'draft'=>$selected,'history'=>$history,
            'settings'=>['from_name'=>(string)$native['email']->get_from_name(),'from_address'=>(string)$native['email']->get_from_address(),
                'enabled'=>$native['email']->is_enabled(),'content_type'=>$native['email']->get_content_type(),'configured_not_deliverability'=>true]];
        $data['version']=YoRo_Early_Access_Product_Read::params_hash(['order'=>YoRo_Order_Presenter::order_version($order),'data'=>$data,'drafts'=>$drafts]);
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
            $selectors=array_intersect_key($changes,array_flip(['draft_id','template','note']));if($action==='orders.add_customer_note'){$selectors['template']='customer_note';}$source=self::context($order->get_id(),$selectors);
            if(!hash_equals($params['expected_version'],$source['version'])||$params['recipient']!==$source['recipient']){self::invalid();}
            if(in_array($action,['orders.send_native_email','orders.add_customer_note'],true)&&(!$source['native_eligible']||$params['subject']!==$source['subject']||$params['html_body']!==$source['html_body'])){self::invalid();}
            return ['order'=>$order,'source'=>$source,'changes'=>$changes];
        } catch(Throwable $error){return new WP_Error('order_email_conflict','The current email, draft, recipient, native mail configuration or order version no longer matches the preview.');}
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
        $html=$native?$source['html_body']:'<div style="white-space:pre-wrap">'.htmlspecialchars($changes['body'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</div>';
        // Native send() does not clean its filters if a site hook throws. Track only
        // callbacks introduced by this send so an interrupted attempt cannot leak them.
        $mailObjects=$native?[$native['email']]:[];
        if($note){$mailObjects[]=WC()->mailer()->get_emails()['WC_Email_Customer_Note'];}
        $cleanup=[];
        foreach($mailObjects as $email){foreach(['wp_mail_from'=>'get_from_address','wp_mail_from_name'=>'get_from_name','wp_mail_content_type'=>'get_content_type']as$hook=>$method){if(false===has_filter($hook,[$email,$method])){$cleanup[]=[$hook,$email,$method];}}}
        $calls=0;$observed=null;
        $guard=static function($atts)use($recipient,$subject,$html,&$calls){
            if(++$calls!==1||(($atts['to']??null)!==[$recipient]&&($atts['to']??null)!==$recipient)||($atts['subject']??null)!==$subject||($atts['message']??null)!==$html||!empty($atts['attachments'])
                ||preg_match('/(?:^|[\r\n])(?:Cc|Bcc):/i',is_array($atts['headers']??null)?implode("\n",$atts['headers']):(string)($atts['headers']??''))){throw new RuntimeException('Final mail arguments differ from confirmed content.');}
            return $atts;
        };
        $transport=static function($mailer)use($recipient,$subject,$html){
            if(array_column($mailer->getToAddresses(),0)!==[$recipient]||$mailer->getCcAddresses()!==[]||$mailer->getBccAddresses()!==[]||$mailer->getAttachments()!==[]||$mailer->Subject!==$subject||$mailer->Body!==$html){throw new RuntimeException('The final mail transport differs from the confirmed email.');}
        };
        $sent=static function($success,$id,$email)use(&$observed,$prepared){if($id==='customer_note'&&$email->object instanceof WC_Order&&$email->object->get_id()===$prepared['order']->get_id()){$observed=$success;}};
        $callbackGuard=static function($callback){if($callback!=='wp_mail'){throw new RuntimeException('The native mail callback changed after review.');}return $callback;};
        if(has_filter('pre_wp_mail')!==false){self::invalid();}
        if($native){add_filter('woocommerce_mail_callback',$callbackGuard,PHP_INT_MAX);}
        add_filter('wp_mail',$guard,PHP_INT_MAX);add_action('phpmailer_init',$transport,PHP_INT_MAX);if($note){add_action('woocommerce_email_sent',$sent,PHP_INT_MAX,3);}
        try {
            if($note){$noteId=$prepared['order']->add_order_note($changes['note'],1,true);$ok=$observed;}else{$ok=$native?$native['email']->send($recipient,$subject,$native['raw'],$native['headers'],[]):wp_mail($recipient,$subject,$html,['Content-Type: text/html; charset=UTF-8'],[]);}
            if($calls!==1||!is_bool($ok)){throw new RuntimeException('The mail outcome is unknown.');}
            return $ok;
        } finally {
            if($native){remove_filter('woocommerce_mail_callback',$callbackGuard,PHP_INT_MAX);}
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
        $recordId='mail_'.wp_generate_uuid4();$outcome='draft_saved';
        try {
            if(str_contains($action,'draft')){
                $drafts=self::drafts($order);$changes=$prepared['changes'];$recordId=$changes['draft_id']??'draft_'.wp_generate_uuid4();
                $drafts=array_values(array_filter($drafts,static fn($row)=>$row['id']!==$recordId));
                if(count($drafts)>=20){self::invalid();}
                array_unshift($drafts,['id'=>$recordId,'subject'=>$changes['subject'],'body'=>$changes['body'],'created_by'=>get_current_user_id(),'updated_at'=>gmdate('c')]);
                $order->update_meta_data('_yoro_customer_email_drafts',$drafts);$order->save_meta_data();$order->read_meta_data(true);
                if($order->get_meta('_yoro_customer_email_drafts',true)!==$drafts){throw new RuntimeException('Draft readback unavailable.');}
            }else{
                $record=['id'=>$recordId,'subject'=>$params['subject']??$params['changes']['subject']??'Customer-visible order note','outcome'=>'attempting','content_hash'=>$hash,'occurred_at'=>gmdate('c')];
                self::record($order,$record);
                try{$noteId=null;$ok=self::send($params,$action,$prepared,$noteId);}catch(Throwable $error){$record['outcome']='unknown';self::record($order,$record);throw $error;}
                $record['outcome']=$ok?'mailer_accepted':'failed';self::record($order,$record);
                if(!$ok){return new WP_Error('order_mail_failed','The mailer did not accept this attempt. Inbox delivery is not verified.');}
                $outcome=$action==='orders.add_customer_note'?'note_created':'mailer_accepted';if($noteId!==null){$recordId='note_'.$noteId;}
            }
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
