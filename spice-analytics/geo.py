"""Local GeoIP only: customer IPs never leave this machine."""
from functools import lru_cache
import ipaddress
from pathlib import Path

class GeoResolver:
    def __init__(self, data_dir):
        self.path=Path(data_dir)/'geoip-city.mmdb'
        self.reader=None
        self.error=None
        if self.path.exists():
            try:
                import maxminddb
                self.reader=maxminddb.open_database(str(self.path))
            except (ImportError,ValueError,OSError) as e:
                self.error=type(e).__name__

    def metadata(self):
        return {'available':self.reader is not None,'database':'GeoIP City / local MMDB',
                'error':self.error,'provider':'DB-IP Lite / MaxMind-compatible',
                'note':'Приблизительное местоположение сети на дату геобазы; не адрес доставки.'}

    @lru_cache(maxsize=20000)
    def lookup(self, raw_ip):
        try:
            ip=ipaddress.ip_address((raw_ip or '').strip())
            if not ip.is_global:return None
        except ValueError:return None
        if self.reader is None:return None
        record=self.reader.get(str(ip))
        if not record:return None
        country=record.get('country',{})
        code=country.get('iso_code','')
        sub=(record.get('subdivisions') or [{}])[0]
        def label(item):
            names=item.get('names',{})
            return names.get('uk') or names.get('ru') or names.get('en') or ''
        return {'city':label(record.get('city',{})),'zone':label(sub),
                'zone_code':f"{code}-{sub['iso_code']}" if code and sub.get('iso_code') else '',
                'country':label(country),'country_code':code,'source':'IP · приблизительно'}

    def address(self,o):
        shipping=bool((o.get('shipping_city') or o.get('shipping_zone') or '').strip())
        prefix='shipping_' if shipping else 'payment_'
        country=o.get(prefix+'country') or o.get('shipping_country') or o.get('payment_country') or ''
        return {'city':o.get(prefix+'city') or 'Город не указан','zone':o.get(prefix+'zone') or '',
                'zone_code':'','country':country,'country_code':'UA' if country.casefold() in ('україна','украина','ukraine') else '',
                'source':'Адрес доставки' if shipping else 'Адрес оплаты'}

    def resolve(self,o,mode='mixed'):
        address=self.address(o)
        if mode=='address':return address
        ip=self.lookup(o.get('ip',''))
        if mode=='ip':
            return ip or {'city':'IP не определён','zone':'','zone_code':'','country':'Не определена','country_code':'','source':'IP · нет результата'}
        # Do not mix a delivery country with a guessed region from another country.
        # A partial address stays available through the separate address mode.
        return address if address['zone'] else ip or address
