<?php

namespace App\Core\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Prosesor Monolog untuk semua channel: membersihkan exception di konteks, pesan yang memuat
 * galat SQL, dan nilai binding (kunci `bindings`, seperti dari DB::listen).
 */
final class ProsesorPenyamaran implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: PenyamarSql::samarkanTeks($record->message),
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
                PenyamarSql::samarkanException($nilai);
            } elseif (is_string($nilai)) {
                $data[$kunci] = PenyamarSql::samarkanTeks($nilai);
            } elseif (is_array($nilai)) {
                $data[$kunci] = $this->bersihkan($nilai);
            }
        }

        return $data;
    }
}
