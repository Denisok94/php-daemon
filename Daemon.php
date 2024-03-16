<?php

include 'DaemonClass.php';

// // все логи в файлы
// $baseDir = dirname(__FILE__);
// ini_set('error_log', $baseDir . '/error.log');
// fclose(STDIN);
// fclose(STDOUT);
// fclose(STDERR);
// $STDIN = fopen('/dev/null', 'r');
// $STDOUT = fopen($baseDir . '/application.log', 'ab');
// $STDERR = fopen($baseDir . '/daemon.log', 'ab');

// // Создаем дочерний процесс
// // весь код после pcntl_fork() будет выполняться двумя процессами: родительским и дочерним
// $child_pid = pcntl_fork();
// if ($child_pid) {
//     // Выходим из родительского, привязанного к консоли, процесса
//     exit();
// }
// // Делаем основным процессом дочерний.
// posix_setsid();
// // Дальнейший код выполнится только дочерним процессом, который уже отвязан от консоли

try {
    $daemon = new DaemonClass();
    $daemon->run();
} catch (\Throwable $th) {
    error_log($th->getMessage());
}
// ps -ela 
// kill -9 PID
