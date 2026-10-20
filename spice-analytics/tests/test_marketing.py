from collections import defaultdict
from datetime import date
from pathlib import Path
import sqlite3
import sys
import tempfile
from types import SimpleNamespace
import unittest

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
import marketing
import ga4
from geo import GeoResolver
from import_geoip import install

class RetentionTests(unittest.TestCase):
    def data(self):
        groups={};customers=[];lines=defaultdict(list);orders=[]
        for cid,days in [('1',['2026-08-01','2026-08-11','2026-10-01']),('2',['2026-10-10']),('3',['2026-09-01','2026-10-15'])]:
            items=[]
            for i,d in enumerate(days):
                oid=cid+str(i)
                o={'order_id':oid,'date_added':d+' 12:00:00','total':'100','currency_value':'1'}
                items.append(o);orders.append(o)
                for pid in ('A','B'):
                    lines[oid].append({'product_id':pid,'name':'Product '+pid,'total':'50','quantity':'1','pack':{'label':'50 г'}})
            groups[cid]=items
            customers.append({'id':cid,'lifetime_count':len(days),'lifetime_amount':100*len(days),
              'days_since':(date(2026,10,20)-date.fromisoformat(days[-1])).days,'interval_days':30,'reminder':'ok',
              'followup_eligible':len(days)>1,'order_count':len(days),'amount':100*len(days),'order_ids':[o['order_id'] for o in items]})
        return SimpleNamespace(lines=lines),customers,groups,orders

    def test_matured_denominators_and_future_cohorts(self):
        s,customers,groups,orders=self.data();r=marketing.build(s,customers,groups,orders,date(2026,10,20))
        conversion={r['window']:r for r in r['conversion']}
        self.assertEqual(conversion[30]['eligible'],2)
        self.assertEqual(conversion[30]['returned'],1)
        self.assertEqual(conversion[30]['rate'],50)
        self.assertEqual(conversion[60]['eligible'],1)
        self.assertEqual(conversion[60]['rate'],100)
        self.assertEqual(conversion[90]['eligible'],0)
        self.assertIsNone(conversion[90]['rate'])
        august=next(c for c in r['cohorts'] if c['month']=='2026-08')
        self.assertEqual(august['cells'][1]['rate'],0)
        self.assertEqual(august['cells'][2]['rate'],100)
        self.assertTrue(august['cells'][2]['partial'])
        self.assertIsNone(august['cells'][3]['rate'])
        self.assertTrue(august['cells'][3]['future'])

    def test_repeat_sums_pairs_and_time_heatmap(self):
        s,customers,groups,orders=self.data();r=marketing.build(s,customers,groups,orders,date(2026,10,20))
        self.assertEqual(r['first_amount'],300)
        self.assertEqual(r['repeat_amount'],300)
        self.assertEqual(r['repeat_amount_share'],50)
        self.assertEqual(sum(sum(day) for day in r['hours']),6)
        self.assertEqual(r['affinities'][0]['orders'],6)
        self.assertEqual(r['affinities'][0]['confidence_a'],100)
        self.assertEqual(r['affinities'][0]['lift'],1)
        self.assertEqual(sum(s['count'] for s in r['segments']),3)
        self.assertEqual(sum(c['count'] for c in r['rfm_matrix']),3)
        self.assertEqual(sum(p['amount'] for p in r['abc']),600)
        self.assertEqual(r['queue'],[])

class GeoTests(unittest.TestCase):
    def test_real_mmdb_decoder_local_only(self):
        with tempfile.TemporaryDirectory() as t:
            destination=Path(t)/'geoip-city.mmdb'
            install(ROOT/'tests/fixtures/GeoIP2-City-Test.mmdb',destination)
            geo=GeoResolver(t)
            self.assertTrue(geo.metadata()['available'])
            result=geo.lookup('81.2.69.160')
            self.assertEqual(result['country_code'],'GB')
            self.assertIn(result['city'],('Лондон','London'))
            self.assertIsNone(geo.lookup('127.0.0.1'))
            self.assertIsNone(geo.lookup('not an IP'))
            order={'ip':'81.2.69.160','shipping_city':'Львів','shipping_zone':'Львівська область','shipping_country':'Україна'}
            self.assertEqual(geo.resolve(order,'mixed')['zone'],'Львівська область')
            order['shipping_zone']=''
            self.assertEqual(geo.resolve(order,'mixed')['country_code'],'GB')
            self.assertEqual(geo.resolve(order,'address')['country_code'],'UA')

    def test_no_database_does_not_invent_regions(self):
        with tempfile.TemporaryDirectory() as t:
            geo=GeoResolver(t)
            self.assertFalse(geo.metadata()['available'])
            self.assertIsNone(geo.lookup('81.2.69.160'))
            self.assertEqual(geo.resolve({'ip':'81.2.69.160','shipping_country':'Україна'},'mixed')['zone'],'')

class GA4Tests(unittest.TestCase):
    def setUp(self):
        self.t=tempfile.TemporaryDirectory();self.db=Path(self.t.name)/'db.sqlite3'
    def tearDown(self):self.t.cleanup()

    def test_optional_metrics_currency_and_repeat_import(self):
        text='# GA4 export\ndate,sessionDefaultChannelGroup,sessions,activeUsers,ecommercePurchases,purchaseRevenue,adCost,currencyCode\n20261019,Organic Search,100,80,1,1200,,UAH\n20261020,Organic Search,150,120,2,2400,,UAH\n20261020,Paid Search,10,8,1,100,25,USD\n'
        ga4.import_csv(self.db,text,'daily','UAH');ga4.import_csv(self.db,text,'daily','UAH')
        r=ga4.report(self.db,2026,date(2026,10,20),'UAH',[])
        self.assertEqual(r['daily_rows'],2)
        self.assertEqual(r['totals']['sessions'],250)
        self.assertEqual(r['totals']['revenue'],3600)
        self.assertIsNone(r['totals']['cost'])
        self.assertIsNone(r['channels'][0]['roas'])
        self.assertIsNone(r['channels'][0]['carts'])

    def test_transactions_match_ids_and_duplicates_are_atomic(self):
        text='date,transactionId,purchaseRevenue,currencyCode\n2026-10-19,#4923,3510,UAH\n2026-10-19,9999,100,UAH\n'
        ga4.import_csv(self.db,text,'transactions','UAH')
        with self.assertRaises(ValueError):ga4.import_csv(self.db,text+'2026-10-20,4923,3510,UAH\n','transactions','UAH')
        orders=[{'order_id':'4923','total':'3510','currency_value':'1','date_added':'2026-10-19 12:00:00'},
                {'order_id':'4924','total':'765','currency_value':'1','date_added':'2026-10-19 13:00:00'}]
        r=ga4.report(self.db,2026,date(2026,10,20),'UAH',orders)
        self.assertEqual(len(r['matched']),1)
        self.assertEqual(r['matched'][0]['difference'],0)
        self.assertEqual(r['ga_only'],['9999'])
        self.assertEqual(r['oc_only'],['4924'])

    def test_invalid_numeric_data_is_rejected(self):
        for value in ('NaN','Infinity','-1','1.5'):
            with self.assertRaises(ValueError):ga4.import_csv(self.db,f'date,channel,sessions\n20261019,Direct,{value}\n','daily','UAH')

if __name__=='__main__':unittest.main(verbosity=2)
