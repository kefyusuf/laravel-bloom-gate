<?php

declare(strict_types=1);
use Swoole\Table;

final class ParentRuntime
{
    public static ?Table $control = null;

    public static function initialize(): void
    {
        $entropy = random_bytes(16);
        if (! is_string($entropy)) {
            throw new RuntimeException('Runtime incarnation entropy is invalid.');
        }
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
    }
}
