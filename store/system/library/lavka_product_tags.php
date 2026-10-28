<?php
/** Shared marketing labels. No product identifiers, prices or dates are written here. */
class LavkaProductTags {
    const TYPES = ['new', 'hit', 'fresh', 'brand', 'exclusive'];
    const MANUAL = ['hit', 'fresh', 'brand', 'exclusive'];
    private $registry;
    private $db;
    private $store;
    private $native;
    private $definitions;
    private $flags;
    private $best;

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
            "CREATE TABLE IF NOT EXISTS `".$prefix."lavka_tag_definition` (`store_id` INT NOT NULL, `code` VARCHAR(16) NOT NULL, `enabled` TINYINT NOT NULL, `label_ru` VARCHAR(80) NOT NULL, `label_uk` VARCHAR(80) NOT NULL, `background` CHAR(7) NOT NULL, `foreground` CHAR(7) NOT NULL, `sort_order` INT NOT NULL, `days` INT NOT NULL DEFAULT 0, `revision` INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (`store_id`,`code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `".$prefix."lavka_product_tag` (`store_id` INT NOT NULL, `product_id` INT NOT NULL, `code` VARCHAR(16) NOT NULL, `enabled` TINYINT NOT NULL, `revision` INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (`store_id`,`product_id`,`code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];
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
            'hit'=>['Хит продаж','Хіт продажів','#bb8a26'],
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
                'days'=>$code === 'new' ? max(0,min(3650,isset($u['sticker_new_date'])?(int)$u['sticker_new_date']:30)) : 0,'revision'=>1];
        }
        return $out;
    }

    public function definitions() {
        if ($this->definitions === null) {
            $q = $this->db->query("SELECT * FROM `".DB_PREFIX."lavka_tag_definition` WHERE `store_id`=".$this->store);
            $this->definitions = [];
            foreach ($q->rows as $row) if (in_array($row['code'],self::TYPES,true)) $this->definitions[$row['code']] = $row;
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
        foreach (['enabled','sort_order','days'] as $key) $parts[] = '`'.$key.'`='.(int)$row[$key];
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
            foreach (['enabled','sort_order','days','revision'] as $key) {
                if (!isset($row[$key]) || !is_scalar($row[$key]) || !preg_match('/^\d{1,10}$/D',(string)$row[$key])) throw new InvalidArgumentException('Invalid numeric value');
                $row[$key] = (int)$row[$key];
            }
            if ($row['enabled'] > 1 || $row['sort_order'] > 999 || $row['days'] > 3650 || $row['revision'] < 1) throw new InvalidArgumentException('Value outside allowed range');
            if ($code !== 'new') $row['days'] = 0;
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

    private function legacyHit($product) {
        if ((int)$product['product_id'] < 1) return false;
        $mpn = isset($product['mpn']) ? trim($product['mpn']) : '';
        $u = $this->nativeSettings();
        if (isset($u['mpn_as_sticker']) && in_array($mpn,['Популярно','Хит продаж','Хіт продажів'],true)) return true;
        if (!isset($u['sticker_bestseller'])) return false;
        if ($this->best === null) {
            $key = 'unishop.sticker.bestseller.'.$this->store;
            $ids = $this->registry->get('cache')->get($key);
            if (!is_array($ids) || !$ids) {
                $q = $this->db->query("SELECT op.product_id, SUM(op.quantity) AS total FROM `".DB_PREFIX."order_product` op LEFT JOIN `".DB_PREFIX."product` p ON op.product_id=p.product_id LEFT JOIN `".DB_PREFIX."order` o ON op.order_id=o.order_id WHERE o.order_status_id>0 AND p.date_available<=NOW() AND p.status=1 GROUP BY op.product_id");
                $ids = [0];
                foreach ($q->rows as $row) if ((int)$row['total'] >= (int)$u['sticker_bestseller_item']) $ids[] = (int)$row['product_id'];
                $this->registry->get('cache')->set($key,$ids);
            }
            $this->best = array_fill_keys($ids,true);
        }
        return isset($this->best[(int)$product['product_id']]);
    }

    public function state($product, $now = null) {
        $now = $now === null ? time() : $now;
        $definitions = $this->definitions();
        $flags = $this->flags();
        $id = (int)$product['product_id'];
        $start = isset($product['date_available']) ? strtotime($product['date_available']) : false;
        $end = $start === false ? false : $start+(int)$definitions['new']['days']*86400;
        $out = ['new'=>['enabled'=>$start !== false && $start <= $now && $end >= $now,'until'=>$end ? date('Y-m-d',$end) : '']];
        foreach (self::MANUAL as $code) {
            $row = isset($flags[$id][$code]) ? $flags[$id][$code] : null;
            $out[$code] = ['enabled'=>$row ? (bool)$row['enabled'] : ($code === 'hit' && $this->legacyHit($product)),
                          'revision'=>$row ? (int)$row['revision'] : 0,'inherited'=>$code === 'hit' && !$row];
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
            // Known old marketing MPN is represented by the single hit state; code is untouched.
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
