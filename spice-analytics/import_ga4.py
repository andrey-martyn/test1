import argparse
import json
from pathlib import Path
from ga4 import import_csv
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('file',type=Path);p.add_argument('--kind',choices=['daily','transactions'],required=True)
    p.add_argument('--currency',default='UAH');p.add_argument('--database',type=Path,default=Path(__file__).parent/'data/analytics.sqlite3')
    a=p.parse_args();print(json.dumps(import_csv(a.database,a.file.read_text(encoding='utf-8-sig'),a.kind,a.currency),ensure_ascii=False))
