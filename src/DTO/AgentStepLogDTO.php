<?php

declare(strict_types=1);

namespace App\DTO;

readonly class AgentStepLogDTO
{
    public function __construct(
        public string $agent,
        public string $action,
        public string $status,
        public int $attempt,
        public string $startedAt,
        public int $durationMs,
        public string $detail,
        public ?float $eurTotal = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'agent' => $this->agent,
            'action' => $this->action,
            'status' => $this->status,
            'attempt' => $this->attempt,
            'started_at' => $this->startedAt,
            'duration_ms' => $this->durationMs,
            'detail' => $this->detail,
            'eur_total' => $this->eurTotal,
        ];
    }
}
