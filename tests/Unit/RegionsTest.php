<?php

namespace Bananaland\Consent\Tests\Unit;

use Bananaland\Consent\Config;
use Bananaland\Consent\Regions\Resolver;
use Bananaland\Consent\Regions\Rules;
use Bananaland\Consent\Tests\TestCase;
use Brain\Monkey\Functions;

final class RegionsTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
        $this->server = $_SERVER;
        Functions\when('get_stylesheet_directory')->justReturn('/nonexistent');
        Functions\when('get_template_directory')->justReturn('/nonexistent');
        Functions\when('get_stylesheet_directory_uri')->justReturn('');
        Functions\when('get_template_directory_uri')->justReturn('');
        Functions\when('get_option')->justReturn([]);
        Functions\when('wp_get_environment_type')->justReturn('production');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        unset($_GET['blc_test_region']);
        parent::tearDown();
    }

    public static function modes(): array
    {
        return [
            'germany' => ['DE', null, 'opt-in'],
            'uk' => ['GB', null, 'opt-in'],
            'quebec' => ['CA', 'QC', 'opt-in'],
            'ontario' => ['CA', 'ON', 'notice'],
            'connecticut' => ['US', 'CT', 'opt-out'],
            'us no state' => ['US', null, 'opt-out'],
            'japan' => ['JP', null, 'notice'],
            'unknown' => [null, null, 'opt-in'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_mode_for_location(?string $country, ?string $region, string $expected): void
    {
        $this->assertSame($expected, Rules::mode($country, $region));
    }

    public function test_site_overrides_win_subdivision_first(): void
    {
        Functions\when('get_option')->justReturn(['regions' => ['overrides' => ['US' => 'opt-in', 'US-CT' => 'notice']]]);
        $this->assertSame('opt-in', Rules::mode('US', 'TX'));
        $this->assertSame('notice', Rules::mode('US', 'CT'));
    }

    public function test_unknown_mode_setting(): void
    {
        Functions\when('get_option')->justReturn(['regions' => ['unknown_mode' => 'opt-out']]);
        $this->assertSame('opt-out', Rules::mode(null, null));
    }

    public function test_resolver_prefers_wpengine_then_cloudflare(): void
    {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
        $this->assertSame(['country' => 'FR', 'region' => null, 'source' => 'cloudflare'], Resolver::resolve());

        $_SERVER['HTTP_GEOIP_COUNTRY_CODE'] = 'us';
        $_SERVER['HTTP_GEOIP_REGION'] = 'ct';
        $this->assertSame(['country' => 'US', 'region' => 'CT', 'source' => 'wpengine'], Resolver::resolve());
    }

    public function test_cloudflare_unknown_codes_are_null(): void
    {
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
        $this->assertNull(Resolver::resolve()['country']);
    }

    public function test_test_override_only_outside_production(): void
    {
        $_SERVER['HTTP_X_BLC_TEST_REGION'] = 'DE';
        $this->assertNotSame('DE', Resolver::resolve()['country']);

        Functions\when('wp_get_environment_type')->justReturn('local');
        $_SERVER['HTTP_X_BLC_TEST_REGION'] = 'US-CA';
        $this->assertSame(['country' => 'US', 'region' => 'CA', 'source' => 'test'], Resolver::resolve());
    }

    public function test_empty_test_header_falls_back_to_query(): void
    {
        Functions\when('wp_get_environment_type')->justReturn('local');
        $_SERVER['HTTP_X_BLC_TEST_REGION'] = '';
        $_GET['blc_test_region'] = 'DE';
        $this->assertSame('DE', Resolver::resolve()['country']);
    }
}
