<?php
define('ABSPATH','/'); define('DAY_IN_SECONDS',86400); define('MINUTE_IN_SECONDS',60);
function add_action(){} function apply_filters($h,$v){return $v;} function is_admin(){return false;} function get_option(){return '1.4.0';}
function wp_parse_url($u,$c=-1){return parse_url($u,$c);} function home_url($p=''){return 'https://smartpropertychecks.com'.$p;}
require '/home/mundo/seo-work/plugins/lead-tracker/spc-lead-and-call-tracker.php';
$C='SPC_Lead_Tracker';
$t=[
 ['ads url', $C::guess_channel('', 'https://smartpropertychecks.com/contact-us/?gad_source=1&gclid=x'), 'Google Ads'],
 ['no ref', $C::guess_channel('', 'https://smartpropertychecks.com/contact-us/'), 'Direct'],
 ['google ref', $C::guess_channel('https://www.google.com/', ''), 'SEO (Google)'],
 ['internal', $C::guess_channel('https://smartpropertychecks.com/x/', ''), 'Direct'],
 ['bing', $C::guess_channel('https://www.bing.com/', ''), 'SEO (Bing)'],
 ['chatgpt referrer', $C::guess_channel('https://chatgpt.com/', 'https://smartpropertychecks.com/sutton/'), 'ChatGPT'],
 ['chatgpt utm tag, no referrer', $C::channel_from('', 'https://smartpropertychecks.com/landlord-safety-certificates-northamptonshire/?utm_source=chatgpt.com', '', ''), 'ChatGPT'],
 ['no referrer, no tag = Direct', $C::guess_channel('', 'https://smartpropertychecks.com/sutton/'), 'Direct'],
 ['gemini referrer (not google SEO)', $C::guess_channel('https://gemini.google.com/', 'https://smartpropertychecks.com/'), 'Gemini'],
 ['google ref on ads page, no click id', $C::guess_channel('https://www.google.co.uk/', 'https://smartpropertychecks.com/epc-london/'), 'Google Ads (unconfirmed)'],
 ['google ref on county page, no click id', $C::guess_channel('https://www.google.co.uk/', 'https://smartpropertychecks.com/landlord-safety-certificates-northamptonshire/'), 'SEO (Google)'],
 ['google ref on ads page WITH gclid', $C::guess_channel('https://www.google.co.uk/', 'https://smartpropertychecks.com/epc-london/?gclid=abc'), 'Google Ads'],
 ['pkt day start', $C::uk_to_utc('2026-11-12'), '2026-11-11 19:00:00'],
 ['pkt day start 2', $C::uk_to_utc('2026-07-01'), '2026-06-30 19:00:00'],
 ['22:11 Wed after hours', var_export($C::is_out_of_hours('2026-11-11 22:11:49'),true), 'true'],
 ['10:00 Wed open', var_export($C::is_out_of_hours('2026-11-11 10:00:00'),true), 'false'],
 ['Sunday noon closed', var_export($C::is_out_of_hours('2026-11-15 12:00:00'),true), 'true'],
 ['Sat 17:59 open', var_export($C::is_out_of_hours('2026-11-14 17:59:00'),true), 'false'],
 ['summer 17:30 UTC = 18:30 BST closed', var_export($C::is_out_of_hours('2026-07-01 17:30:00'),true), 'true'],
 ['attr ms now', $C::parse_attr_time((string)(time()*1000)) !== null ? 'ok':'null', 'ok'],
 ['attr junk', var_export($C::parse_attr_time('abc'),true), 'NULL'],
 ['attr too old', var_export($C::parse_attr_time((string)((time()-200*86400)*1000)),true), 'NULL'],
];
$f=0; foreach($t as [$n,$got,$exp]){ $ok=$got===$exp; if(!$ok)$f++; echo ($ok?'PASS ':'FAIL ')."$n => $got\n"; } echo $f?"$f FAILED\n":"ALL PASS\n";
