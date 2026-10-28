<?php

namespace Bananaland\Consent\Tests\Unit;

use Bananaland\Consent\Config;
use Bananaland\Consent\ConsentLog\Anonymizer;
use Bananaland\Consent\Rest\Controller;
use Bananaland\Consent\Updater\GitHubUpdater;
use Bananaland\Consent\Tests\TestCase;
use Brain\Monkey\Functions;

final class LogAndUpdaterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
        Functions\when('get_stylesheet_directory')->justReturn('/nonexistent');
        Functions\when('get_template_directory')->justReturn('/nonexistent');
        Functions\when('get_stylesheet_directory_uri')->justReturn('');
        Functions\when('get_template_directory_uri')->justReturn('');
        Functions\when('get_option')->justReturn([]);
        Functions\when('wp_parse_url')->alias('parse_url');
    }

    public function test_ip_truncation(): void
    {
        $this->assertSame('203.0.113.0', Anonymizer::truncate_ip('203.0.113.77'));
        $this->assertSame('2001:db8:85a3::', Anonymizer::truncate_ip('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
        $this->assertSame('', Anonymizer::truncate_ip('nonsense'));
    }

    private function body(array $over = []): array
    {
        return $over + [
            'id' => '3f2b8c1e-9d4a-4b6e-8f1a-2c3d4e5f6a7b',
            'categories' => ['analytics'],
            'mode' => 'opt-in',
            'method' => 'custom',
            'policyVersion' => '1',
            'url' => 'https://example.test/about/?utm=x',
            'region' => 'DE',
        ];
    }

    public function test_valid_record_is_cleaned(): void
    {
        $record = Controller::validate($this->body());
        $this->assertSame(['analytics', 'necessary'], $record['categories']);
        $this->assertSame('/about/', $record['url']);
        $this->assertSame('DE', $record['region']);
    }

    public function test_invalid_records_rejected(): void
    {
        $this->assertSame('invalid_id', Controller::validate($this->body(['id' => 'abc'])));
        $this->assertSame('invalid_categories', Controller::validate($this->body(['categories' => ['tracking']])));
        $this->assertSame('invalid_mode', Controller::validate($this->body(['mode' => 'maybe'])));
        $this->assertSame('invalid_method', Controller::validate($this->body(['method' => 'hack'])));
    }

    public function test_release_parsing_requires_zip_asset(): void
    {
        $release = GitHubUpdater::parse_release([
            'tag_name' => 'v1.2.0',
            'assets' => [['name' => 'bananaland-consent.zip', 'browser_download_url' => 'https://github.com/x.zip']],
        ]);
        $this->assertSame('1.2.0', $release['version']);
        $this->assertSame('https://github.com/x.zip', $release['package']);

        $this->assertNull(GitHubUpdater::parse_release(['tag_name' => 'v1.2.0', 'assets' => []]));
        $this->assertNull(GitHubUpdater::parse_release(['tag_name' => 'v1.3.0', 'prerelease' => true, 'assets' => [['name' => 'bananaland-consent.zip', 'browser_download_url' => 'x']]]));
    }

    public function test_dev_install_guard(): void
    {
        $dir = sys_get_temp_dir() . '/blc-dev-' . uniqid();
        mkdir($dir . '/.git', 0777, true);
        $this->assertTrue(GitHubUpdater::is_dev_install($dir));

        $plain = sys_get_temp_dir() . '/blc-plain-' . uniqid();
        mkdir($plain);
        $this->assertFalse(GitHubUpdater::is_dev_install($plain));

        $link = sys_get_temp_dir() . '/blc-link-' . uniqid();
        symlink($plain, $link);
        $this->assertTrue(GitHubUpdater::is_dev_install($link));
    }
}
