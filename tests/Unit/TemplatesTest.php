<?php

namespace Bananaland\Consent\Tests\Unit;

use Bananaland\Consent\Config;
use Bananaland\Consent\Templates\Locator;
use Bananaland\Consent\Tests\TestCase;
use Brain\Monkey\Functions;

final class TemplatesTest extends TestCase
{
    private string $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
        $this->theme = sys_get_temp_dir() . '/blc-theme-' . uniqid();
        mkdir($this->theme . '/consent', 0777, true);
        Functions\when('get_stylesheet_directory')->justReturn($this->theme);
        Functions\when('get_template_directory')->justReturn($this->theme);
        Functions\when('get_stylesheet_directory_uri')->justReturn('https://example.test/theme');
        Functions\when('get_template_directory_uri')->justReturn('https://example.test/theme');
        Functions\when('sanitize_file_name')->returnArg();
    }

    public function test_theme_template_wins_and_plugin_is_fallback(): void
    {
        file_put_contents($this->theme . '/consent/banner.php', "<?php\n/** @version 0.1.0 */");
        $this->assertSame($this->theme . '/consent/banner.php', Locator::locate('banner'));
        $this->assertSame(BLC_DIR . 'templates/preferences.php', Locator::locate('preferences'));
    }

    public function test_outdated_override_detection(): void
    {
        file_put_contents($this->theme . '/consent/banner.php', "<?php\n/** @version 0.0.1 */");
        file_put_contents($this->theme . '/consent/preferences.php', "<?php\n// no version");
        $overrides = Locator::overrides();
        $this->assertTrue($overrides['banner']['outdated']);
        $this->assertSame('0.0.1', $overrides['banner']['theme_version']);
        $this->assertTrue($overrides['preferences']['outdated']);
        $this->assertArrayNotHasKey('category', $overrides);
    }

    public function test_every_plugin_template_declares_a_version(): void
    {
        foreach (Locator::TEMPLATES as $name) {
            $this->assertNotNull(Locator::version(BLC_DIR . "templates/$name.php"), $name);
        }
    }
}
