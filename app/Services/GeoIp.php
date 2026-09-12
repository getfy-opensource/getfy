<?php

namespace App\Services;

use App\Support\CheckoutCurrencyCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeoIp
{
    /** Locales suportados no checkout */
    public const LOCALE_PT_BR = 'pt_BR';

    public const LOCALE_EN = 'en';

    public const LOCALE_ES = 'es';

    /** Moedas suportadas no checkout */
    public const CURRENCY_BRL = 'BRL';

    public const CURRENCY_USD = 'USD';

    public const CURRENCY_EUR = 'EUR';

    /** Países que usam euro (sigla ISO 3166-1 alpha-2) */
    private const EUR_COUNTRIES = [
        'AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK',
    ];

    private const LOCALE_BY_COUNTRY = [
        'BR' => self::LOCALE_PT_BR,
        'ES' => self::LOCALE_ES,
        'MX' => self::LOCALE_ES,
        'AR' => self::LOCALE_ES,
        'CO' => self::LOCALE_ES,
        'CL' => self::LOCALE_ES,
        'PE' => self::LOCALE_ES,
        'US' => self::LOCALE_EN,
        'GB' => self::LOCALE_EN,
        'CA' => self::LOCALE_EN,
        'AU' => self::LOCALE_EN,
        'IN' => self::LOCALE_EN,
    ];

    private int $cacheTtlSeconds = 86400; // 1 day

    public function __construct(?int $cacheTtlSeconds = null)
    {
        if ($cacheTtlSeconds !== null) {
            $this->cacheTtlSeconds = $cacheTtlSeconds;
        }
    }

    /**
     * País e sugestões a partir da request: cabeçalhos de CDN (Cloudflare, Vercel) ou IP + ip-api.
     *
     * @return array{country_code: string|null, suggested_locale: string, suggested_currency: string}
     */
    public function getSuggestionsForRequest(Request $request): array
    {
        $fromHeader = $this->countryFromTrustedHeaders($request);
        if ($fromHeader !== null) {
            return [
                'country_code' => $fromHeader,
                'suggested_locale' => $this->localeForCountry($fromHeader),
                'suggested_currency' => $this->currencyForCountry($fromHeader),
            ];
        }

        return $this->getSuggestionsForIp((string) ($request->ip() ?? ''));
    }

    /**
     * Só headers CDN — sem HTTP ao ip-api (para TTFB do checkout).
     * Sem header: fallback Brasil (locale/moeda), country_code null.
     *
     * @return array{country_code: string|null, suggested_locale: string, suggested_currency: string}
     */
    public function getSuggestionsFromHeadersOrDefault(Request $request): array
    {
        $fromHeader = $this->countryFromTrustedHeaders($request);
        if ($fromHeader !== null) {
            return [
                'country_code' => $fromHeader,
                'suggested_locale' => $this->localeForCountry($fromHeader),
                'suggested_currency' => $this->currencyForCountry($fromHeader),
            ];
        }

        return [
            'country_code' => null,
            'suggested_locale' => self::LOCALE_PT_BR,
            'suggested_currency' => self::CURRENCY_BRL,
        ];
    }

    /**
     * Obtém país (e sugestões de locale/moeda) a partir do IP.
     * Usa cache por IP.
     *
     * @return array{country_code: string|null, suggested_locale: string, suggested_currency: string}
     */
    public function getSuggestionsForIp(string $ip): array
    {
        $cacheKey = 'geo_ip:'.md5($ip);
        $countryCode = Cache::remember($cacheKey, $this->cacheTtlSeconds, function () use ($ip) {
            return $this->fetchCountryCode($ip);
        });
        if ($countryCode === null) {
            return [
                'country_code' => null,
                'suggested_locale' => self::LOCALE_PT_BR,
                'suggested_currency' => self::CURRENCY_BRL, // fallback: Brasil como padrão (localhost/indeterminado)
            ];
        }
        return [
            'country_code' => $countryCode,
            'suggested_locale' => $this->localeForCountry($countryCode),
            'suggested_currency' => $this->currencyForCountry($countryCode),
        ];
    }

    private function countryFromTrustedHeaders(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'X-Vercel-IP-Country'] as $headerName) {
            $raw = $request->header($headerName);
            if ($raw === null || $raw === '') {
                continue;
            }
            $code = strtoupper(trim((string) (is_array($raw) ? ($raw[0] ?? '') : $raw)));
            if (strlen($code) !== 2) {
                continue;
            }
            if (in_array($code, ['XX', 'T1'], true)) {
                continue;
            }

            return $code;
        }

        return null;
    }

    private function fetchCountryCode(string $ip): ?string
    {
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return null;
        }
        try {
            $url = 'https://ip-api.com/json/'.urlencode($ip).'?fields=countryCode';
            $response = Http::timeout(3)->get($url);
            if (! $response->successful()) {
                return null;
            }
            $data = $response->json();
            $code = $data['countryCode'] ?? null;
            return is_string($code) && strlen($code) === 2 ? strtoupper($code) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function localeForCountry(string $countryCode): string
    {
        return self::LOCALE_BY_COUNTRY[$countryCode] ?? self::LOCALE_EN;
    }

    private function currencyForCountry(string $countryCode): string
    {
        return CheckoutCurrencyCatalog::currencyForCountry($countryCode);
    }
}
