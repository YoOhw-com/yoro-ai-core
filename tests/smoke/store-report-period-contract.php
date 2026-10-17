<?php
if (!defined('ABSPATH')) { exit(1); }
$assert=static function($ok,$reason){if(!$ok)throw new RuntimeException($reason);};
$cases=[
 [['window'=>'this_week'],'2026-10-18T12:00:00Z','UTC',1,'2026-10-12','2026-10-18'],
 [['window'=>'this_week'],'2026-10-18T12:00:00Z','UTC',0,'2026-10-18','2026-10-18'],
 [['window'=>'month','anchor_date'=>'2024-02-15'],'2026-10-18T12:00:00Z','UTC',1,'2024-02-01','2024-02-29'],
 [['window'=>'quarter','anchor_date'=>'2024-05-15'],'2026-10-18T12:00:00Z','UTC',1,'2024-04-01','2024-06-30'],
 [['window'=>'year','anchor_date'=>'2024-05-15'],'2026-10-18T12:00:00Z','UTC',1,'2024-01-01','2024-12-31'],
 [['window'=>'custom','date_from'=>'2026-03-08','date_to'=>'2026-03-08'],'2026-10-18T12:00:00Z','America/New_York',0,'2026-03-08','2026-03-08'],
];
foreach($cases as[$params,$now,$tz,$week,$start,$end]){
 $r=YoRo_Store_Report_Period::resolve($params,new DateTimeImmutable($now),$tz,$week);
 $assert(substr($r['windows'][0]['start'],0,10)===$start&&substr($r['windows'][0]['end'],0,10)===$end,'Calendar boundaries preserve store-local dates.');
 foreach($r['windows'] as$w){$day=new DateTimeImmutable($w['start']);$points=[];while($day<=new DateTimeImmutable($w['end'])){$points[YoRo_Store_Report_Period::bucket($day->format('Y-m-d'),$w,$r['context'])]=true;$day=$day->modify('+1 day');}$assert(count($points)<=32,'Adaptive chart retains bounded calendar buckets.');}
}
$assert(!YoRo_Early_Access_Store_Overview_Read::valid_params('store.get_insights',['window'=>'custom','date_from'=>'2024-02-30','date_to'=>'2024-03-01']),'Invalid dates are rejected locally.');
echo "Store report periods: calendar/week-start/leap/DST/custom and chart bounds passed.\n";
