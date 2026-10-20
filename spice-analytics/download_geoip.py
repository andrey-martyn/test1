"""Download DB-IP's public monthly City Lite database; no order/IP data is transmitted."""
from datetime import date
import argparse
from pathlib import Path
import tempfile
from urllib.request import urlopen
from import_geoip import install

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--month',default=date.today().strftime('%Y-%m'))
    p.add_argument('--destination',type=Path,default=Path(__file__).parent/'data/geoip-city.mmdb')
    args=p.parse_args()
    # Validate before constructing an authoritative, fixed-host URL.
    date.fromisoformat(args.month+'-01')
    url=f'https://download.db-ip.com/free/dbip-city-lite-{args.month}.mmdb.gz'
    with tempfile.TemporaryDirectory() as d:
        path=Path(d)/'city.mmdb.gz'
        with urlopen(url,timeout=60) as response,path.open('wb') as out:
            size=0
            while block:=response.read(1024*1024):
                size+=len(block)
                if size>1024*1024*1024:raise ValueError('Неожиданно большой файл')
                out.write(block)
        result=install(path,args.destination)
    print(f"City database installed and validated. Build epoch: {result['build_epoch']}. Attribution: DB-IP.com, CC BY 4.0.")
