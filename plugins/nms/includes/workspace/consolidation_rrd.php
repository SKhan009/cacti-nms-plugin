<?php
/** Collector-only bounded file evidence. Caller must first authorize the reviewed native data IDs. */
function nms_workspace_consolidation_rrd_manifest($data,$rra_root,$max_bytes=268435456,$seconds=15)
{
    if(PHP_SAPI!=='cli')throw new RuntimeException('RRD verification must run on the collector.');
    $root=realpath($rra_root);
    if(!$root||!is_dir($root))throw new RuntimeException('Collector RRD directory is unavailable.');
    if(count($data)>512||$max_bytes<1||$max_bytes>268435456||$seconds<1||$seconds>15)throw new RuntimeException('RRD verification exceeds its bounded limits.');
    $deadline=microtime(true)+$seconds;$bytes=0;$manifest=[];$files=[];
    foreach($data as $row) {
        $id=(int)($row['id']??0);$stored=(string)($row['data_source_path']??'');
        if($id<1||$stored===''||strpos($stored,"\0")!==false)throw new RuntimeException('A reviewed data source has no usable RRD path.');
        if(isset($manifest[$id])) {
            if($manifest[$id]['stored_path']!==$stored)throw new RuntimeException('A data source has conflicting RRD path records.');
            continue;
        }
        $path=str_replace('<path_rra>/',$root.DIRECTORY_SEPARATOR,$stored);
        if(strpos($path,'/')===false)$path=$root.DIRECTORY_SEPARATOR.$path;
        clearstatcache(true,$path);$resolved=realpath($path);
        if(!$resolved||strpos($resolved,$root.DIRECTORY_SEPARATOR)!==0)throw new RuntimeException('An RRD is missing or outside the configured collector RRD directory.');
        if(isset($files[$resolved]))throw new RuntimeException('Multiple data sources share one RRD file; dedicated migration review is required.');
        if(microtime(true)>$deadline)throw new RuntimeException('RRD verification exceeded its time limit.');
        $pathStat=@stat($resolved);
        if(!$pathStat||($pathStat['mode']&0170000)!==0100000)throw new RuntimeException('Expected a regular RRD file, not a pipe or device.');
        $handle=@fopen($resolved,'rb');
        if(!$handle)throw new RuntimeException('An RRD cannot be read by the collector.');
        try {
            $before=fstat($handle);
            if(!$before||($before['mode']&0170000)!==0100000||$before['size']<4)throw new RuntimeException('Expected a nonempty regular RRD file.');
            if($before['size']>$max_bytes-$bytes)throw new RuntimeException('RRD verification exceeds the total byte limit.');
            $header=fread($handle,4);
            if($header!=="RRD\0")throw new RuntimeException('A data-source file does not have an RRD header.');
            rewind($handle);$hash=hash_init('sha256');$read=0;
            while(!feof($handle)) {
                if(microtime(true)>$deadline)throw new RuntimeException('RRD verification exceeded its time limit.');
                $chunk=fread($handle,1048576);
                if($chunk===false||($chunk===''&&!feof($handle)))throw new RuntimeException('RRD read failed.');
                $read+=strlen($chunk);
                if($read>$max_bytes-$bytes)throw new RuntimeException('RRD grew beyond the verification limit.');
                hash_update($hash,$chunk);
            }
            $after=fstat($handle);clearstatcache(true,$path);$current=@stat($path);
            foreach(['dev','ino','size','mtime','ctime'] as $field)if(!$after||!$current||$before[$field]!==$after[$field]||$before[$field]!==$current[$field])throw new RuntimeException('RRD changed during verification; stop concurrent writes and retry.');
            if($read!==$before['size']||realpath($path)!==$resolved)throw new RuntimeException('RRD was replaced during verification.');
            $manifest[$id]=['stored_path'=>$stored,'resolved_path'=>$resolved,'bytes'=>$read,'sha256'=>hash_final($hash),'device'=>$before['dev'],'inode'=>$before['ino']];
            $bytes+=$read;$files[$resolved]=true;
        }finally{fclose($handle);}
    }
    ksort($manifest,SORT_NUMERIC);return $manifest;
}

/** Compare pre/post transfer evidence without silently accepting replacement paths or files. */
function nms_workspace_consolidation_rrd_verify($before,$data,$rra_root)
{
    $after=nms_workspace_consolidation_rrd_manifest($data,$rra_root);
    if($before!==$after)throw new RuntimeException('RRD paths, files or historical contents changed; consolidation requires recovery review.');
    return $after;
}
