<?php
namespace Swoole\Network;
use Swoole\Network\Protocol;

class SOAClient
{
    protected $servers = array();

    protected $wait_list = array();
    protected $timeout = 0.5;
    protected $packet_maxlen = 2465792;

    const OK = 0;
    const TYPE_ASYNC = 1;
    const TYPE_SYNC  = 2;
    public $re_connect = true; //����connect
    /**
     * ��������
     * @param $type
     * @param $send
     * @param $retObj
     */
    protected function request($type, $send, $retObj)
    {
        $socket = new \Swoole\Network\ClientTCP;
        $retObj->socket = $socket;
        $retObj->type = $type;
        $retObj->send = $send;

        $svr = $this->getServer();
        //�첽connect
        $ret = $socket->connect($svr['host'], $svr['port'], $this->timeout);
        //ʹ��SOCKET�ı����ΪID
        $retObj->id = (int)$socket->get_socket();
        if($ret === false)
        {
            $retObj->code = SOAClient_Result::ERR_CONNECT;
            unset($retObj->socket);
            return false;
        }
        //����ʧ����
        if($retObj->socket->send(self::packData($retObj->send)) === false)
        {
            $retObj->code = SOAClient_Result::ERR_SEND;
            unset($retObj->socket);
            return false;
        }
        //����wait_list
        if($type != self::TYPE_ASYNC)
        {
            $this->wait_list[$retObj->id] = $retObj;
        }
        return true;
    }
    /**
     * �������
     * @param $retData
     * @param $retObj
     */
    protected function finish($retData, $retObj)
    {
        $retObj->data = $retData;
        if(!empty($retData) and isset($retData['errno']))
        {
            if($retData['errno'] === self::OK)
            {
                $retObj->code = self::OK;
            }
            else
            {
                $retObj->code = SOAClient_Result::ERR_SERVER;
            }
        }
        else
        {
            $retObj->code = SOAClient_Result::ERR_UNPACK;
        }
        if($retObj->type != self::TYPE_ASYNC)
        {
            unset($this->wait_list[$retObj->id]);
        }
    }

    function addServers(array $servers)
    {
        $this->servers = array_merge($this->servers, $servers);
    }

    function getServer()
    {
        if(empty($this->servers))
        {
            throw new \Exception("servers config empty.");
        }
        $_svr = $this->servers[array_rand($this->servers)];
        $svr = array('host'=>'', 'port'=>0);
        list($svr['host'], $svr['port']) = explode(':', $_svr, 2);
        return $svr;
    }
    /**
     * �������
     * @param $data
     * @return string
     */
    static function packData($data)
    {
        return pack('n', Protocol\SOAServer::STX).serialize($data).pack('n', Protocol\SOAServer::ETX);
    }
    /**
     * ���
     * @param $recv
     * @param bool $unseralize
     * @return string
     */
    static function unpackData($recv, $unseralize = true)
    {
        $data = substr($recv, 2, strlen($recv)-4);
        return unserialize($data);
    }
    /**
     * RPC����
     * @param $function
     * @param $params
     * @return SOAClient_Result
     */
    function task($function, $params)
    {
        $retObj = new SOAClient_Result();
        $send = array('call' => $function, 'params' => $params);
        $this->request(self::TYPE_SYNC, $send, $retObj);
        return $retObj;
    }
    /**
     * �첽����
     * @param $function
     * @param $params
     * @return SOAClient_Result
     */
    function async($function, $params)
    {
        $retObj = new SOAClient_Result();
        $send = array('call' => $function, 'params' => $params);
        $this->request(self::TYPE_ASYNC, $send, $retObj);
        if($retObj->socket != null)
        {
            $recv = $retObj->socket->recv();
            if($recv==false)
            {
                $retObj->code = SOAClient_Result::ERR_TIMEOUT;
                return $retObj;
            }
            $this->finish(self::unpackData($recv), $retObj);
        }
        return $retObj;
    }

    /**
     * ��������
     * @param float $timeout
     * @return int
     */
    function wait($timeout = 0.5)
    {
        $st = microtime(true);
        $t_sec = (int)$timeout;
        $t_usec = (int)(($timeout - $t_sec) * 1000 * 1000);
        $buffer = array();
        $success_num = 0;

        while(true)
        {
            $write = $error = $read = array();
            if(empty($this->wait_list))
            {
                break;
            }
            foreach($this->wait_list as $obj)
            {
                if($obj->socket !== null)
                {
                    $read[] = $obj->socket->get_socket();
                }
            }
            if(empty($read))
            {
                break;
            }
            $n = socket_select($read, $write, $error, $t_sec, $t_usec);
            if($n > 0)
            {
                //�ɶ�
                foreach($read as $sock)
                {
                    $id = (int)$sock;
                    $retObj = $this->wait_list[$id];
                    $data = $retObj->socket->recv();
                    //socket���ر���
                    if(empty($data))
                    {
                        $retObj->code = SOAClient_Result::ERR_CLOSED;
                        unset($this->wait_list[$id], $retObj->socket);
                        continue;
                    }
                    if(!isset($buffer[$id]))
                    {
                        $_stx = unpack('nstx', substr($data, 0, 2));
                        //�������ʼ��
                        if($_stx == false or $_stx['stx'] != Protocol\SOAServer::STX)
                        {
                            $retObj->code = SOAClient_Result::ERR_STX;
                            unset($this->wait_list[$id]);
                            continue;
                        }
                        $buffer[$id] = '';
                    }
                    $buffer[$id] .= $data;
                    $_etx = unpack('netx', substr($buffer[$id], -2, 2));
                    //�յ�������
                    if($_etx!=false and $_etx['etx'] === Protocol\SOAServer::ETX)
                    {
                        //�ɹ�����
                        $this->finish(self::unpackData($buffer[$id]), $retObj);
                        $success_num++;
                    }
                    //������󳤶Ƚ�����
                    elseif(strlen($data) > $this->packet_maxlen)
                    {
                        $retObj->code = SOAClient_Result::ERR_TOOBIG;
                        unset($this->wait_list[$id]);
                        continue;
                    }
                    //�����ȴ�����
                }
            }
            //������ʱ
            if((microtime(true) - $st) > $timeout)
            {
                foreach($this->wait_list as $obj)
                {
                    $obj->code = ($obj->socket->connected)?SOAClient_Result::ERR_TIMEOUT:SOAClient_Result::ERR_CONNECT;
                }
                //��յ�ǰ�б�
                $this->wait_list = array();
                return $success_num;
            }
        }
        //δ�����κγ�ʱ
        $this->wait_list = array();
        return $success_num;
    }

}

class SOAClient_Result
{
    public $id;
    public $code = self::ERR_NO_READY;
    public $msg;
    public $data = null;
    public $send;  //Ҫ���͵�����
    public $type;
    public $socket = null;

    const ERR_NO_READY   = 8001; //δ����
    const ERR_CONNECT    = 8002; //���ӷ�����ʧ��
    const ERR_TIMEOUT    = 8003; //�������˳�ʱ
    const ERR_SEND       = 8004; //����ʧ��
    const ERR_SERVER     = 8005; //server�����˴�����
    const ERR_UNPACK     = 8006; //���ʧ����
    const ERR_STX        = 8007; //�������ʼ��
    const ERR_TOOBIG     = 8008; //�����������ĳ���
    const ERR_CLOSED     = 8009;
}