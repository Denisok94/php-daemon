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
    /** @var Process */
    private $process;
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

    public function run()
    {
        if ($this->isDaemonActive(__DIR__ . '/daemon.pid')) {
            $this->log('Daemon already active');
            exit;
        }
        $this->log("Running daemon controller");
        file_put_contents(__DIR__ . '/daemon.pid', $this->pid);

        // Пока $stop_server не установится в TRUE, гоняем бесконечный цикл
        while (!$this->stop_server) {
            // Если уже запущено максимальное количество дочерних процессов, ждем их завершения
            while (count($this->currentJobs) >= $this->maxProcesses) {
                $this->log("Maximum children allowed, waiting...");
                //
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
                // Обновить статусы работы у дочерних процессов
                // https://stackoverflow.com/questions/19546588/process-from-pcntl-fork-not-terminating
                pcntl_wait($status);
                sleep(5);
            }

            if (!$this->launchJob()) {
                exit;
            }
        }
    }

    /**
     * https://habr.com/ru/articles/40432/
     * @param [type] $pid_file
     * @return boolean
     */
    function isDaemonActive($pid_file)
    {
        if (is_file($pid_file)) {
            $pid = file_get_contents($pid_file);
            //проверяем на наличие процесса
            if (posix_kill($pid, 0)) {
                //демон уже запущен
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

    /**
     * Создаем дочерний процесс
     */
    protected function launchJob(): bool
    {
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
                $this->log("stop");
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
        echo '[' . microtime() . '] pid:' . $this->pid . "|$msg" . PHP_EOL;
    }
}
