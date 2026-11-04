<?php
namespace FreePBX\modules\Sccp_manager;

/** Local-only uploaded media catalog; files are never served by Apache. */
final class serviceMediaLibrary {
    public const ROOT='/var/spool/asterisk/sccp-manager-media';
    public static function rows() {
        $result=[];
        foreach(glob(self::ROOT.'/*.json')?:[] as $path) {
            if(is_link($path))continue;
            $row=json_decode((string)@file_get_contents($path),true);
            if(!is_array($row) || !preg_match('/^[a-f0-9]{24}$/D',(string)($row['id']??'')))continue;
            $file=self::ROOT.'/'.$row['id'].'.media';
            if(is_file($file) && !is_link($file))$result[]=$row;
        }
        usort($result,static fn($a,$b)=>strcmp($a['name'],$b['name']));
        return $result;
    }
    public static function path($id) {
        if(!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D',$id))
            throw new \InvalidArgumentException('invalid media id');
        $path=self::ROOT.'/'.$id.'.media';
        if(!is_file($path) || is_link($path) || !is_file(self::ROOT.'/'.$id.'.json'))
            throw new \InvalidArgumentException('media file not found');
        return $path;
    }
    public static function find($id) {
        self::path($id);
        $data=json_decode((string)@file_get_contents(self::ROOT.'/'.$id.'.json'),true);
        if(!is_array($data) || ($data['id']??'')!==$id)
            throw new \InvalidArgumentException('media metadata not found');
        return $data;
    }
    public static function upload($upload) {
        if(!is_array($upload) || ($upload['error']??-1)!==UPLOAD_ERR_OK ||
            !is_string($upload['tmp_name']??null) ||
            !is_uploaded_file($upload['tmp_name']))
            throw new \InvalidArgumentException('select a valid media file');
        $size=(int)($upload['size']??0);
        if($size<1 || $size>262144000)
            throw new \InvalidArgumentException('media must be under 250 mib');
        if(!is_dir(self::ROOT) || is_link(self::ROOT) || !is_writable(self::ROOT))
            throw new \RuntimeException('media library is not installed');
        $original=basename((string)($upload['name']??'media'));
        $name=trim(preg_replace('/[\x00-\x1f\x7f]/','',$original));
        if($name==='' || strlen($name)>180)$name=substr($name,0,180)?:'media';
        $id=bin2hex(random_bytes(12));
        $dest=self::ROOT.'/'.$id.'.media';
        $meta=self::ROOT.'/'.$id.'.json';
        if(!move_uploaded_file($upload['tmp_name'],$dest))
            throw new \RuntimeException('unable to save uploaded media');
        @chmod($dest,0640);
        try {
            $probe=self::probe($dest);
            $audio=false;$video=false;
            foreach($probe['streams']??[] as $stream) {
                if(($stream['codec_type']??'')==='audio')$audio=true;
                if(($stream['codec_type']??'')==='video' &&
                    !in_array('attached_pic',$stream['disposition']??[],true) &&
                    empty($stream['disposition']['attached_pic']))$video=true;
            }
            if(!$audio)throw new \InvalidArgumentException('media needs an audio stream');
            $length=(float)($probe['format']['duration']??0);
            if(!is_finite($length) || $length<=0 || $length>14400)
                throw new \InvalidArgumentException('media duration must be between 0 and 4 hours');
            $row=['id'=>$id,'name'=>$name,'video'=>$video,'duration'=>$length,'size'=>$size];
            $json=json_encode($row,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if(file_put_contents($meta,$json,LOCK_EX)!==strlen($json))
                throw new \RuntimeException('unable to save media information');
            @chmod($meta,0640);
            return $row;
        }catch(\Throwable $e){
            @unlink($dest);@unlink($meta);throw $e;
        }
    }
    private static function probe($path) {
        if(!is_executable('/usr/bin/ffprobe'))
            throw new \RuntimeException('ffprobe is required');
        $command='/usr/bin/timeout 15 /usr/bin/ffprobe -v error -show_format -show_streams -of json '.
            escapeshellarg($path).' 2>/dev/null';
        $lines=[];$code=0;
        exec($command,$lines,$code);
        if($code!==0)throw new \InvalidArgumentException('ffmpeg cannot read this file');
        $data=json_decode(implode("\n",$lines),true);
        if(!is_array($data))throw new \InvalidArgumentException('invalid media file');
        return $data;
    }
    public static function remove($id) {
        self::path($id);
        @unlink(self::ROOT.'/'.$id.'.json');
        @unlink(self::ROOT.'/'.$id.'.media');
    }
}
