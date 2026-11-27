#!/usr/bin/env python3
"""Keep all PCP findings visible and reject errors or new/unreviewed warnings."""
from pathlib import Path
import argparse,collections,json,re
p=argparse.ArgumentParser();p.add_argument('results',type=Path);p.add_argument('--baseline',type=Path,required=True);p.add_argument('--json-output',type=Path);a=p.parse_args()
raw=a.results.read_text();findings=[]
for m in re.finditer(r'^FILE: (.+)\n',raw,re.M):
    rows,_=json.JSONDecoder().raw_decode(raw[m.end():])
    findings.extend(dict(file=m[1],**row) for row in rows)
if not findings and not ('No errors found' in raw or 'Success:' in raw):raise SystemExit('No recognized Plugin Check result; refusing to report success.')
errors=[r for r in findings if r['type']=='ERROR'];warnings=collections.Counter(r['code'] for r in findings if r['type']=='WARNING')
baseline=json.loads(a.baseline.read_text());unreviewed={code:count for code,count in warnings.items() if count>baseline['reviewed_warning_limits'].get(code,{}).get('maximum',0)}
report={'checker':baseline['checker'],'errors':len(errors),'warnings':sum(warnings.values()),'warning_codes':dict(warnings),'unreviewed_warning_codes':unreviewed,'findings':findings}
if a.json_output:a.json_output.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({k:v for k,v in report.items() if k!='findings'},ensure_ascii=False,indent=2))
raise SystemExit(1 if errors or unreviewed else 0)
