<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/Competitors.php';
require_once dirname(__DIR__) . '/src/CompetitorHttp.php';
require_once dirname(__DIR__) . '/src/CompetitorEvidence.php';

final class LavkaCompetitorDeferred extends RuntimeException {}

/**
 * CLI implementation with explicit test injection, never an environment bypass.
 * Public competitor requests are separate from the optional read-only own
 * catalogue refresh. The latter starts only after its first manual activation.
 * Neither path writes to the shop or reads customer records.
 */
function lavka_competitors_run(array $args, ?callable $transport = null, ?callable $clock = null, ?callable $pause = null): array
{
    if (PHP_VERSION_ID < 80200) throw new InvalidArgumentException('Нужен PHP 8.2 CLI; веб-PHP магазина менять не требуется.');
    $clock = $clock ?: function (): float { return microtime(true); };
    $pause = $pause ?: function (float $seconds): void { if ($seconds > 0) usleep((int)ceil($seconds * 1000000)); };
    $started = $clock();
    $stamp = function () use ($clock): string { return gmdate('Y-m-d\TH:i:s\Z', (int)$clock()); };
    $limit = filter_var($args['limit'] ?? 12, FILTER_VALIDATE_INT);
    $seconds = filter_var($args['seconds'] ?? 120, FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1 || $limit > 50 || $seconds === false || $seconds < 30 || $seconds > 240) {
        throw new InvalidArgumentException('Допустимо --limit 1..50 и --seconds 30..240.');
    }
    $data = $args['data'] ?? (getenv('ANALYTICS_DATA_DIR') ?: dirname(__DIR__) . '/data');
    if (!is_string($data) || $data === '' || $data[0] !== '/' || !is_dir($data) || !($directory = realpath($data))) {
        throw new InvalidArgumentException('Укажите --data с абсолютным путём существующего закрытого каталога.');
    }
    $manifest = is_file($directory . '/deployment-php.json') ? \Lavka\decode(file_get_contents($directory . '/deployment-php.json')) : [];
    foreach (['document_root', 'shop_root'] as $key) {
        if (!empty($manifest[$key])) {
            $public = \Lavka\canonical_path($manifest[$key]);
            if ($directory === $public || \Lavka\str_starts_with($directory, $public . '/')) throw new InvalidArgumentException('Данные должны находиться вне публичных каталогов.');
        }
    }
    umask(0077);
    \Lavka\private_dir($directory);
    $lock = fopen($directory . '/.competitors.lock', 'c');
    if ($lock === false) throw new RuntimeException('lock_failed');
    chmod($directory . '/.competitors.lock', 0600);
    $db = null;
    $summary = ['state' => 'ok', 'started_at' => $stamp(), 'checked' => 0, 'succeeded' => 0, 'failed' => 0, 'deferred' => 0,'runner_revision'=>substr(hash_file('sha256',__FILE__),0,16),'php'=>PHP_VERSION,'evidence'=>['saved'=>0,'failed'=>0,'skipped'=>0,'errors'=>[]]];
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return ['state' => 'skipped', 'reason' => 'already_running'];
        $db = \Lavka\competitors_db($directory);
        $capture=function(array $target,array $response,?string $code)use($db,&$summary,$stamp):void{
            $result=lavka_competitor_evidence_capture($db,$target,$response,$code,$stamp());
            $state=$result['state'];++$summary['evidence'][$state];
            if(!empty($result['error_code'])&&!in_array($result['error_code'],$summary['evidence']['errors'],true))$summary['evidence']['errors'][]=$result['error_code'];
        };
        if (array_key_exists('init-only', $args)) return ['state' => 'initialized', 'network_requests' => 0];
        require_once dirname(__DIR__) . '/src/CompetitorParser.php';
        require_once dirname(__DIR__) . '/src/CompetitorOwnCatalog.php';
        $ownCatalog = \Lavka\competitors_own_catalog_sync_due($directory);
        $ownCatalogActive = !empty($ownCatalog['last_success']);
        $ownCatalogTargetIds = \Lavka\competitors_catalog_target_ids($ownCatalog);
        // Sandbox own prices never fall back to live storefront requests.
        if (file_exists($directory . '/sandbox.json') || is_link($directory . '/sandbox.json')) {
            $ownCatalogTargetIds = array_map('intval', $db->query("SELECT id FROM competitor_targets WHERE source_id='lavka' AND kind='product'")->fetchAll(PDO::FETCH_COLUMN));
        }
        $summary['own_catalog'] = ['state' => $ownCatalog['state'], 'active' => $ownCatalogActive];
        $summary['limit']=$limit;$summary['budget_seconds']=$seconds;
        $discoveryOn=(bool)$db->query('SELECT enabled FROM competitor_discovery_settings WHERE id=1')->fetchColumn();
        $deadline = $started + $seconds;
        $cacheDirectory = $directory . '/competitor-http-cache';
        \Lavka\private_dir($cacheDirectory);

        // Persistent source pacing also spans consecutive cron processes. A long
        // crawl-delay defers a target rather than keeping shared hosting asleep.
        $pace = function (string $url, ?float $delay = null) use ($cacheDirectory, $clock, $pause, $deadline): void {
            $address = \Lavka\competitors_http_url($url);
            $host = preg_replace('/^www\./', '', $address['host']);
            $path = $cacheDirectory . '/pacing-' . hash('sha256', $host) . '.json';
            $previous = [];
            if (is_file($path) && filesize($path) <= 1000) {
                try { $previous = \Lavka\decode(file_get_contents($path)); } catch (Throwable $ignored) {}
            }
            $delay = max(2.0, min(86400.0, $delay ?? (float)($previous['crawl_delay'] ?? 0)));
            $last = max(0.0, min($clock(), (float)($previous['last_request_at'] ?? 0)));
            $wait = max(0.0, $last + $delay - $clock());
            if ($wait > 10.0 || $deadline - $clock() < $wait + 13.0) throw new LavkaCompetitorDeferred('request_deferred');
            if ($wait > 0) $pause($wait);
            \Lavka\atomic_write($path, \Lavka\encode(['last_request_at' => $clock(), 'crawl_delay' => $delay]));
        };
        $robotsPace = function (string $url) use ($pace): void { $pace($url); };
        $guard = function (string $url) use ($directory, $transport, $clock, $pace, $robotsPace, $db, $discoveryOn): void {
            $policy = \Lavka\competitors_robots_policy($url, $directory, $transport, (int)$clock(), $robotsPace);
            if($discoveryOn) {foreach(\Lavka\competitors_sources() as $source)if(preg_replace('/^www\./','',parse_url($source['base_url'],PHP_URL_HOST))===preg_replace('/^www\./','',parse_url($url,PHP_URL_HOST)))foreach($policy['sitemaps']??[] as $sitemap)\Lavka\competitors_scan_enqueue($db,$source['id'],$sitemap,'sitemap');}
            if (!$policy['allowed']) throw new \Lavka\CompetitorHttpError('robots_disallowed');
            $pace($url, (float)$policy['crawl_delay']);
        };

        if($discoveryOn && $deadline-$clock()>=27) {
          for($scanIndex=0;$scanIndex<min(8,$limit)&&$deadline-$clock()>=27;$scanIndex++){
            $scan=$db->query("SELECT p.* FROM competitor_scan_pages p WHERE p.next_check_at<='".$stamp()."' ORDER BY (SELECT COALESCE(MAX(q.checked_at),'') FROM competitor_scan_pages q WHERE q.source_id=p.source_id),(p.kind='product'),p.depth,p.id LIMIT 1")->fetch();
            if($scan) {
                $summary['discovery']=$summary['discovery']??['checked'=>0,'links'=>0,'pages'=>0,'failed'=>0];
                $response=null;
                try {
                    $response=\Lavka\competitors_http_fetch($scan['url'],$transport,$guard,$scan['kind']==='sitemap');
                    $found=\Lavka\competitors_scan_parse($db,$scan,$response['body'],$response['url']);
                    $capture($scan,$response,null);
                    $db->prepare("UPDATE competitor_scan_pages SET checked_at=?,next_check_at=?,state='ok',error_code=NULL,errors=0 WHERE id=?")->execute([$stamp(),gmdate('Y-m-d\TH:i:s\Z',(int)$clock()+604800),$scan['id']]);
                    if($scan['kind']==='product'){
                        // Reuse this very HTTP response for existing watched pages.
                        // A catalogue scan must not immediately download it again.
                        $watched=$db->prepare("SELECT * FROM competitor_targets WHERE enabled=1 AND kind='product' AND source_id=? AND url=?");$watched->execute([$scan['source_id'],$scan['url']]);
                        foreach($watched->fetchAll() as $target){
                            try{$observation=\Lavka\competitors_parse($target,$response['body'],$response['url']);\Lavka\competitors_record_attempt($db,(int)$target['id'],$observation,null,$stamp(),$response['status']);$capture($target,$response,null);$summary['discovery']['prices_loaded']=($summary['discovery']['prices_loaded']??0)+1;}
                            catch(\Lavka\CompetitorParseError $error){\Lavka\competitors_record_attempt($db,(int)$target['id'],null,$error->errorCode,$stamp(),$response['status']);$capture($target,$response,$error->errorCode);}
                        }
                    }
                    $summary['discovery']['checked']++;$summary['discovery']['links']+=$found['links'];$summary['discovery']['pages']+=$found['pages'];
                }catch(LavkaCompetitorDeferred $deferred){$summary['discovery']['deferred']=1;break;}
                catch(\Throwable $error){$code=$error instanceof \Lavka\CompetitorHttpError?$error->errorCode:($error instanceof \Lavka\CompetitorParseError?$error->getMessage():'scan_error');if(!preg_match('/^[a-z_]{1,60}$/D',$code))$code='scan_error';
                    $db->prepare("UPDATE competitor_scan_pages SET checked_at=?,next_check_at=?,state='error',error_code=?,errors=errors+1 WHERE id=?")->execute([$stamp(),gmdate('Y-m-d\TH:i:s\Z',(int)$clock()+min(86400,3600*(1<<(min(4,(int)$scan['errors']))))),$code,$scan['id']]);
                    if($response)$capture($scan,$response,$code);
                    $summary['discovery']['checked']++;$summary['discovery']['failed']++;
                }
            }
            if(!$scan||$scan['kind']!=='product')break;
          }
            $summary['matching']=\Lavka\competitors_discovery_match($db,true);
        }
        $targetBudget=max(0,$limit-($summary['discovery']['checked']??0));
        $targets = $targetBudget?\Lavka\competitors_due($db, $targetBudget, $ownCatalogTargetIds):[];
        foreach ($targets as $position => $target) {
            // A fresh robots check and product each have their own 12s total
            // timeout; reserve enough budget before starting the next target.
            if ($deadline - $clock() < 27.0) { $summary['deferred'] += count($targets) - $position; break; }
            $status = null;$response=null;
            try {
                $response = \Lavka\competitors_http_fetch($target['url'], $transport, $guard);
                $status = $response['status'];
                $observation = \Lavka\competitors_parse($target, $response['body'], $response['url']);
                \Lavka\competitors_record_attempt($db, (int)$target['id'], $observation, null, $stamp(), $status);
                $capture($target,$response,null);
                $summary['succeeded']++;
                if($discoveryOn && $target['source_id']!=='lavka' && $target['kind']==='product')\Lavka\competitors_index_link($db,$target['source_id'],$target['url'],$observation['title'],'page');
            } catch (LavkaCompetitorDeferred $error) {
                $summary['deferred']++;
                continue;
            } catch (\Lavka\CompetitorHttpError $error) {
                \Lavka\competitors_record_attempt($db, (int)$target['id'], null, $error->errorCode, $stamp(), $error->httpStatus);
                $summary['failed']++;
                if($response)$capture($target,$response,$error->errorCode);
            } catch (\Lavka\CompetitorParseError $error) {
                $code = isset($error->errorCode) ? $error->errorCode : $error->getMessage();
                if (!is_string($code) || !preg_match('/^[a-z_]{1,50}$/D', $code)) $code = 'parse_error';
                \Lavka\competitors_record_attempt($db, (int)$target['id'], null, $code, $stamp(), $status);
                $summary['failed']++;
                if($response)$capture($target,$response,$code);
            }
            $summary['checked']++;
        }
        $failures=$summary['failed']+($summary['discovery']['failed']??0);$successes=$summary['succeeded']+($summary['discovery']['checked']??0)-($summary['discovery']['failed']??0);
        $summary['state'] = $failures ? ($successes ? 'partial' : 'error') : 'ok';
        $summary['finished_at'] = $stamp();
        $summary['seconds'] = round($clock() - $started, 3);
        $summary['peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
        \Lavka\competitors_run_status($db, $summary);
        return $summary;
    } catch (Throwable $error) {
        if ($db) {
            $summary['state'] = 'error';
            $summary['finished_at'] = $stamp();
            $summary['error_code'] = 'runner_error';
            try { \Lavka\competitors_run_status($db, $summary); } catch (Throwable $ignored) {}
        }
        throw $error;
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

// Requiring the file in a deterministic CLI test defines the runner only.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
try {
    $summary = lavka_competitors_run(getopt('', ['data:', 'init-only', 'limit:', 'seconds:']));
    echo \Lavka\encode($summary) . "\n";
    exit($summary['state'] === 'error' ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof InvalidArgumentException ? $error->getMessage() : 'Ошибка проверки конкурентов; предыдущие результаты сохранены.') . "\n");
    exit(1);
}
