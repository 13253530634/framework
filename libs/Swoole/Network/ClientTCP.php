<?php
namespace Swoole\Network;
/**
 * TCP�ͻ���
 * @author ����
 */
class ClientTCP extends Client
{
    /**
     * �Ƿ���������
     */
    public $try_reconnect = true;
    public $connected = false; //�Ƿ�������
    /**
     * ��������
     * @param string $data
     */
    function send($data)
    {
        $length = strlen($data);
        $written = 0;
        $t1 = microtime(true);
        //�ܳ�ʱ��forѭ���м�ʱ
        while ($written < $length)
        {
            $n = socket_send($this->sock, substr($data, $written), $length - $written, null);
            //������ʱ��
            if (microtime(true) > $this->timeout_send + $t1)
            {
                return false;
            }
            if ($n === false) //������
            {
                $errno = socket_last_error($this->sock);
                //�жϴ�����Ϣ��EAGAIN EINTR����дһ��
                if ($errno == 11 or $errno == 4) {
                    continue;
                } else {
                    return false;
                }
            }
            $written += $n;
        }
        return $written;
    }

    /**
     * ��������
     * @param int $length �������ݵĳ���
     * @param bool $waitall �ȴ����յ�ȫ�����ݺ��ٷ��أ�ע�����ﳬ�������Ȼ�����ס
     */
    function recv($length = 65535, $waitall = 0)
    {
        if ($waitall) $waitall = MSG_WAITALL;
        $ret = socket_recv($this->sock, $data, $length, $waitall);

        if ($ret === false) {
            $this->set_error();
            //����һ�Σ�����Ϊ��ֹ���⣬��ʹ�õݹ�ѭ��
            if ($this->errCode == 4) {
                socket_recv($this->sock, $data, $length, $waitall);
            } else {
                return false;
            }
        }
        return $data;
    }

    /**
     * ���ӵ�������
     * ����һ��������������Ϊ��ʱ������������Ϊsec��С������*100����Ϊusec
     *
     * @param string $host ��������ַ
     * @param int $port ��������ַ
     * @param float $timeout ��ʱĬ��ֵ�����ӣ����ͣ����ն�ʹ�ô�����
     */
    function connect($host, $port, $timeout = 0.1, $nonblock = false)
    {
        //�жϳ�ʱΪ0����
        if (empty($host) or empty($port) or $timeout <= 0)
        {
            $this->errCode = -10001;
            $this->errMsg = "param error";
            return false;
        }
        $this->host = $host;
        $this->port = $port;
        //����socket
        $this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->sock === false)
        {
            $this->set_error();
            return false;
        }
        //����connect��ʱ
        $this->set_timeout($timeout, $timeout);
        $this->setopt(SO_REUSEADDR, 1);
        //������ģʽ��connect����������
        if($nonblock)
        {
            socket_set_nonblock($this->sock);
            @socket_connect($this->sock, $this->host, $this->port);
            return true;
        }
        else
        {
            //����Ĵ�����Ϣû���κ����壬�������ε�
            if (@socket_connect($this->sock, $this->host, $this->port))
            {
                $this->connected = true;
                return true;
            }
            elseif ($this->try_reconnect)
            {
                if (@socket_connect($this->sock, $this->host, $this->port))
                {
                    $this->connected = true;
                    return true;
                }
            }
        }
        $this->set_error();
        trigger_error("connect server[{$this->host}:{$this->port}] fail.errno={$this->errCode}|{$this->errMsg}");
        return false;
    }

    /**
     * �ر�socket����
     */
    function close()
    {
        if ($this->sock) socket_close($this->sock);
        $this->sock = null;
    }
}
