"""Validate a user-supplied City MMDB and atomically install it locally."""
import argparse
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import tempfile
import maxminddb

def install(source,destination):
    destination.parent.mkdir(parents=True,exist_ok=True)
    fd,name=tempfile.mkstemp(dir=destination.parent,suffix='.mmdb')
    os.close(fd);temporary=Path(name)
    try:
        opener=gzip.open if source.suffix=='.gz' else open
        with opener(source,'rb') as incoming,temporary.open('wb') as out:
            shutil.copyfileobj(incoming,out)
        with maxminddb.open_database(str(temporary)) as reader:
            metadata=reader.metadata()
            if 'city' not in metadata.database_type.casefold():
                raise ValueError('Нужна база уровня City с регионами, а не только Country')
            summary={'database_type':metadata.database_type,'build_epoch':metadata.build_epoch,
                     'sha256':hashlib.sha256(temporary.read_bytes()).hexdigest()}
        temporary.chmod(0o600);temporary.replace(destination)
        destination.with_suffix('.metadata.json').write_text(json.dumps(summary))
        return summary
    finally:
        temporary.unlink(missing_ok=True)

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('file',type=Path)
    p.add_argument('--destination',type=Path,default=Path(__file__).parent/'data'/'geoip-city.mmdb')
    a=p.parse_args();print(json.dumps(install(a.file,a.destination),indent=2))
