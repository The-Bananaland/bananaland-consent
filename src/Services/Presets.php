<?php

namespace Bananaland\Consent\Services;

/**
 * Built-in service definitions. Each renders its snippet from validated settings.
 *
 * Shape: label, provider, category, fields (key => [label, pattern]), cookies, privacy_url,
 * google (bool: honors Consent Mode, may load cookieless in Advanced mode), render(settings) => [head, body].
 */
final class Presets
{
    public static function all(): array
    {
        $presets = [
            'gtm' => [
                'label' => 'Google Tag Manager',
                'provider' => 'Google',
                'category' => 'necessary',
                'fields' => ['id' => ['label' => 'Container ID', 'pattern' => '/^GTM-[A-Z0-9]{4,12}$/', 'placeholder' => 'GTM-XXXXXXX']],
                'cookies' => [],
                'privacy_url' => 'https://policies.google.com/privacy',
                'google' => true,
                'note' => __('Tag Manager loads for every visitor so it can receive Consent Mode signals. Tags inside it must respect consent: Google tags do this automatically; give other tags a trigger on the blc_consent_update event or a consent check.', 'bananaland-consent'),
                'render' => static function (array $s): array {
                    $id = $s['id'];
                    return [
                        'head' => "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$id}');</script>",
                        'body' => '',
                    ];
                },
            ],
            'ga4' => [
                'label' => 'Google Analytics 4',
                'provider' => 'Google',
                'category' => 'analytics',
                'fields' => ['id' => ['label' => 'Measurement ID', 'pattern' => '/^G-[A-Z0-9]{4,15}$/', 'placeholder' => 'G-XXXXXXXXXX']],
                'cookies' => [
                    ['name' => '_ga', 'duration' => __('2 years', 'bananaland-consent'), 'purpose' => __('Distinguishes visitors.', 'bananaland-consent')],
                    ['name' => '_ga_*', 'duration' => __('2 years', 'bananaland-consent'), 'purpose' => __('Keeps session state.', 'bananaland-consent')],
                ],
                'privacy_url' => 'https://policies.google.com/privacy',
                'google' => true,
                'render' => static fn(array $s): array => [
                    'head' => self::gtag($s['id']),
                    'body' => '',
                ],
            ],
            'google_ads' => [
                'label' => 'Google Ads',
                'provider' => 'Google',
                'category' => 'marketing',
                'fields' => ['id' => ['label' => 'Conversion ID', 'pattern' => '/^AW-\d{5,15}$/', 'placeholder' => 'AW-123456789']],
                'cookies' => [
                    ['name' => '_gcl_au', 'duration' => __('3 months', 'bananaland-consent'), 'purpose' => __('Measures ad conversions.', 'bananaland-consent')],
                    ['name' => '_gcl_aw', 'duration' => __('3 months', 'bananaland-consent'), 'purpose' => __('Stores ad click information.', 'bananaland-consent')],
                ],
                'privacy_url' => 'https://policies.google.com/privacy',
                'google' => true,
                'render' => static fn(array $s): array => [
                    'head' => self::gtag($s['id']),
                    'body' => '',
                ],
            ],
            'meta_pixel' => [
                'label' => 'Meta Pixel',
                'provider' => 'Meta',
                'category' => 'marketing',
                'fields' => ['id' => ['label' => 'Pixel ID', 'pattern' => '/^\d{5,20}$/', 'placeholder' => '123456789012345']],
                'cookies' => [
                    ['name' => '_fbp', 'duration' => __('3 months', 'bananaland-consent'), 'purpose' => __('Identifies browsers for ad delivery and measurement.', 'bananaland-consent')],
                    ['name' => '_fbc', 'duration' => __('3 months', 'bananaland-consent'), 'purpose' => __('Stores the last ad click.', 'bananaland-consent')],
                ],
                'privacy_url' => 'https://www.facebook.com/privacy/policy/',
                'google' => false,
                'render' => static function (array $s): array {
                    $id = $s['id'];
                    return [
                        'head' => "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('consent','grant');fbq('init','{$id}');fbq('track','PageView');</script>",
                        'body' => '',
                    ];
                },
            ],
            'linkedin' => [
                'label' => 'LinkedIn Insight Tag',
                'provider' => 'LinkedIn',
                'category' => 'marketing',
                'fields' => ['id' => ['label' => 'Partner ID', 'pattern' => '/^\d{3,12}$/', 'placeholder' => '1234567']],
                'cookies' => [
                    ['name' => 'li_fat_id', 'duration' => __('30 days', 'bananaland-consent'), 'purpose' => __('Attributes conversions to LinkedIn ads.', 'bananaland-consent')],
                    ['name' => 'li_sugr', 'duration' => __('3 months', 'bananaland-consent'), 'purpose' => __('Browser identifier for ad measurement.', 'bananaland-consent')],
                    ['name' => 'bcookie', 'duration' => __('1 year', 'bananaland-consent'), 'purpose' => __('Browser identifier (set on linkedin.com).', 'bananaland-consent')],
                ],
                'privacy_url' => 'https://www.linkedin.com/legal/privacy-policy',
                'google' => false,
                'render' => static function (array $s): array {
                    $id = $s['id'];
                    return [
                        'head' => '',
                        'body' => "<script>_linkedin_partner_id='{$id}';window._linkedin_data_partner_ids=window._linkedin_data_partner_ids||[];window._linkedin_data_partner_ids.push(_linkedin_partner_id);</script><script>(function(l){if(!l){window.lintrk=function(a,b){window.lintrk.q.push([a,b])};window.lintrk.q=[]}var s=document.getElementsByTagName('script')[0];var b=document.createElement('script');b.type='text/javascript';b.async=true;b.src='https://snap.licdn.com/li.lms-analytics/insight.min.js';s.parentNode.insertBefore(b,s);})(window.lintrk);</script>",
                    ];
                },
            ],
            'hubspot' => [
                'label' => 'HubSpot tracking',
                'provider' => 'HubSpot',
                'category' => 'analytics',
                'fields' => ['id' => ['label' => 'Hub ID', 'pattern' => '/^\d{3,12}$/', 'placeholder' => '1234567']],
                'cookies' => [
                    ['name' => '__hstc', 'duration' => __('6 months', 'bananaland-consent'), 'purpose' => __('Tracks visitors.', 'bananaland-consent')],
                    ['name' => 'hubspotutk', 'duration' => __('6 months', 'bananaland-consent'), 'purpose' => __('Identifies visitors for form submissions.', 'bananaland-consent')],
                    ['name' => '__hssc', 'duration' => __('30 minutes', 'bananaland-consent'), 'purpose' => __('Tracks sessions.', 'bananaland-consent')],
                    ['name' => '__hssrc', 'duration' => __('Session', 'bananaland-consent'), 'purpose' => __('Detects browser restarts.', 'bananaland-consent')],
                ],
                'privacy_url' => 'https://legal.hubspot.com/privacy-policy',
                'google' => false,
                'render' => static fn(array $s): array => [
                    'head' => '',
                    'body' => '<script id="hs-script-loader" async defer src="https://js.hs-scripts.com/' . $s['id'] . '.js"></script>',
                ],
            ],
            'clarity' => [
                'label' => 'Microsoft Clarity',
                'provider' => 'Microsoft',
                'category' => 'analytics',
                'fields' => ['id' => ['label' => 'Project ID', 'pattern' => '/^[a-z0-9]{6,16}$/', 'placeholder' => 'abcdef1234']],
                'cookies' => [
                    ['name' => '_clck', 'duration' => __('1 year', 'bananaland-consent'), 'purpose' => __('Identifies visitors.', 'bananaland-consent')],
                    ['name' => '_clsk', 'duration' => __('1 day', 'bananaland-consent'), 'purpose' => __('Joins page views into a session recording.', 'bananaland-consent')],
                ],
                'privacy_url' => 'https://privacy.microsoft.com/privacystatement',
                'google' => false,
                'render' => static function (array $s): array {
                    $id = $s['id'];
                    return [
                        'head' => "<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,'clarity','script','{$id}');window.clarity('consentv2',{ad_Storage:'denied',analytics_Storage:'granted'});</script>",
                        'body' => '',
                    ];
                },
            ],
            'custom' => [
                'label' => __('Custom', 'bananaland-consent'),
                'provider' => '',
                'category' => 'analytics',
                'fields' => [],
                'cookies' => [],
                'privacy_url' => '',
                'google' => false,
                'render' => static fn(array $s): array => [
                    'head' => (string) ($s['head'] ?? ''),
                    'body' => (string) ($s['body'] ?? ''),
                ],
            ],
        ];

        /**
         * Filters the available service presets. Add your own using the same shape.
         */
        return apply_filters('blc_service_presets', $presets);
    }

    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    private static function gtag(string $id): string
    {
        return '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script>'
            . "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$id}');</script>";
    }
}
