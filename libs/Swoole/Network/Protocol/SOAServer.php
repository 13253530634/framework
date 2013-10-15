<?php
namespace Swoole\Network\Protocol;

use Swoole;
/**
 * Class Server
 * @package Swoole\Network
 */
class SOAServer extends \Swoole\Network\Protocol implements \Swoole\Server\Protocol
{
    protected $_buffer; //buffer��
    protected $_fdfrom; //����fd��Ӧ��from_id

    protected $errCode;
    protected $errMsg;

    protected $packet_maxlen = 2465792; //2MĬ����󳤶�
    protected $buffer_maxlen = 10240;   //��������������,�����󽫶��������������
    protected $buffer_clear_num = 100; //������󳤶Ⱥ�����100������

    const STX = 0xABAB;
    const ETX = 0xEFEF;

    const ERR_STX         = 9001;
    const ERR_OVER_MAXLEN = 9002;
    const ERR_BUFFER_FULL = 9003;

    const ERR_UNPACK      = 9204; //���ʧ��
    const ERR_PARAMS      = 9205; //��������
    const ERR_NOFUNC      = 9206; //����������
    const ERR_CALL        = 9207; //ִ�д���

    protected $appNS = array(); //Ӧ�ó��������ռ�
    public $function_map = array(); //�ӿ��б�

    function onStart($serv)
    {
        $this->log("Server@{$this->server->host}:{$this->server->port} is running.");
    }
    function onShutdown($serv)
    {
        $this->log("Server is shutdown");
    }
    function onWorkerStart($serv, $worker_id)
    {
        $this->log("Worker[$worker_id] is start");
    }
    function onWorkerStop($serv, $worker_id)
    {
        $this->log("Worker[$worker_id] is stop");
    }
    function onTimer($serv, $interval)
    {
        $this->log("Timer[$interval] call");
    }
    /**
     * ����false�����������ʹ����룬����true��������һ����������0��ʾ�����ȴ���
     * @param $data
     * @return false or true or 0
     */
    function _packetReform($data)
    {
        $_etx = unpack('netx', substr($data, -2, 2));
        //�յ�������
        if($_etx!=false and $_etx['etx'] === self::ETX)
        {
            return true;
        }
        //������󳤶Ƚ�����
        elseif(strlen($data) > $this->packet_maxlen)
        {
            $this->errCode = self::ERR_OVER_MAXLEN;
            $this->log("ERROR: packet too big.data=".$data);
            return false;
        }
        //�����ȴ�����
        else
        {
            return 0;
        }
    }
    function onReceive($serv, $fd, $from_id, $data)
    {
        if(!isset($this->_buffer[$fd]) or $this->_buffer[$fd]==='')
        {
            //����buffer������󳤶���
            if(count($this->_buffer) >= $this->buffer_maxlen)
            {
                $n = 0;
                foreach($this->_buffer as $k=>$v)
                {
                    $this->server->close($k, $this->_fdfrom[$k]);
                    $n++;
                    $this->log("clear buffer");
                    //�������
                    if($n >= $this->buffer_clear_num) break;
                }
            }
            $_stx = unpack('nstx', substr($data, 0, 2));
            //�������ʼ��
            if($_stx == false or $_stx['stx'] != self::STX)
            {
                $this->errCode = self::ERR_STX;
                $this->log("ERROR: No stx.data=".$data);
                return false;
            }
            $this->_buffer[$fd] = '';
        }
        $this->_buffer[$fd] .= $data;
        $ret = $this->_packetReform($this->_buffer[$fd]);
        //�����ȴ�����
        if($ret === 0)
        {
            return true;
        }
        //�����˰�
        elseif($ret === false)
        {
            $this->log("ERROR: lose data=".$data);
            $this->server->close($fd, $from_id);
            //������Լ�log
        }
        //��������
        else
        {
            //������Ҫȥ��STX��ETX
            $retData = $this->task($fd, substr($this->_buffer[$fd], 2, strlen($this->_buffer[$fd])-4));
            //ִ��ʧ��
            if($retData === false)
            {
                $this->server->close($fd);
            }
            else
            {
                $this->server->send($fd, pack('n', self::STX).serialize($retData).pack('n', self::ETX));
            }
            //������
            $this->_buffer[$fd] = '';
        }
    }
    function onConnect($serv, $fd, $from_id)
    {
        $this->_fdfrom[$fd] = $from_id;
    }
    function onClose($serv, $fd, $from_id)
    {
        unset($this->_buffer[$fd], $this->_fdfrom[$fd]);
    }
    function addNameSpace($name, $path)
    {
        if(!is_dir($path))
        {
            throw new \Exception("$path is not real path.");
        }
        Swoole\Loader::setRootNS($name, $path);
    }

    function task($client_id, $data)
    {
        $request = unserialize($data);
        if($request === false)
        {
            return array('errno'=>self::ERR_UNPACK);
        }
        if(empty($request['call']) or empty($request['params']))
        {
            return array('errno'=>self::ERR_PARAMS);
        }
        if(!is_callable($request['call']))
        {
            return array('errno'=>self::ERR_NOFUNC);
        }
        $ret = call_user_func($request['call'], $request['params']);
        if($ret === false)
        {
            return array('errno'=>self::ERR_CALL);
        }
        return array('errno'=>0, 'data' => $ret);
    }
}