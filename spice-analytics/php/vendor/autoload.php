<?php
// PSR-4 loader for the bundled, pinned official MaxMind reader (see PROVENANCE.txt).
spl_autoload_register(static function(string $class): void {
    $prefix='MaxMind\\Db\\';
    if(strncmp($class,$prefix,strlen($prefix))===0){
        $path=__DIR__.'/maxmind-db/reader/src/MaxMind/Db/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($path))require $path;
    }
});
