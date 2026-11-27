#!/usr/bin/env python3
"""Build the installable plugin, excluding development fixtures and tooling."""
from pathlib import Path
import argparse,zipfile
p=argparse.ArgumentParser();p.add_argument('output',type=Path);a=p.parse_args();root=Path(__file__).resolve().parents[1]
files=['belger-mieterportal.php','readme.txt','README.md','SECURITY.md','LICENSE','THIRD-PARTY-NOTICES.txt','CHANGELOG.md']
for directory in ['includes','assets','languages']:
 files += [str(x.relative_to(root)) for x in (root/directory).rglob('*') if x.is_file()]
a.output.parent.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(a.output,'w',zipfile.ZIP_DEFLATED) as z:
 for name in sorted(files):z.write(root/name,'openmieterportal/'+name)
print(a.output)
