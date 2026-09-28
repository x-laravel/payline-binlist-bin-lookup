# payline-binlist-bin-lookup

[![Tests](https://github.com/x-laravel/payline-binlist-bin-lookup/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/payline-binlist-bin-lookup/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

[binlist.net](https://binlist.net) BIN lookup provider for
[x-laravel/payline](https://github.com/x-laravel/payline).

binlist answers the scheme, the funding type and the issuing country behind the first
eight digits of a card number, for cards issued anywhere. It is the provider to reach
for when a routing policy needs to know where a card comes from, which no Turkish BIN
service reports.

It names no card family, so commission rates keyed on `bonus` or `maximum` will not
match a profile resolved here. Pair it with a provider that does, or key those rates on
the card type alone.

## Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- x-laravel/payline

## Installation

```bash
composer require x-laravel/payline-binlist-bin-lookup
```

## Configuration

Name `binlist` as the BIN lookup driver in `config/payline.php`:

```php
'bin_lookup' => [
    'providers' => ['binlist'],
    'drivers' => [],
],
```

binlist names no card family, so a shop routing on Turkish loyalty programs lists a
provider that does before it and lets Payline merge the two answers:

```php
'providers' => ['hoppa', 'binlist'],
```

The service takes no credentials, and there is one address for everyone, so
`payline.test_mode` does not apply here.

| Key | Default | Meaning |
|-----|---------|---------|
| `base_url` | `https://lookup.binlist.net` | Address to query |
| `cache_ttl` | `7776000` | Seconds a resolved profile is kept; `0` turns caching off |
| `cache_store` | `null` | Cache store name; the application default when absent |
| `timeout` | `5` | Seconds to wait for an answer |

## Rate Limits

The free endpoint allows a handful of requests per hour per address, and answers `429`
once that is spent. A resolved profile is cached for 90 days, which is what makes the
free tier workable: a busy shop asks about each range once a quarter.

The fields this service fills are the durable ones. A country and a scheme belong to the
range by assignment and do not move, unlike an issuer's trading name or a loyalty
program, which change when banks merge or leave a scheme. A provider that answers those
deserves a shorter life than this one.

An answer that resolves nothing is not cached, so a rate-limited hour does not poison
the cache with blanks.

What is stored is binlist's own payload, not the profile built from it. The profile is
rebuilt on every read, so a correction to this mapping or a new field on `CardProfile`
takes effect immediately instead of waiting the cache out.

Requests carry `Accept-Version: 3`, the version this mapping was written against.

## Failure Is Quiet

Every failure resolves to `null` rather than an exception: an unknown BIN (`404`), a
spent rate limit (`429`), a timeout and an unreachable host alike. A BIN lookup only
improves gateway selection, so it must never be the reason a payment fails. Routing
falls back to the default gateway, exactly as it does when no lookup is configured.

The timeout defaults to five seconds for the same reason.

## What binlist Fills

| `CardProfile` | binlist | Note |
|---------------|---------|------|
| `bin` | the queried digits | |
| `scheme` | `scheme` | |
| `type` | `type` | |
| `productType` | `brand` | such as `Visa Classic/Dankort` |
| `issuer` | `bank.name` | |
| `issuerCountry` | `country.alpha2` | ISO 3166-1 alpha-2 |
| `currency` | `country.currency` | the issuing country's currency, not the card's |
| `prepaid` | `prepaid` | |
| `numberLength` | `number.length` | |
| `source` | always `binlist` | |
| `raw` | the whole payload | bank url, phone, city and the country's coordinates live here |

`family`, `category`, `issuerCode` and `localSchemes` stay null. binlist reports none of
them, and most fields are absent for any given BIN, so a profile from here is usually
partly filled.

## Usage

Payline calls the provider on its own while routing a payment. To ask directly:

```php
use XLaravel\Payline\BinLookupManager;

$profile = app(BinLookupManager::class)->lookup('4571736012345678');

$profile?->issuerCountry;          // 'DK'
$profile?->issuedOutside('TR');    // true
$profile?->scheme;                 // CardScheme::Visa
```

A routing policy can then keep foreign cards on one gateway:

```php
class ForeignCardsGoToQnb implements GatewayRoutingPolicy
{
    public function allows(Gateway $gateway, PaymentRequest $request, TransactionType $operation): bool
    {
        $profile = $request->card?->profile ?? $request->cardProfile;

        if ($profile === null || ! $profile->issuedOutside(config('payline.country'))) {
            return true;
        }

        return $gateway->getName() === 'qnb';
    }
}
```

```php
'routing' => [
    'policies' => [ForeignCardsGoToQnb::class],
],
```

A profile that names no country answers `false` to both `issuedIn()` and
`issuedOutside()`, so a policy written this way steps aside rather than rejecting a card
it knows nothing about.

## Testing

```bash
composer test
```

```bash
docker compose --profile php84 up --build
```

## License

MIT
