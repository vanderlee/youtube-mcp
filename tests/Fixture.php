<?php
declare(strict_types=1);
namespace YouTubeMcp\Tests;
use YouTubeMcp\Storage\Database;
use YouTubeMcp\Config;
trait Fixture
{
    private function database(): Database
    { $db=new Database(':memory:'); $db->migrate(dirname(__DIR__)); return $db; }
    private function config(array $values=[]): Config
    { return new Config($values+['APP_URL'=>'https://music.example.test','APP_KEY'=>base64_encode(str_repeat('k',32))],dirname(__DIR__)); }
    private function user(Database $db,string $id='u1'): void { $db->execute('INSERT INTO users VALUES (?,?,?,?)',[$id,'issuer|'.$id,$id.'@example.test',time()]); }
    private function tempDir(): string { $dir=sys_get_temp_dir().'/yt-mcp-'.bin2hex(random_bytes(8)); mkdir($dir,0700); return $dir; }
    private function removeDir(string $dir): void { foreach (glob($dir.'/*')?:[] as $file) { if (is_dir($file)) { $this->removeDir($file); } else { unlink($file); } } rmdir($dir); }
}
