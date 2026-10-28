<?php

namespace Bananaland\Consent\Tests\Unit;

use Bananaland\Consent\Config;
use Bananaland\Consent\Tests\TestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
        Functions\when('get_stylesheet_directory')->justReturn('/nonexistent/child');
        Functions\when('get_template_directory')->justReturn('/nonexistent/parent');
        Functions\when('get_stylesheet_directory_uri')->justReturn('https://example.test/child');
        Functions\when('get_template_directory_uri')->justReturn('https://example.test/parent');
    }

    public function test_defaults_fill_missing_saved_keys(): void
    {
        Functions\when('get_option')->justReturn(['banner' => ['layout' => 'box']]);

        $this->assertSame('box', Config::get('banner.layout'));
        $this->assertSame('bottom', Config::get('banner.position'));
        $this->assertSame('opt-in', Config::get('regions.unknown_mode'));
    }

    public function test_lists_are_replaced_not_merged(): void
    {
        Functions\when('get_option')->justReturn([
            'services' => [['id' => 'a', 'preset' => 'ga4']],
        ]);
        $this->assertCount(1, Config::get('services'));
    }

    public function test_theme_config_overrides_saved_and_filter_runs_last(): void
    {
        $dir = sys_get_temp_dir() . '/blc-theme-' . uniqid();
        mkdir($dir . '/consent', 0777, true);
        file_put_contents($dir . '/consent/config.php', "<?php return ['banner' => ['layout' => 'modal']];");
        Functions\when('get_stylesheet_directory')->justReturn($dir);
        Functions\when('get_option')->justReturn(['banner' => ['layout' => 'box']]);

        $this->assertSame('modal', Config::get('banner.layout'));
        $this->assertSame(['banner' => ['layout' => 'modal']], Config::theme());

        Config::reset();
        Filters\expectApplied('blc_config')->once()->andReturnUsing(function ($c) {
            $c['banner']['layout'] = 'bar';
            return $c;
        });
        $this->assertSame('bar', Config::get('banner.layout'));
    }

    public function test_get_returns_default_for_unknown_path(): void
    {
        Functions\when('get_option')->justReturn([]);
        $this->assertSame('x', Config::get('nope.nothing', 'x'));
    }
}
