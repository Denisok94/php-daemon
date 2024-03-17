<?php

// Без этой директивы PHP не будет перехватывать сигналы
// declare(ticks=1);
pcntl_signal_dispatch();

include_once 'Process.php';
include_once 'DaemonChildClass.php';

/**
 * https://habr.com/ru/articles/134620/
 */
class DaemonClass
{
    private int $pid;
    private Process $process;
    /**
     * кол-во объектов на обработку
     */
    private int $queue = 0;
    /**
     * Максимальное количество дочерних процессов
     */
    public int $maxProcesses = 3;
    /**
     * Когда установится в TRUE, демон завершит работу
     */
    protected bool $stop_server = FALSE;
    /**
     * Здесь будем хранить запущенные дочерние процессы
     */
    protected array $currentJobs = [];

    public function __construct()
    {
        $this->pid = getmypid();
        $this->process = new Process();
        $this->log("Сonstructed daemon controller");
        // Ждем сигналы SIGTERM и SIGCHLD
        // todo: пока не работает?...
        pcntl_signal(SIGTERM, array($this, "childSignalHandler"));
        pcntl_signal(SIGCHLD, array($this, "childSignalHandler"));
    }

    private function start()
    {
        $this->log("Running daemon controller");
        file_put_contents(__DIR__ . '/daemon.pid', $this->pid);
        // $this->queue ?? - узнаем кол-во объектов на обработку из бд
        if ($this->queue == 0) {
            // Нет объектов на обработку, зря стартовали
            $this->childSignalHandler(SIGTERM);
        }
    }

    public function run()
    {
        if ($this->isDaemonActive(__DIR__ . '/daemon.pid')) {
            exit;
        }
        $this->start();

        // Пока $stop_server не установится в TRUE, гоняем бесконечный цикл
        while (!$this->stop_server) {
            // Если уже запущено максимальное количество дочерних процессов, ждем их завершения
            while (count($this->currentJobs) >= $this->maxProcesses) {
                $this->log("Maximum children allowed, waiting...");
                $this->updateStatus();
            }
            // если есть объекты в очереди, создаём демона
            if ($this->queue) {
                if (!$this->launchJob()) {
                    // exit;
                }
                sleep(1); // мини пауза
            } else {
                $this->updateStatus();
            }
        }
        $this->log("stop");
    }

    /**
     * https://habr.com/ru/articles/40432/
     * @param [type] $pid_file
     * @return boolean
     */
    private function isDaemonActive($pid_file)
    {
        if (is_file($pid_file)) {
            $pid = file_get_contents($pid_file);
            //проверяем на наличие процесса
            $statusPid = $this->process->setPid($pid)->status();
            if ($statusPid) {
                //демон уже запущен
                $this->log('Daemon already active: ' . $pid);
                return true;
            } else {
                //pid-файл есть, но процесса нет 
                if (!unlink($pid_file)) {
                    //не могу уничтожить pid-файл. ошибка
                    exit(-1);
                }
            }
        }
        return false;
    }

    private function updateStatus()
    {
        $this->log("currentJobs: " . count($this->currentJobs));
        foreach ($this->currentJobs as $pid => $value) {
            $statusPid = $this->process->setPid($pid)->status();
            $this->log("$pid status: " . ($statusPid ? 'on' : 'off'));
            if (!$statusPid) {
                // принудительно удалить процесс из системы и списка у завершённого процесса <defunct>
                $this->childSignalHandler(SIGCHLD, $pid);
            }
            // if ($pid > 100) {
            //     $this->childSignalHandler(SIGTERM);
            // }
        }
        // Обновляем статус $this->queue из бд
        //
        // Обновить статусы работы процессов в системе у дочерних процессов
        pcntl_wait($status);
        // Ожидаем
        sleep(5);
    }

    /**
     * Создаем дочерний процесс
     */
    protected function launchJob(): bool
    {
        // Запрашиваем объект на обработку и убираем его из очереди
        // if (!$file = $this->getQueue()) {
        //     $this->log("Больше нет файлов на обработку");
        //     return FALSE;
        // }
        //
        // весь код после pcntl_fork() будет выполняться
        // двумя процессами: родительским и дочерним
        $pid = pcntl_fork();
        if ($pid == -1) {
            // Не удалось создать дочерний процесс
            error_log('Could not launch new job, exiting');
            return FALSE;
        } elseif ($pid) {
            // Этот код выполнится родительским процессом (2)
            $this->currentJobs[$pid] = TRUE;
        } else {
            // Этот код выполнится дочерним процессом (1)
            $child = new DaemonChildClass(getmypid());
            try {
                $child->run();
            } catch (\Throwable $th) {
                $this->log($child->getPid() . '-error');
                error_log($th->getMessage());
            }
            exit();
        }
        return TRUE;
    }

    /**
     * Обработчик событий сигналов
     * @param integer $signo
     * @param integer|null $pid
     * @param mixed $status
     * 
     * todo: пока не работает?... 
     * https://habr.com/ru/articles/355020/ 
     * https://kamashev.name/2011/06/daemons-signals/
     */
    public function childSignalHandler(int $signo, ?int $pid = null, $status = null)
    {
        switch ($signo) {
            case SIGTERM:
                // При получении сигнала завершения работы, устанавливаем флаг
                $this->stop_server = true;
                $this->log("start stop");
                break;
            case SIGCHLD:
                // При получении сигнала от дочернего процесса
                if (!$pid) {
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                    if ($pid > 0) $this->log("$pid status: " . ($status ? 'on' : 'off'));
                }
                // Пока есть завершенные дочерние процессы
                while ($pid > 0) {
                    if ($pid && isset($this->currentJobs[$pid])) {
                        // Удаляем дочерние процессы из списка
                        unset($this->currentJobs[$pid]);
                        $this->log("delete:$pid");
                    }
                    $pid = pcntl_waitpid(-1, $status, WNOHANG);
                    if ($pid > 0) $this->log("$pid status: " . ($status ? 'on' : 'off'));
                }
                // все дочернии процессы завершены и нет больше объектов на обработку
                if (count($this->currentJobs) == 0 && $this->queue == 0) {
                    // останавливаем демона
                    $this->stop_server = true;
                }
                break;
            default:
                // все остальные сигналы
                $this->log("получен сигнал: $signo");
        }
    }

    /**
     * @param string $msg
     */
    public function log(string $msg)
    {
        echo '[' . date('Y-m-d H:i:s') . '] pid:' . $this->pid . "|$msg" . PHP_EOL;
    }

    /**
     * @param int $maxProcesses 
     * @return self
     */
    public function setMaxProcesses(int $maxProcesses): self
    {
        $this->maxProcesses = $maxProcesses;
        return $this;
    }
}
