#!/usr/bin/env python3
"""Read/export one active test product template outside webroot; never update DB."""
import datetime
import hashlib
import json
import os
import subprocess

import test_store as store

PHP_READ = r'''
$c = json_decode(stream_get_contents(STDIN), true);
function template_fail($code) { echo json_encode(array('ok'=>false,'code'=>$code)); exit(1); }
if (!is_array($c) || $c['DB_DATABASE'] !== 'lavkasp_test' || !preg_match('/^[a-zA-Z0-9_]*$/', $c['DB_PREFIX']) || !isset($c['template_id']) || !is_int($c['template_id']) || $c['template_id'] < 1) template_fail('INVALID_TEMPLATE_TARGET');
if (!extension_loaded('mysqli')) template_fail('MYSQLI_UNAVAILABLE');
mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_init(); $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
try {
    if (!@$db->real_connect($c['DB_HOSTNAME'], $c['DB_USERNAME'], $c['DB_PASSWORD'], $c['DB_DATABASE'], (int)$c['DB_PORT'])) template_fail('CONNECT_FAILED');
    if (!$db->set_charset('utf8mb4')) template_fail('CHARSET_FAILED');
    $r = $db->query('SELECT DATABASE() AS selected_database');
    if (!$r || $r->fetch_assoc()['selected_database'] !== 'lavkasp_test') template_fail('DATABASE_IDENTITY_MISMATCH');
    $r = $db->query("SELECT theme_id, OCTET_LENGTH(code) AS stored_bytes, CASE WHEN OCTET_LENGTH(code) <= 2097152 THEN code ELSE NULL END AS source_code FROM `".$c['DB_PREFIX']."theme` WHERE store_id = 0 AND theme = 'unishop2' AND route = 'product/product' AND theme_id = ".$c['template_id']." LIMIT 2");
    if (!$r || $r->num_rows !== 1) template_fail('UNIQUE_ACTIVE_TEMPLATE_NOT_FOUND');
    $row = $r->fetch_assoc();
    if (!is_string($row['source_code'])) template_fail('TEMPLATE_SIZE_UNSUPPORTED');
    $source = html_entity_decode($row['source_code'], ENT_QUOTES, 'UTF-8');
    if (hash('sha256', $source) !== $c['expected_sha256']) template_fail('ACTIVE_TEMPLATE_CHANGED');
    $db->close();
    echo json_encode(array('ok'=>true,'source'=>$source,'sha256'=>hash('sha256',$source)));
} catch (Exception $e) { template_fail('TEMPLATE_READ_FAILED'); }
'''


def read_template(diagnostic):
    check=diagnostic['template_check'];scope=check.get('scope') or {};records=check.get('records') or []
    if check.get('count')!=1 or len(records)!=1 or scope!={'store_id':0,'theme':'unishop2','route':'product/product'}:
        raise ValueError('A single active test template must be verified first')
    record=records[0]
    if record.get('source_bounded') is not True or type(record.get('theme_id')) is not int or record['theme_id']<1:
        raise ValueError('Unsupported active template')
    config=store.config_values(store.SHOP/'config.php');config.setdefault('DB_PORT','3306')
    config.update({'template_id':record['theme_id'],'expected_sha256':record['decoded_sha256']})
    result=subprocess.run(['php','-d','display_errors=0','-d','log_errors=0','-r',PHP_READ],input=json.dumps(config),universal_newlines=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=15)
    payload=json.loads(result.stdout)
    if result.returncode or payload.get('ok') is not True or set(payload)!={'ok','source','sha256'} or not isinstance(payload['source'],str):
        raise ValueError('Active template read failed')
    if payload['sha256']!=record['decoded_sha256'] or hashlib.sha256(payload['source'].encode('utf-8')).hexdigest()!=record['decoded_sha256']:
        raise ValueError('Active source changed')
    return payload['source']


def export_template(diagnostic):
    source=read_template(diagnostic)
    if store.BACKUPS.resolve()!=store.BACKUPS or store.within(store.BACKUPS,store.SHOP) or store.within(store.BACKUPS,store.STORAGE):
        raise ValueError('Unsafe review directory')
    identifier=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ')
    directory=store.BACKUPS/('product-template-review-'+identifier);directory.mkdir(parents=True,mode=0o700)
    destination=directory/'active-product.twig'
    with os.fdopen(os.open(str(destination),os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600),'wb') as handle:handle.write(source.encode('utf-8'))
    return {'exported':str(destination),'sha256':hashlib.sha256(source.encode('utf-8')).hexdigest(),'shop_writes':False,'database_writes':False,'contents':'Effective active product template only; no settings or customer rows.'}
