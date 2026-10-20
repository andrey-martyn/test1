<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$required=['pdo_mysql','pdo_sqlite','sqlite3','bcmath','mbstring','intl','openssl'];$missing=array_values(array_filter($required,fn($e)=>!extension_loaded($e)));
echo json_encode(['php'=>PHP_VERSION,'php_supported'=>PHP_VERSION_ID>=80200,'missing_extensions'=>$missing,'argon2id'=>in_array('argon2id',password_algos(),true),'memory_limit'=>ini_get('memory_limit')],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
exit($missing||PHP_VERSION_ID<80200||!in_array('argon2id',password_algos(),true)?1:0);
