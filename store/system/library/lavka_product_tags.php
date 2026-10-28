<?php
/** Shared marketing labels. No product identifiers, prices or dates are written here. */
class LavkaProductTags {
    const TYPES = ['new', 'hit', 'fresh', 'brand', 'exclusive'];
    const MANUAL = ['fresh', 'brand', 'exclusive'];
    private $registry;
    private $db;
    private $store;
    private $native;
    private $definitions;
    private $flags;
    private $sales;
    private $salesConfigured = false;

    public function __construct($registry) {
        $this->registry = $registry;
        $this->db = $registry->get('db');
        $this->store = (int)$registry->get('config')->get('config_store_id');
    }

    public static function color($value, $fallback = null) {
        if (!is_string($value)) return $fallback;
        $value = ltrim(trim($value), '#');
        if (preg_match('/^[a-f0-9]{3}$/iD', $value)) $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        return preg_match('/^[a-f0-9]{6}$/iD', $value) ? '#'.strtolower($value) : $fallback;
    }

    public static function schema($prefix) {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) throw new InvalidArgumentException('Invalid prefix');
        return [
            "CREATE TABLE IF NOT EXISTS `".$prefix."lavka_tag_definition` (`store_id` INT NOT NULL, `code` VARCHAR(16) NOT NULL, `enabled` TINYINT NOT NULL, `label_ru` VARCHAR(80) NOT NULL, `label_uk` VARCHAR(80) NOT NULL, `background` CHAR(7) NOT NULL, `foreground` CHAR(7) NOT NULL, `sort_order` INT NOT NULL, `days` INT NOT NULL DEFAULT 0, `revision` INT UNSIGNED NOT NULL DEFAULT 1, `min_sales` INT UNSIGNED NOT NULL DEFAULT 11, PRIMARY KEY (`store_id`,`code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `".$prefix."lavka_product_tag` (`store_id` INT NOT NULL, `product_id` INT NOT NULL, `code` VARCHAR(16) NOT NULL, `enabled` TINYINT NOT NULL, `revision` INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (`store_id`,`product_id`,`code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];
    }

    public static function upgradeSql($prefix) {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) throw new InvalidArgumentException('Invalid prefix');
        return "ALTER TABLE `".$prefix."lavka_tag_definition` ADD COLUMN `min_sales` INT UNSIGNED NOT NULL DEFAULT 11";
    }

    public function nativeSettings() {
        if ($this->native === null) {
            // Never expose this private JSON; only label/color switches are used in responses.
            $q = $this->db->query("SELECT `data` FROM `".DB_PREFIX."uni_setting` WHERE `store_id`=".$this->store." LIMIT 2");
            if (count($q->rows) !== 1) throw new RuntimeException('Native settings row is not unique');
            $data = json_decode($q->row['data'], true);
            if (!is_array($data)) throw new RuntimeException('Invalid native settings');
            $this->native = $data;
        }
        return $this->native;
    }

    public function defaults() {
        $u = $this->nativeSettings();
        $labels = [
            'new'=>['Новинка','Новинка','#7c9b42'],
            'hit'=>['Топ продаж','Топ продажів','#bb8a26'],
            'fresh'=>['Свежий помол','Свіжий помел','#4f7f28'],
            'brand'=>['Наша марка','Наша марка','#63745e'],
            'exclusive'=>['Эксклюзив','Ексклюзив','#8c3445']
        ];
        $out = [];
        foreach ($labels as $code=>$label) {
            $old = $code === 'hit' ? (isset($u['mpn_as_sticker']) ? 'mpn' : 'bestseller') : $code;
            $out[$code] = ['code'=>$code,'enabled'=>$code === 'new' ? (int)isset($u['sticker_new']) : 1,
                'label_ru'=>$label[0],'label_uk'=>$label[1],
                'background'=>self::color(isset($u['sticker_'.$old.'_background_color']) ? $u['sticker_'.$old.'_background_color'] : '',$label[2]),
                'foreground'=>self::color(isset($u['sticker_'.$old.'_text_color']) ? $u['sticker_'.$old.'_text_color'] : '','#ffffff'),
                'sort_order'=>(array_search($code,self::TYPES,true)+1)*10,
                'days'=>$code === 'new' ? max(0,min(3650,isset($u['sticker_new_date'])?(int)$u['sticker_new_date']:30)) : 0,
                'min_sales'=>$code === 'hit' ? 11 : 0,'revision'=>1];
        }
        return $out;
    }

    public function definitions() {
        if ($this->definitions === null) {
            $q = $this->db->query("SELECT * FROM `".DB_PREFIX."lavka_tag_definition` WHERE `store_id`=".$this->store);
            $this->definitions = [];
            foreach ($q->rows as $row) if (in_array($row['code'],self::TYPES,true)) {
                $row['min_sales']=$row['code'] === 'hit' ? (isset($row['min_sales']) ? max(1,(int)$row['min_sales']) : 11) : 0;
                $this->definitions[$row['code']] = $row;
            }
            if (count($this->definitions) !== 5) throw new RuntimeException('Tag definitions are not initialized');
            $ordered=[];
            foreach (self::TYPES as $code) $ordered[$code]=$this->definitions[$code];
            $this->definitions=$ordered;
        }
        return $this->definitions;
    }

    public function initialize() {
        foreach (self::schema(DB_PREFIX) as $sql) $this->db->query($sql);
        foreach ($this->defaults() as $row) {
            $sets = $this->definitionSets($row);
            $this->db->query("INSERT IGNORE INTO `".DB_PREFIX."lavka_tag_definition` SET `store_id`=".$this->store.", `code`='".$row['code']."', ".$sets.", `revision`=1");
        }
        return $this->definitions();
    }

    private function definitionSets($row) {
        $parts = [];
        foreach (['label_ru','label_uk','background','foreground'] as $key) $parts[] = '`'.$key."`='".$this->db->escape($row[$key])."'";
        foreach (['enabled','sort_order','days','min_sales'] as $key) $parts[] = '`'.$key.'`='.(int)$row[$key];
        return implode(', ',$parts);
    }

    public function saveDefinitions($input) {
        if (!is_array($input) || array_diff(self::TYPES,array_keys($input)) || array_diff(array_keys($input),self::TYPES)) throw new InvalidArgumentException('Invalid tag types');
        $validated = [];
        foreach (self::TYPES as $code) {
            $row = $input[$code];
            if (!is_array($row)) throw new InvalidArgumentException('Invalid definition');
            foreach (['label_ru','label_uk'] as $key) {
                if (!isset($row[$key]) || !is_string($row[$key])) throw new InvalidArgumentException('Missing label');
                $row[$key] = trim(html_entity_decode($row[$key],ENT_QUOTES,'UTF-8'));
                if (!preg_match('/^[^<>\p{C}]{1,40}$/uD',$row[$key])) throw new InvalidArgumentException('Invalid label');
            }
            foreach (['background','foreground'] as $key) if (($row[$key] = self::color(isset($row[$key]) ? $row[$key] : null)) === null) throw new InvalidArgumentException('Invalid color');
            foreach (['enabled','sort_order','days','revision','min_sales'] as $key) {
                if (!isset($row[$key]) || !is_scalar($row[$key]) || !preg_match('/^\d{1,10}$/D',(string)$row[$key])) throw new InvalidArgumentException('Invalid numeric value');
                $row[$key] = (int)$row[$key];
            }
            if ($row['enabled'] > 1 || $row['sort_order'] > 999 || $row['days'] > 3650 || $row['revision'] < 1) throw new InvalidArgumentException('Value outside allowed range');
            if ($code !== 'new') $row['days'] = 0;
            if ($code === 'hit' && ($row['min_sales'] < 1 || $row['min_sales'] > 1000000)) throw new InvalidArgumentException('Invalid sales threshold');
            if ($code !== 'hit') $row['min_sales'] = 0;
            $validated[$code] = $row;
        }
        $this->db->query('START TRANSACTION');
        try {
            foreach ($validated as $code=>$row) {
                $this->db->query("UPDATE `".DB_PREFIX."lavka_tag_definition` SET ".$this->definitionSets($row).", `revision`=`revision`+1 WHERE `store_id`=".$this->store." AND `code`='".$code."' AND `revision`=".$row['revision']);
                if ($this->db->countAffected() !== 1) throw new RuntimeException('TAG_CONFLICT');
            }
            $this->db->query('COMMIT');
        } catch (Exception $e) { $this->db->query('ROLLBACK'); throw $e; }
        $this->definitions = null;
        return $this->definitions();
    }

    private function flags() {
        if ($this->flags === null) {
            $q = $this->db->query("SELECT `product_id`,`code`,`enabled`,`revision` FROM `".DB_PREFIX."lavka_product_tag` WHERE `store_id`=".$this->store);
            $this->flags = [];
            foreach ($q->rows as $row) if (in_array($row['code'],self::MANUAL,true)) $this->flags[(int)$row['product_id']][$row['code']] = $row;
        }
        return $this->flags;
    }

    private function sales() {
        if ($this->sales !== null) return $this->sales;
        $this->sales=[];
        $configured=$this->registry->get('config')->get('config_complete_status');
        $statuses=[];
        foreach (is_array($configured) ? $configured : [] as $id) if (is_scalar($id) && preg_match('/^[1-9][0-9]{0,8}$/D',(string)$id)) $statuses[]=(int)$id;
        $statuses=array_values(array_unique($statuses)); sort($statuses,SORT_NUMERIC);
        $this->salesConfigured=(bool)$statuses;
        // Empty completion settings must never include pending/cancelled orders by fallback.
        if (!$statuses) return $this->sales;
        $key='lavka.tags.sales.'.$this->store;
        $signature=implode(',',$statuses);
        $cached=$this->registry->get('cache')->get($key);
        if (is_array($cached) && isset($cached['statuses'],$cached['expires'],$cached['totals']) && $cached['statuses']===$signature && $cached['expires']>time() && is_array($cached['totals'])) return $this->sales=$cached['totals'];
        $q=$this->db->query("SELECT op.product_id, SUM(op.quantity) AS total FROM `".DB_PREFIX."order_product` op INNER JOIN `".DB_PREFIX."order` o ON op.order_id=o.order_id WHERE o.store_id=".$this->store." AND o.order_status_id IN (".$signature.") AND op.quantity>0 GROUP BY op.product_id");
        foreach ($q->rows as $row) $this->sales[(int)$row['product_id']]=(int)$row['total'];
        $this->registry->get('cache')->set($key,['statuses'=>$signature,'expires'=>time()+1800,'totals'=>$this->sales]);
        return $this->sales;
    }

    public function state($product, $now = null) {
        $now = $now === null ? time() : $now;
        $definitions = $this->definitions();
        $flags = $this->flags();
        $id = (int)$product['product_id'];
        $start = isset($product['date_available']) ? strtotime($product['date_available']) : false;
        $end = $start === false ? false : $start+(int)$definitions['new']['days']*86400;
        $out = ['new'=>['enabled'=>$start !== false && $start <= $now && $end >= $now,'until'=>$end ? date('Y-m-d',$end) : '']];
        $sales=$id > 0 ? $this->sales() : [];
        $sold=isset($sales[$id]) ? (int)$sales[$id] : 0;
        $out['hit']=['enabled'=>$sold >= (int)$definitions['hit']['min_sales'],'sales'=>$sold,'min_sales'=>(int)$definitions['hit']['min_sales'],'configured'=>$this->salesConfigured,'automatic'=>true,'revision'=>0,'inherited'=>false];
        foreach (self::MANUAL as $code) {
            $row = isset($flags[$id][$code]) ? $flags[$id][$code] : null;
            $out[$code] = ['enabled'=>$row ? (bool)$row['enabled'] : false,
                          'revision'=>$row ? (int)$row['revision'] : 0,'inherited'=>false];
        }
        return $out;
    }

    public function writeFlag($product_id, $code, $enabled, $revision) {
        if (!is_int($product_id) || $product_id < 1 || !in_array($code,self::MANUAL,true) || !in_array($enabled,[0,1],true) || !is_int($revision) || $revision < 0) throw new InvalidArgumentException('Invalid flag change');
        $q = $this->db->query("SELECT * FROM `".DB_PREFIX."product` WHERE product_id=".$product_id." LIMIT 1");
        if (!$q->num_rows) throw new InvalidArgumentException('Product not found');
        $product = $q->row;
        if ($revision === 0) {
            $this->db->query("INSERT IGNORE INTO `".DB_PREFIX."lavka_product_tag` SET store_id=".$this->store.", product_id=".$product_id.", code='".$code."', enabled=".$enabled.", revision=1");
        } else {
            $this->db->query("UPDATE `".DB_PREFIX."lavka_product_tag` SET enabled=".$enabled.",revision=revision+1 WHERE store_id=".$this->store." AND product_id=".$product_id." AND code='".$code."' AND revision=".$revision);
        }
        if ($this->db->countAffected() !== 1) throw new RuntimeException('TAG_CONFLICT');
        $this->flags = null;
        $this->registry->get('cache')->delete('product');
        $this->registry->get('cache')->delete('product.unishop');
        return $this->state($product);
    }

    public function stickers($product, $legacy = []) {
        $definitions = $this->definitions();
        $state = $this->state($product);
        $u = $this->nativeSettings();
        $language = (string)$this->registry->get('config')->get('config_language');
        if ($this->registry->has('session') && isset($this->registry->get('session')->data['language'])) $language = (string)$this->registry->get('session')->data['language'];
        $label = strpos($language,'uk') === 0 || strpos($language,'ua') === 0 ? 'label_uk' : 'label_ru';
        $out = [];
        foreach (self::TYPES as $code) {
            $d = $definitions[$code];
            if (!$d['enabled'] || !$state[$code]['enabled']) continue;
            $out[] = ['name'=>'lavka-'.$code,'text'=>$d[$label],'value'=>'','text_after'=>'',
                      'background'=>$d['background'],'color'=>$d['foreground'],'length'=>strlen($d[$label]),'order'=>(int)$d['sort_order']];
        }
        foreach ($legacy as $sticker) {
            if (in_array($sticker['name'],['new','bestseller','sku','upc','ean','jan','isbn','mpn'],true)) continue;
            $out[] = $this->legacyColor($sticker,$u);
        }
        foreach (['sku','upc','ean','jan','isbn','mpn'] as $index=>$code) {
            if (!isset($u[$code.'_as_sticker']) || !isset($product[$code]) || trim((string)$product[$code]) === '') continue;
            // Old marketing MPN cannot bypass the automatic sales criterion; code is untouched.
            if ($code === 'mpn' && in_array(trim($product[$code]),['Популярно','Хит продаж','Хіт продажів'],true)) continue;
            $out[] = $this->legacyColor(['name'=>$code,'text'=>$product[$code],'value'=>'','text_after'=>'','order'=>200+$index],$u);
        }
        usort($out,function($a,$b) { $cmp=$a['order'] <=> $b['order']; return $cmp ?: strcmp($a['name'],$b['name']); });
        return $out;
    }

    private function legacyColor($sticker,$u) {
        foreach (['text','value','text_after'] as $field) if (isset($sticker[$field]) && is_string($sticker[$field])) $sticker[$field]=html_entity_decode($sticker[$field],ENT_QUOTES,'UTF-8');
        foreach (['background'=>'background','color'=>'text'] as $output=>$key) {
            $input = 'sticker_'.$sticker['name'].'_'.$key.'_color';
            $sticker[$output] = self::color(isset($u[$input]) ? $u[$input] : null);
        }
        if (!isset($sticker['order'])) $sticker['order'] = 150;
        if (!isset($sticker['length'])) $sticker['length'] = strlen($sticker['text']);
        return $sticker;
    }
}
