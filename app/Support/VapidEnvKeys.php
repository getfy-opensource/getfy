<?php

namespace App\Support;

use Jose\Component\Core\Util\Base64UrlSafe;

/**
 * Normaliza chaves VAPID vindas do .env para o formato esperado por minishlink/web-push (Base64 URL-safe).
 */
final class VapidEnvKeys
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        // BOM UTF-8 (comum ao colar chaves de editores / exports)
        if (str_starts_with($v, "\xEF\xBB\xBF")) {
            $v = substr($v, 3);
        }
        $v = trim($v, " \t\n\r\0\x0B\"'");
        $v = str_replace(["\r", "\n", ' ', "\t"], '', $v);
        if ($v === '') {
            return null;
        }
        if (str_contains($v, '+') || str_contains($v, '/')) {
            $v = strtr($v, ['+' => '-', '/' => '_']);
        }
        // Remove qualquer caractere fora do alfabeto base64url (NBSP, zero-width, etc.)
        $v = preg_replace('/[^A-Za-z0-9\-_=]/', '', $v) ?? '';
        if ($v === '') {
            return null;
        }

        return $v;
    }

    /**
     * Verifica se o par (após normalização) decodifica para 65 bytes (pública) e 32 bytes (privada), como exige o Web Push VAPID.
     */
    public static function normalizedPairLooksValid(?string $rawPublic, ?string $rawPrivate): bool
    {
        $pub = self::normalize($rawPublic);
        $priv = self::normalize($rawPrivate);
        if ($pub === null || $priv === null) {
            return false;
        }
        try {
            $pubBin = Base64UrlSafe::decode($pub);
            $privBin = Base64UrlSafe::decode($priv);
        } catch (\Throwable) {
            return false;
        }

        return strlen($pubBin) === 65 && strlen($privBin) === 32;
    }
}
