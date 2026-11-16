#!/usr/bin/env python3
"""Push Search Console (UK) query x page x date rows into the site's {prefix}spc_gsc_queries table, so the lead
tracker can show 'likely searches' for Google SEO leads. usage: push_gsc.py [days=90]  (run daily; GSC lags 2-3 days)"""
import sys, json, base64, datetime, urllib.parse, io, contextlib
sys.path.insert(0, "/home/mundo/seo-work/daily"); sys.path.insert(0, "/home/mundo/seo-work/borough")
import run
days = int(sys.argv[1]) if len(sys.argv) > 1 else 90
sys.argv = ["x", "none"]
with contextlib.redirect_stdout(io.StringIO()):
    import ga4 as g
SITE = "sc-domain:smartpropertychecks.com"
U = "https://www.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query" % urllib.parse.quote(SITE, safe="")
end = datetime.date.today() - datetime.timedelta(days=1)
start = end - datetime.timedelta(days=days)
rows, s = [], 0
while True:
    q = g.call(U, {"startDate": str(start), "endDate": str(end), "dimensions": ["date", "query", "page"], "rowLimit": 25000, "startRow": s,
                   "dimensionFilterGroups": [{"filters": [{"dimension": "country", "expression": "gbr"}]}]})
    r = q.get("rows", []) if isinstance(q, dict) else []
    rows += r
    if len(r) < 25000: break
    s += 25000
data = [[r["keys"][0], r["keys"][2].split("#")[0][:255], r["keys"][1][:255], int(r["clicks"]), int(r["impressions"]), round(float(r["position"]), 2)] for r in rows]
first = min((x[0] for x in data), default=str(start))
print("rows from GSC:", len(data), "dates", first, "->", max((x[0] for x in data), default="-"))
print(run.php(r"""global $wpdb; $t=$wpdb->prefix.'spc_gsc_queries'; echo json_encode(array('deleted'=>$wpdb->query($wpdb->prepare("DELETE FROM $t WHERE d >= %%s", '%s'))));""" % first))
ins = 0
for i in range(0, len(data), 700):
    chunk = base64.b64encode(json.dumps(data[i:i + 700]).encode()).decode()
    r = run.php(r"""global $wpdb; $t=$wpdb->prefix.'spc_gsc_queries'; $rows=json_decode(base64_decode('%s'),true); $vals=array(); $ph=array();
    foreach($rows as $x){ $ph[]='(%%s,%%s,%%s,%%d,%%d,%%f)'; array_push($vals,$x[0],$x[1],$x[2],$x[3],$x[4],$x[5]); }
    $n=$wpdb->query($wpdb->prepare("INSERT INTO $t (d,page,query,clicks,impressions,position) VALUES ".implode(',',$ph), $vals)); echo json_encode(array('n'=>$n));""" % chunk)
    ins += int((r or {}).get("n") or 0)
print("inserted", ins)
print(run.php(r"""global $wpdb; $t=$wpdb->prefix.'spc_gsc_queries'; $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE d < %%s", '%s')); echo json_encode(array('total'=>$wpdb->get_var("SELECT COUNT(*) FROM $t")));""" % (end - datetime.timedelta(days=400))))
