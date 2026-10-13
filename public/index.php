<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

try {
    $factory=new \Nyholm\Psr7\Factory\Psr17Factory();
    $creator=new \Nyholm\Psr7Server\ServerRequestCreator($factory,$factory,$factory,$factory);
    $request=$creator->fromGlobals();
    $app=new \YouTubeMcp\App(\YouTubeMcp\Config::load(dirname(__DIR__)));
    $response=$app->handle($request);
    if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name=>$values) { foreach ($values as $value) { header($name.': '.$value,false); } }
    echo $response->getBody();
} catch (\Throwable $e) {
    error_log(json_encode(['event'=>'youtube_mcp_bootstrap','error_class'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()]));
    http_response_code(503); header('Content-Type: application/json');
    echo '{"code":"SETUP_REQUIRED","message":"Service configuration or initialization is incomplete. Consult the installation guide."}';
}
