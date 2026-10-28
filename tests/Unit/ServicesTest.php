<?php

namespace Bananaland\Consent\Tests\Unit;

use Bananaland\Consent\Config;
use Bananaland\Consent\Services\Registry;
use Bananaland\Consent\Services\Renderer;
use Bananaland\Consent\Services\ScriptTagger;
use Bananaland\Consent\Tests\TestCase;
use Brain\Monkey\Functions;

final class ServicesTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
        Registry::reset();
        Functions\when('get_stylesheet_directory')->justReturn('/nonexistent');
        Functions\when('get_template_directory')->justReturn('/nonexistent');
        Functions\when('get_stylesheet_directory_uri')->justReturn('');
        Functions\when('get_template_directory_uri')->justReturn('');
        Functions\when('get_option')->alias(fn() => $this->saved);
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('sanitize_key')->alias(fn($k) => preg_replace('/[^a-z0-9_\-]/', '', strtolower($k)));
    }

    private function services(array $rows): void
    {
        $this->saved = ['services' => $rows];
        Config::reset();
        Registry::reset();
    }

    public function test_ga4_renders_with_measurement_id_and_is_held(): void
    {
        $this->services([['uid' => 'ga', 'preset' => 'ga4', 'settings' => ['id' => 'G-ABC1234']]]);
        $out = Renderer::render('head');
        $this->assertStringContainsString("gtag('config','G-ABC1234')", $out);
        $this->assertStringContainsString('type="text/plain" data-consent="analytics" data-blc-service="ga"', $out);
        $this->assertStringNotContainsString('<script async', $out);
    }

    public function test_invalid_id_is_skipped_with_error(): void
    {
        $this->services([['preset' => 'ga4', 'settings' => ['id' => 'UA-123']]]);
        $this->assertSame([], Registry::all());
        $this->assertCount(1, Registry::errors());
    }

    public function test_gtm_is_live_and_advanced_mode_releases_google_tags(): void
    {
        $this->services([
            ['uid' => 'tm', 'preset' => 'gtm', 'settings' => ['id' => 'GTM-AB12CD']],
            ['uid' => 'ga', 'preset' => 'ga4', 'settings' => ['id' => 'G-ABC1234']],
        ]);
        $out = Renderer::render('head');
        $this->assertStringContainsString("'dataLayer','GTM-AB12CD'", $out);
        $this->assertStringNotContainsString('data-blc-service="tm"', $out);

        $this->saved['consent_mode'] = ['mode' => 'advanced'];
        Config::reset();
        $this->assertStringNotContainsString('text/plain', Renderer::render('head'));
    }

    public function test_hold_preserves_module_type_and_skips_json(): void
    {
        $html = '<script type="module" src="/a.js"></script><script type="application/ld+json">{}</script>';
        $out = Renderer::hold($html, 'marketing');
        $this->assertStringContainsString('<script type="text/plain" data-consent="marketing" data-blc-type="module" src="/a.js">', $out);
        $this->assertStringContainsString('<script type="application/ld+json">', $out);
    }

    public function test_hold_moves_iframe_src_and_leaves_necessary_alone(): void
    {
        $this->assertSame(
            '<iframe data-blc-src="https://x.test/e" data-consent="preferences" width="1"></iframe>',
            Renderer::hold('<iframe src="https://x.test/e" width="1"></iframe>', 'preferences')
        );
        $this->assertSame('<script>1</script>', Renderer::hold('<script>1</script>', 'necessary'));
    }

    public function test_custom_service_cookies_and_category(): void
    {
        $this->services([[
            'uid' => 'chat', 'preset' => 'custom', 'category' => 'preferences', 'label' => 'Chat',
            'settings' => ['body' => '<script src="https://chat.test/w.js"></script>'],
            'cookies' => [['name' => 'chat_id', 'duration' => '1 year', 'purpose' => 'Chat session']],
        ]]);
        $this->assertSame(['chat_id'], Registry::cookie_names_by_category()['preferences']);
        $this->assertStringContainsString('data-consent="preferences"', Renderer::render('body'));
    }

    public function test_script_tagger_only_rewrites_registered_handles(): void
    {
        ScriptTagger::require('hotjar', 'analytics');
        $tag = '<script src="/hj.js" id="hotjar-js"></script>';
        $this->assertStringContainsString('data-consent="analytics"', ScriptTagger::filter($tag, 'hotjar'));
        $this->assertSame($tag, ScriptTagger::filter($tag, 'other'));
    }
}
