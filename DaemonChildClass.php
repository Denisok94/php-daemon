<?php

/**
 * Дочерний демон, который будет выполнять всю работы
 */
class DaemonChildClass
{
    private $pid;

    public function __construct($pid)
    {
        $this->pid = $pid;
        $this->log("DaemonChild start");
    }
    public function getPid()
    {
        return $this->pid;
    }

    public function run()
    {
        sleep(rand(10, 30));
        $this->log("DaemonChild end");
        return 1;
    }

    public function log($msg)
    {
        echo '[' . microtime() . ']pid:' . $this->pid . "|$msg" . PHP_EOL;
    }
}
