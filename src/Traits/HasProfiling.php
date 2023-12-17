<?php

namespace Conveyor\Traits;

trait HasProfiling
{
    protected ?int $startMemoryUsage = null;
    protected ?int $endMemoryUsage = null;

    public function setStartMemoryUsage(): void
    {
        $this->startMemoryUsage = memory_get_usage();
    }

    public function setEndMemoryUsage(): void
    {
        $this->endMemoryUsage = memory_get_usage();
    }

    /**
     * @return int Memory usage in bytes
     */
    public function getMemoryUsage(): int
    {
        return $this->endMemoryUsage - $this->startMemoryUsage;
    }

    public function printProfile(): void
    {
        $this->setEndMemoryUsage();

        echo PHP_EOL . '=== Conveyor Profile (bytes) ===';
        echo PHP_EOL . 'Start Memory: ' . $this->startMemoryUsage;
        echo PHP_EOL . 'End Memory: ' . $this->endMemoryUsage;
        echo PHP_EOL . 'Memory Difference: ' . $this->getMemoryUsage();
        echo PHP_EOL . '==============================';
    }
}
