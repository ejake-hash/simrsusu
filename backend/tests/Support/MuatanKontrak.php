<?php

namespace Tests\Support;

use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/**
 * Kontrak dan validatornya dimuat sekali per proses tes. Properti statis di trait terpisah per kelas pemakai, sehingga
 * tanpa kelas ini setiap kelas tes memuat ulang seluruh kontrak dan memori habis saat kontrak membesar.
 */
final class MuatanKontrak
{
    public const URI = 'https://kontrak.karsitara.invalid/openapi.json';

    /** @var array<string, mixed>|null */
    private static ?array $kontrak = null;

    private static ?Validator $validator = null;

    /** @return array<string, mixed> */
    public static function kontrak(): array
    {
        return self::$kontrak ??= Yaml::parseFile(base_path('../docs/api/openapi.yaml'));
    }

    public static function validator(): Validator
    {
        if (self::$validator === null) {
            self::$validator = new Validator;
            self::$validator->setMaxErrors(1);
            self::$validator->resolver()?->registerRaw(json_decode((string) json_encode(self::kontrak())), self::URI);
        }

        return self::$validator;
    }
}
