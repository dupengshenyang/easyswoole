<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2017/12/12
 * Time: 下午6:03
 */

namespace EasySwoole\Core\Swoole;
use EasySwoole\Config;
use EasySwoole\Core\Component\Cache\Cache;
use EasySwoole\Core\Component\Cluster\Cluster;
use EasySwoole\Core\Component\Event;
use EasySwoole\Core\Component\Trigger;
use Swoole\Coroutine;

class ServerManager
{
    private static $instance;
    private $serverList = [];
    private $mainServer = null;
    private $isStart = false;

    const TYPE_SERVER = 1;
    const TYPE_WEB_SERVER = 2;
    const TYPE_WEB_SOCKET_SERVER = 3;

    public static function getInstance():ServerManager
    {
        if(!isset(self::$instance)){
            self::$instance = new ServerManager();
        }
        return ServerManager::$instance;
    }

    public function addServer(string $serverName,int $port,int $type = SWOOLE_TCP,string $host = '0.0.0.0',array $setting = [
        "open_eof_check"=>false,
    ]):EventRegister
    {
        $eventRegister = new EventRegister();
        $this->serverList[$serverName] = [
            'port'=>$port,
            'host'=>$host,
            'type'=>$type,
            'setting'=>$setting,
            'eventRegister'=>$eventRegister
        ];
        return $eventRegister;
    }

    public function isStart():bool
    {
        return $this->isStart;
    }

    public function start():void
    {
        $this->createMainServer();
        Cache::getInstance();
        Cluster::getInstance()->run();
        $this->attachListener();
        $this->isStart = true;
        $this->getServer()->start();
    }


    private function attachListener():void
    {
        $mainServer = $this->getServer();
        foreach ($this->serverList as $serverName => $server){
            $subPort = $mainServer->addlistener($server['host'],$server['port'],$server['type']);
            if($subPort){
                $this->serverList[$serverName] = $subPort;
                if(is_array($server['setting'])){
                    $subPort->set($server['setting']);
                }
                $events = $server['eventRegister']->all();
                foreach ($events as $event => $callback){
                    $subPort->on($event, function () use ($callback) {
                        $args = func_get_args();
                        foreach ($callback as $item) {
                            call_user_func_array($item, $args);
                        }
                    });
                }
            }else{
                Trigger::throwable(new \Exception("addListener with server name:{$serverName} at host:{$server['host']} port:{$server['port']} fail"));
            }
        }
    }

    private function createMainServer():\swoole_server
    {
        $conf = Config::getInstance()->getConf("MAIN_SERVER");
        $runModel = $conf['RUN_MODEL'];
        $host = $conf['HOST'];
        $port = $conf['PORT'];
        $setting = $conf['SETTING'];
        $sockType = $conf['SOCK_TYPE'];
        switch ($conf['SERVER_TYPE']){
            case self::TYPE_SERVER:{
                $this->mainServer = new \swoole_server($host,$port,$runModel,$sockType);
                break;
            }
            case self::TYPE_WEB_SERVER:{
                $this->mainServer = new \swoole_http_server($host,$port,$runModel,$sockType);
                break;
            }
            case self::TYPE_WEB_SOCKET_SERVER:{
                $this->mainServer = new \swoole_websocket_server($host,$port,$runModel,$sockType);
                break;
            }
            default:{
                Trigger::throwable(new \Exception("unknown server type :{$conf['SERVER_TYPE']}"));
            }
        }
        $this->mainServer->set($setting);
        //创建默认的事件注册器
        $register = new EventRegister();
        $register->registerDefaultOnTask();
        $register->registerDefaultOnFinish();
        $register->registerDefaultOnPipeMessage();
        Event::getInstance()->hook('mainServerCreate', $this, $register);
        if($conf['SERVER_TYPE'] == self::TYPE_WEB_SERVER || $conf['SERVER_TYPE'] == self::TYPE_WEB_SOCKET_SERVER){
            //检查是否注册了onRequest,否则注册默认onRequest
            if(!$register->get($register::onRequest)){
                $register->registerDefaultOnRequest();
            }
        }

        $register->add($register::onWorkerStart,function (\swoole_server $server,int $workerId){
            if(PHP_OS != 'Darwin'){
                $workerNum = Config::getInstance()->getConf('MAIN_SERVER.SETTING.worker_num');
                $name = \EasySwoole\Core\Component\Cluster\Config::getInstance()->getServerName();
                if($workerId <= ($workerNum -1)){
                    $name = "{$name}_Worker_".$workerId;
                }else{
                    $name = "{$name}_Task_Worker_".$workerId;
                }
                cli_set_process_title($name);
            }
        });

        $events = $register->all();
        foreach ($events as $event => $callback){
            $this->mainServer->on($event, function () use ($callback) {
                $args = func_get_args();
                foreach ($callback as $item) {
                    call_user_func_array($item, $args);
                }
            });
        }
        return $this->mainServer;
    }

    public function getServer($serverName = null):?\swoole_server
    {
         if($this->mainServer){
             if($serverName === null){
                 return $this->mainServer;
             }else{
                 if(isset($this->serverList[$serverName])){
                     return $this->serverList[$serverName];
                 }
                 return null;
             }
         }else{
             return null;
         }
    }

    public function coroutineId():?int
    {
        if(class_exists('Swoole\Coroutine')){
            //进程错误的时候返回-1
            $ret =  Coroutine::getuid();
            if($ret >= 0){
                return $ret;
            }else{
                return null;
            }
        }else{
            return null;
        }
    }

    public function isCoroutine():bool
    {
        if($this->coroutineId() !== null){
            return true;
        }else{
            return false;
        }
    }
}