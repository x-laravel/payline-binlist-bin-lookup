<?php

namespace XLaravel\Payline\BinLookup\Binlist\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use XLaravel\Payline\BinLookup\Binlist\BinlistBinLookup;
use XLaravel\Payline\BinLookup\Binlist\Tests\TestCase;
use XLaravel\Payline\Enums\CardScheme;
use XLaravel\Payline\Enums\CardType;

class BinlistBinLookupTest extends TestCase
{
    private BinlistBinLookup $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new BinlistBinLookup();
    }

    public function test_lookup_fills_every_field_binlist_reports(): void
    {
        $this->fakeBin();

        $profile = $this->provider->lookup('45717360');

        $this->assertSame('45717360', $profile->bin);
        $this->assertSame(CardScheme::Visa, $profile->scheme);
        $this->assertSame(CardType::Debit, $profile->type);
        $this->assertSame('Visa Classic/Dankort', $profile->productType);
        $this->assertSame('Jyske Bank A/S', $profile->issuer);
        $this->assertSame('DK', $profile->issuerCountry);
        $this->assertSame('DKK', $profile->currency);
        $this->assertFalse($profile->prepaid);
        $this->assertSame(16, $profile->numberLength);
        $this->assertSame('binlist', $profile->source);
        $this->assertSame('visa', $profile->raw['scheme']);
    }

    public function test_the_card_family_stays_open_because_binlist_reports_none(): void
    {
        $this->fakeBin();

        $profile = $this->provider->lookup('45717360');

        $this->assertNull($profile->family);
        $this->assertNull($profile->category);
        $this->assertNull($profile->issuerCode);
    }

    public function test_a_card_issued_abroad_is_answered_against_a_named_country(): void
    {
        $this->fakeBin();

        $profile = $this->provider->lookup('45717360');

        $this->assertTrue($profile->issuedOutside('TR'));
        $this->assertTrue($profile->issuedIn('DK'));
    }

    public function test_lookup_asks_for_the_first_8_digits(): void
    {
        $this->fakeBin();

        $this->provider->lookup('4571736012345678');

        Http::assertSent(fn ($r) => $r->url() === 'https://lookup.binlist.net/45717360');
    }

    public function test_lookup_names_the_api_version_binlist_expects(): void
    {
        $this->fakeBin();

        $this->provider->lookup('45717360');

        Http::assertSent(fn ($r) => $r->header('Accept-Version') === ['3']);
    }

    public function test_an_unknown_bin_resolves_nothing(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->assertNull($this->provider->lookup('00000000'));
    }

    public function test_a_rate_limited_answer_resolves_nothing(): void
    {
        Http::fake(['*' => Http::response('', 429)]);

        $this->assertNull($this->provider->lookup('45717360'));
    }

    public function test_an_unreachable_service_resolves_nothing_instead_of_throwing(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->assertNull($this->provider->lookup('45717360'));
    }

    public function test_an_answer_naming_neither_scheme_type_nor_country_resolves_nothing(): void
    {
        Http::fake(['*' => Http::response(['number' => null, 'country' => [], 'bank' => []])]);

        $this->assertNull($this->provider->lookup('53504200'));
    }

    public function test_a_partial_answer_still_resolves(): void
    {
        Http::fake(['*' => Http::response([
            'scheme' => 'american express',
            'type' => 'credit',
            'country' => ['alpha2' => 'US', 'currency' => 'USD'],
            'bank' => [],
        ])]);

        $profile = $this->provider->lookup('37828224');

        $this->assertSame(CardScheme::Amex, $profile->scheme);
        $this->assertSame('US', $profile->issuerCountry);
        $this->assertNull($profile->issuer);
        $this->assertNull($profile->prepaid);
    }

    public function test_the_cache_holds_the_payload_rather_than_the_profile(): void
    {
        $this->fakeBin();

        $this->provider->lookup('45717360');

        $this->assertSame('visa', Cache::get('payline:bin-lookup:binlist:45717360')['scheme']);
    }

    public function test_a_cached_payload_is_mapped_without_asking_again(): void
    {
        Cache::put('payline:bin-lookup:binlist:45717360', [
            'scheme' => 'visa',
            'type' => 'credit',
            'country' => ['alpha2' => 'PL'],
        ], 60);

        Http::fake();

        $profile = $this->provider->lookup('45717360');

        $this->assertSame(CardScheme::Visa, $profile->scheme);
        $this->assertSame(CardType::Credit, $profile->type);
        $this->assertSame('PL', $profile->issuerCountry);
        Http::assertNothingSent();
    }

    public function test_a_resolved_profile_is_served_from_the_cache(): void
    {
        $this->fakeBin();

        $this->provider->lookup('45717360');
        $this->provider->lookup('45717360');

        Http::assertSentCount(1);
    }

    public function test_an_unresolved_bin_is_asked_again(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->provider->lookup('00000000');
        $this->provider->lookup('00000000');

        Http::assertSentCount(2);
    }

    public function test_caching_is_off_with_a_ttl_of_zero(): void
    {
        $this->fakeBin();

        $provider = new BinlistBinLookup(['cache_ttl' => 0]);

        $provider->lookup('45717360');
        $provider->lookup('45717360');

        Http::assertSentCount(2);
    }

    public function test_a_configured_base_url_wins(): void
    {
        $this->fakeBin();

        (new BinlistBinLookup(['base_url' => 'https://mirror.test/']))->lookup('45717360');

        Http::assertSent(fn ($r) => $r->url() === 'https://mirror.test/45717360');
    }

    public function test_the_manager_resolves_the_provider_by_name(): void
    {
        $this->assertInstanceOf(BinlistBinLookup::class, $this->app->make('payline.bin_lookup')->driver('binlist'));
    }

    public function test_the_registered_provider_answers_through_the_manager(): void
    {
        $this->fakeBin();

        $profile = $this->app->make('payline.bin_lookup')->lookup('4571736012345678');

        $this->assertSame('DK', $profile->issuerCountry);
    }

    private function fakeBin(): void
    {
        Http::fake(['*' => Http::response([
            'number' => ['length' => 16, 'luhn' => true],
            'scheme' => 'visa',
            'type' => 'debit',
            'brand' => 'Visa Classic/Dankort',
            'prepaid' => false,
            'country' => [
                'numeric' => '208',
                'alpha2' => 'DK',
                'name' => 'Denmark',
                'currency' => 'DKK',
            ],
            'bank' => ['name' => 'Jyske Bank A/S'],
        ])]);
    }
}
