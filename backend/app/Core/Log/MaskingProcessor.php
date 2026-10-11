<?php

namespace App\Core\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Prosesor Monolog untuk semua channel: membersihkan exception di konteks, pesan yang memuat
 * error SQL, dan nilai binding (kunci `bindings`, seperti dari DB::listen).
 */
final class MaskingProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: SqlMasker::samarkanTeks($record->message),
            context: $this->bersihkan($record->context),
        );
    }

    /** @param  array<mixed>  $data */
    private function bersihkan(array $data): array
    {
        foreach ($data as $kunci => $nilai) {
            if ($kunci === 'bindings' && is_array($nilai)) {
                $data[$kunci] = array_fill(0, count($nilai), '?');
            } elseif ($nilai instanceof Throwable) {
                SqlMasker::samarkanException($nilai);
            } elseif (is_string($nilai)) {
                $data[$kunci] = SqlMasker::samarkanTeks($nilai);
            } elseif (is_array($nilai)) {
                $data[$kunci] = $this->bersihkan($nilai);
            }
        }

        return $data;
    }
}
