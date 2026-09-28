<?php

namespace XLaravel\Payline\BinLookup\Binlist;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use XLaravel\Payline\Contracts\BinLookupProvider;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardScheme;
use XLaravel\Payline\Enums\CardType;

class BinlistBinLookup implements BinLookupProvider
{
    private const string BASE_URL = 'https://lookup.binlist.net';

    private const int CACHE_TTL = 2592000;

    private const string CACHE_PREFIX = 'payline:bin-lookup:binlist:';

    private const int TIMEOUT = 5;

    private readonly string $baseUrl;

    private readonly int $cacheTtl;

    private readonly ?string $cacheStore;

    private readonly int $timeout;

    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim($config['base_url'] ?? self::BASE_URL, '/');
        $this->cacheTtl = (int) ($config['cache_ttl'] ?? self::CACHE_TTL);
        $this->cacheStore = $config['cache_store'] ?? null;
        $this->timeout = (int) ($config['timeout'] ?? self::TIMEOUT);
    }

    public function lookup(string $bin): ?CardProfile
    {
        $bin = substr($bin, 0, 8);

        if ($this->cacheTtl <= 0) {
            return $this->fetch($bin);
        }

        $cache = Cache::store($this->cacheStore);
        $key = self::CACHE_PREFIX . $bin;
        $cached = $cache->get($key);

        if ($cached instanceof CardProfile) {
            return $cached;
        }

        $profile = $this->fetch($bin);

        if ($profile !== null) {
            $cache->put($key, $profile, $this->cacheTtl);
        }

        return $profile;
    }

    private function fetch(string $bin): ?CardProfile
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['Accept-Version' => '3'])
                ->get($this->baseUrl . '/' . $bin);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $scheme = CardScheme::parse($body['scheme'] ?? null);
        $type = CardType::parse($body['type'] ?? null);
        $country = $this->text($body['country']['alpha2'] ?? null);

        if ($scheme === null && $type === null && $country === null) {
            return null;
        }

        return new CardProfile(
            bin: $bin,
            scheme: $scheme,
            type: $type,
            productType: $this->text($body['brand'] ?? null),
            issuer: $this->text($body['bank']['name'] ?? null),
            issuerCountry: $country,
            currency: $this->text($body['country']['currency'] ?? null),
            prepaid: isset($body['prepaid']) ? (bool) $body['prepaid'] : null,
            numberLength: isset($body['number']['length']) ? (int) $body['number']['length'] : null,
            source: 'binlist',
            raw: $body,
        );
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
