#!/usr/bin/env python3
"""Guarded deploy of the lead tracker mu-plugin. usage: deploy.py [restore]"""
import sys, base64, hashlib, time, urllib.request
sys.path.insert(0, "/home/mundo/seo-work/borough"); import run
SRC = "/home/mundo/seo-work/plugins/lead-tracker/spc-lead-and-call-tracker.php"
EXPECT_OLD = "a8a4d8fb66ea52445fe206bc0277926b"
BK = "spc-backups/spc-lead-and-call-tracker.php.v190.bak"
if len(sys.argv) > 1 and sys.argv[1] == "restore":
    print(run.php(r"""$f=WPMU_PLUGIN_DIR.'/spc-lead-and-call-tracker.php'; $b=WP_CONTENT_DIR.'/%s';
    if(!file_exists($b)){echo json_encode(array('error'=>'no backup'));return;} copy($b,$f); echo json_encode(array('restored'=>md5_file($f)));""" % BK)); sys.exit()
new = open(SRC, "rb").read(); new_md5 = hashlib.md5(new).hexdigest()
r = run.php(r"""$f=WPMU_PLUGIN_DIR.'/spc-lead-and-call-tracker.php'; $cur=md5_file($f);
if($cur!=='%s'){echo json_encode(array('error'=>'server file changed','md5'=>$cur));return;}
@mkdir(WP_CONTENT_DIR.'/spc-backups',0755,true); $b=WP_CONTENT_DIR.'/%s'; if(!file_exists($b)) copy($f,$b);
if(md5_file($b)!==$cur){echo json_encode(array('error'=>'backup failed'));return;}
$tmp=$f.'.new'; file_put_contents($tmp,base64_decode('%s'));
if(md5_file($tmp)!=='%s'){@unlink($tmp); echo json_encode(array('error'=>'upload mismatch'));return;}
rename($tmp,$f); if(function_exists('opcache_invalidate')) opcache_invalidate($f,true);
echo json_encode(array('deployed'=>md5_file($f),'php'=>PHP_VERSION));""" % (EXPECT_OLD, BK, base64.b64encode(new).decode(), new_md5))
print("deploy", r)
if not r or "deployed" not in r: sys.exit(1)
time.sleep(2)
ok = True
try:
    h = urllib.request.urlopen(urllib.request.Request("https://smartpropertychecks.com/?nocache=%d" % time.time(), headers={"User-Agent": "Mozilla/5.0 SPC-verify"}), timeout=60).read().decode("utf8", "ignore")
    ok = "spc-lead-tracker" in h and "newEventId" in h and "critical error" not in h.lower() and "<h1" in h
    print("frontend", ok)
except Exception as e:
    print("frontend error", e); ok = False
a = run.php(r"""wp_set_current_user(1); ob_start(); try { SPC_Lead_Tracker::render_admin_dashboard(); $o=ob_get_clean(); } catch (Throwable $e) { ob_end_clean(); echo json_encode(array('error'=>$e->getMessage().' @'.$e->getLine())); return; }
global $wpdb; $cols=$wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}smart_lead_tracker");
echo json_encode(array('len'=>strlen($o),'table'=>substr_count($o,'spc-lead-cb'),'cols'=>array_slice($cols,-3),'dbv'=>get_option('spc_lead_tracker_db_version')));""")
print("admin", a)
if not ok or not a or "error" in a or a.get("len", 0) < 5000:
    print("FAILED -> restoring"); print(run.php(r"""$f=WPMU_PLUGIN_DIR.'/spc-lead-and-call-tracker.php'; copy(WP_CONTENT_DIR.'/%s',$f); echo json_encode(array('restored'=>md5_file($f)));""" % BK)); sys.exit(1)
print("OK")
