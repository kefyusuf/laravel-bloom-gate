<?php

declare(strict_types=1);
use Kefyusuf\BloomGate\Tests\Experiments\Swoole\SharedMemoryDomain;
use Swoole\Table;

final class ParentRuntime
{
    public static ?Table $control = null;

    public static ?SharedMemoryDomain $domain = null;

    public static function initialize(): void
    {
        /** @var non-empty-string $entropy Native PHP guarantees string; older analyzers model mixed. */
        $entropy = random_bytes(16);
        $control = new Table(16);
        $control->column('incarnation', Table::TYPE_STRING, 32);
        $control->column('sentinel', Table::TYPE_STRING, 32);
        $control->column('parent_pid', Table::TYPE_INT);
        $control->column('marker_pid', Table::TYPE_INT);
        $control->column('published', Table::TYPE_INT);
        if (! $control->create() || ! $control->set('active', [
            'incarnation' => bin2hex($entropy),
            'sentinel' => 'parent-created', 'parent_pid' => getmypid(), 'marker_pid' => 0, 'published' => 0,
        ])) {
            throw new RuntimeException('Parent control table could not be initialized.');
        }
        self::$control = $control;
        self::$domain = new SharedMemoryDomain;
    }
}
