<?php

namespace App\Support;

/**
 * Guarda o tenant ativo. Na API vem do usuário do JWT; nos jobs da fila é
 * definido explicitamente a partir do registro que está sendo processado.
 */
class TenantContext
{
    private static ?int $id = null;

    public static function set(?int $id): void
    {
        self::$id = $id;
    }

    public static function id(): ?int
    {
        if (self::$id !== null) {
            return self::$id;
        }
        $user = auth('api')->hasUser() ? auth('api')->user() : null;

        return $user?->tenant_id;
    }
}
