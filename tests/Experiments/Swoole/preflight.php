<?php

declare(strict_types=1);
use OpenSwoole\Table;
use Swoole\Http\Server;

foreach (['openswoole', 'sockets', 'pcntl', 'pdo_mysql'] as $extension) {
    if (! extension_loaded($extension)) {
        throw new RuntimeException('Required native extension is missing: '.$extension);
    }
}

foreach ([Table::class, Swoole\Table::class, Server::class] as $class) {
    if (! class_exists($class)) {
        throw new RuntimeException('Required Octane native type is unavailable: '.$class);
    }
}

$table = new Swoole\Table(16);
$table->column('revision', Swoole\Table::TYPE_INT);
$table->column('mirror', Swoole\Table::TYPE_INT);
$table->column('bytes', Swoole\Table::TYPE_STRING, 4096);
$table->create();
$bytes = str_repeat("\0\xff", 2048);
$table->set('active', ['revision' => 0, 'mirror' => 0, 'bytes' => $bytes]);
if ($table->get('active', 'bytes') !== $bytes) {
    throw new RuntimeException('Shared table did not preserve packed binary bytes.');
}
$barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($barrier === false) {
    throw new RuntimeException('Native start barrier failed.');
}
$pid = pcntl_fork();
if ($pid < 0) {
    throw new RuntimeException('Native fork failed.');
}
if ($pid === 0) {
    fclose($barrier[0]);
    if (fread($barrier[1], 1) !== 'S') {
        exit(2);
    }
    fclose($barrier[1]);
    for ($revision = 1; $revision <= 100000; $revision++) {
        if (! $table->set('active', ['revision' => $revision, 'mirror' => $revision, 'bytes' => $bytes])) {
            exit(1);
        }
    }
    exit(0);
}
fclose($barrier[1]);
fwrite($barrier[0], 'S');
fclose($barrier[0]);
$samples = 0;
$intermediate = 0;
$status = 0;
do {
    $row = $table->get('active');
    if (! is_array($row) || $row['revision'] !== $row['mirror'] || $row['bytes'] !== $bytes) {
        throw new RuntimeException('Native whole-row read/write coherence failed.');
    }
    $samples++;
    if (is_int($row['revision']) && $row['revision'] > 0 && $row['revision'] < 100000) {
        $intermediate++;
    }
    $finished = pcntl_waitpid($pid, $status, WNOHANG);
} while ($finished === 0);
if ($finished !== $pid || ! is_int($status) || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
    throw new RuntimeException('Native whole-row writer failed.');
}
if ($intermediate === 0) {
    throw new RuntimeException('Whole-row check did not observe concurrent intermediate writes.');
}

echo json_encode(['php' => PHP_VERSION, 'openswoole' => phpversion('openswoole'),
    'octane_types_available' => true, 'binary_chunk_bytes' => strlen($bytes),
    'whole_row_writes' => 100000, 'whole_row_read_samples' => $samples,
    'intermediate_reads' => $intermediate], JSON_THROW_ON_ERROR).PHP_EOL;
