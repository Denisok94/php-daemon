<?php

/* 
 * Process.php
 * An easy way to keep in track of external processes.
 * Ever wanted to execute a process in php, but you still wanted to have somewhat controll of the process ? Well.. This is a way of doing it.
 * @compability: Linux only. (Windows does not work).
 * @author: Peec
 * http://mithrandir.ru/professional/php/php-daemons.html
 */
class Process
{
    private int $pid;
    private $command;

    public function __construct($cl = false)
    {
        if ($cl != false) {
            $this->command = $cl;
            $this->runCom();
        }
    }
    private function runCom(): void
    {
        $command = 'nohup ' . $this->command . ' > /dev/null 2>&1 & echo $!';
        exec($command, $op);
        $this->setPid((int)$op[0]);
    }

    public function setPid(int $pid): self
    {
        $this->pid = $pid;
        return $this;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function status(): bool
    {
        $command = 'ps -p ' . $this->pid;
        exec($command, $op);
        if (!isset($op[1])) return false;
        else return true;
    }

    public function start()
    {
        if ($this->command != '') $this->runCom();
        else return true;
    }

    public function stop(): bool
    {
        $command = 'kill ' . $this->pid;
        exec($command);
        if ($this->status() == false) return true;
        else return false;
    }
}
// $processId = 28310;
// $process = new Process();
// $process->setPid($processId);
// if ($status = $process->status()) // возвращает true или false
// {
//     echo $process->stop(); // возвращает true или false
// }