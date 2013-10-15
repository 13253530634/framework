<?php
namespace Swoole\Network;
/**
 * UDP�ͻ���
 */
class ClientUDP extends Client
{
    public $remote_host;
    public $remote_port;

    /**
     * ���ӵ�������
     * ����һ��������������Ϊ��ʱ������������Ϊsec��С������*100����Ϊusec
     *
     * @param string $host ��������ַ
     * @param int $port ��������ַ
     * @param float $timeout ��ʱĬ��ֵ�����ӣ����ͣ����ն�ʹ�ô�����
     * @param bool $udp_connect �Ƿ�����connect��ʽ
     */
    function connect($host, $port, $timeout = 0.1, $udp_connect = true)
    {
        //�жϳ�ʱΪ0����
        if (empty($host) or empty($port) or $timeout <= 0) {
            $this->errCode = -10001;
            $this->errMsg = "param error";
            return false;
        }
        $this->host = $host;
        $this->port = $port;
        $this->sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->set_timeout($timeout, $timeout);
        //$this->set_bufsize($this->sendbuf_size, $this->recvbuf_size);

        //�Ƿ���UDP Connect
        if ($udp_connect !== true) {
            return true;
        }
        if (socket_connect($this->sock, $host, $port)) {
            //����connectǰ��buffer��������
            while (@socket_recv($this->sock, $buf, 65535, MSG_DONTWAIT)) ;
            return true;
        } else {
            $this->set_error();
            return false;
        }
    }

    /**
     * ��������
     * @param string $data
     * @return $n or false
     */
    function send($data)
    {
        $len = strlen($data);
        $n = socket_sendto($this->sock, $data, $len, 0, $this->host, $this->port);

        if ($n === false or $n < $len) {
            $this->set_error();
            return false;
        } else {
            return $n;
        }
    }

    /**
     * �������ݣ�UD�����ܷ�2�ζ���recv���������ݰ������Ա���Ҫһ���Զ���
     *
     * @param int $length �������ݵĳ���
     * @param bool $waitall �ȴ����յ�ȫ�����ݺ��ٷ��أ�ע��waitall=true,���������Ȼ�����ס
     */
    function recv($length = 65535, $waitall = 0)
    {
        if ($waitall) $waitall = MSG_WAITALL;
        $ret = socket_recvfrom($this->sock, $data, $length, $waitall, $this->remote_host, $this->remote_port);
        if ($ret === false) {
            $this->set_error();
            //����һ�Σ�����Ϊ��ֹ���⣬��ʹ�õݹ�ѭ��
            if ($this->errCode == 4) {
                socket_recvfrom($this->sock, $data, $length, $waitall, $this->remote_host, $this->remote_port);
            } else {
                return false;
            }
        }
        return $data;
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