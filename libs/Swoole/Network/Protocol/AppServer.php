<?php
namespace Swoole\Network\Protocol;
use Swoole;

require_once LIBPATH.'/function/cli.php';
class AppServer extends HttpServer
{
    protected $router_function;
    protected $apps_path;

    function onStart($serv)
    {
        parent::onStart($serv);
        if(empty($this->apps_path))
        {
            if(!empty($this->config['apps']['apps_path']))
            {
                $this->apps_path = $this->config['apps']['apps_path'];
            }
            else
            {
                throw new \Exception("AppServer require apps_path");
            }
        }
    }
    function setAppPath($path)
    {
        $this->apps_path = $path;
    }
    function urlRouter($urlpath)
    {
        $array = array('controller'=>'page', 'view'=>'index');
        if(!empty($_GET["c"])) $array['controller']=$_GET["c"];
        if(!empty($_GET["v"])) $array['view']=$_GET["v"];

        if(empty($urlpath) or $urlpath=='/')
        {
            return $array;
        }
        $request = explode('/', trim($urlpath, '/'), 3);
        if(count($request) < 2)
        {
            return $array;
        }
        $array['controller']=$request[0];
        $array['view']=$request[1];

        if(isset($request[2]))
        {
            if(is_numeric($request[2])) $_GET['id'] = $request[2];
            else
            {
                Swoole\Tool::$url_key_join = '-';
                Swoole\Tool::$url_param_join = '-';
                Swoole\Tool::$url_add_end = '.html';
                Swoole\Tool::$url_prefix = WEBROOT."/{$request[0]}/$request[1]/";
                Swoole\Tool::url_parse_into($request[2],$_GET);
            }
        }
        return $array;
    }
    function onRequest($request)
    {
        $response = new \Swoole\Response();
        $php = \Swoole::getInstance();
        $request->setGlobal();

        if($this->doStaticRequest($request, $response))
        {
            return $response;
        }
        $mvc = $this->urlRouter($request->meta['path']);
        $php->env['mvc'] = $mvc;
        /*---------------------加载MVC程序----------------------*/
        $controller_file = $this->config['apps']['apps_path'].'/controllers/'.$mvc['controller'].'.php';
        if(!isset($php->env['controllers'][$mvc['controller']]))
        {
            if(is_file($controller_file))
            {
                \import_controller($mvc['controller'], $controller_file);
            }
            else
            {
                $this->http_error(404, $response, "控制器 <b>{$mvc['controller']}</b> 不存在!");
                return $response;
            }
        }
        //将对象赋值到控制器
        $php->request = $request;
        $php->response = $response;
        /*---------------------检测代码是否更新----------------------*/
        if(extension_loaded('runkit') and $this->config['apps']['auto_reload'])
        {
            clearstatcache();
            $fstat = stat($controller_file);
            //修改时间大于加载时的时间
            if($fstat['mtime']>$php->env['controllers'][$mvc['controller']]['time'])
            {
                runkit_import($controller_file, RUNKIT_IMPORT_CLASS_METHODS|RUNKIT_IMPORT_OVERRIDE);
                $php->env['controllers'][$mvc['controller']]['time'] = time();
                $this->log("reload controller ".$mvc['controller']);
            }
        }
        /*---------------------处理MVC----------------------*/
        if(empty($mvc['param'])) $param = array();
        else $param = $mvc['param'];

        $response->head['Cache-Control'] = 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0';
        $response->head['Pragma'] = 'no-cache';
        try
        {
            $controller = new $mvc['controller']($php);
            if(!method_exists($controller,$mvc['view']))
            {
                $this->http_error(404,$response,"视图 <b>{$mvc['controller']}->{$mvc['view']}</b> 不存在!");
                return $response;
            }
            ob_start();
            if($controller->is_ajax) $response->body = json_encode(call_user_func(array($controller,$mvc['view']),$param));
            else $response->body = call_user_func(array($controller, $mvc['view']), $param);
            $response->body .= ob_get_contents();
            ob_end_clean();
        }
        catch(\Exception $e)
        {
            if($request->finish!=1) $this->http_error(404,$response,$e->getMessage());
        }
        if(!isset($response->head['Content-Type']))
        {
            $response->head['Content-Type'] = 'text/html; charset='.$this->config['apps']['charset'];
        }
        //重定向
        if(isset($response->head['Location']))
        {
            $response->send_http_status(301);
        }
        //保存Session
        if($php->session->open and $php->session->readonly===false)
        {
            $php->session->save();
        }
        return $response;
    }
}