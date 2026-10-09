<?php

namespace App\Jobs;

use App\Actions\Certificates\RecoverCertificateEmissionDates;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoverEmissionDatesBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $sourceIndex = 0, public int $afterId = 0) {}

    public function uniqueId(): string
    {
        return "emission-dates:{$this->sourceIndex}:{$this->afterId}";
    }

    public function handle(RecoverCertificateEmissionDates $recovery): void
    {
        $result = $recovery->handleBatch($this->sourceIndex, $this->afterId);

        if ($result['hasMore']) {
            self::dispatch($result['sourceIndex'], $result['afterId'])->onConnection($this->connection)->delay(now()->addSecond());
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('No se pudo completar el lote de fechas de emisión.', [
            'source_index' => $this->sourceIndex, 'after_id' => $this->afterId, 'exception' => $exception,
        ]);
    }
}
