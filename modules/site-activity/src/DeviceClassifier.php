<?php
declare(strict_types=1);
namespace SiteActivity;

/** Lightweight user-agent classification. Anything it cannot tell is reported as "unknown", never guessed. */
final class DeviceClassifier
{
    /** @return array{category:string,browser:string,browser_version:string,os:string,bot:bool} */
    public static function classify(?string $ua): array
    {
        $ua = substr((string)$ua, 0, 400);
        $out = ['category' => 'unknown', 'browser' => 'unknown', 'browser_version' => '', 'os' => 'unknown', 'bot' => false];
        if ($ua === '') return $out;

        if (preg_match('/bot|crawl|spider|slurp|headless|curl\/|wget|python-requests|lighthouse|facebookexternalhit|monitor|uptime|httpclient|scrapy/i', $ua)) {
            $out['bot'] = true;
        }

        // Operating system
        if (preg_match('/Windows NT/i', $ua)) $out['os'] = 'Windows';
        elseif (preg_match('/Android/i', $ua)) $out['os'] = 'Android';
        elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) $out['os'] = 'iOS';
        elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) $out['os'] = 'macOS';
        elseif (preg_match('/CrOS/i', $ua)) $out['os'] = 'ChromeOS';
        elseif (preg_match('/Linux|X11/i', $ua)) $out['os'] = 'Linux';

        // Browser family (order matters: Edge/Opera/Samsung also say "Chrome")
        $m = [];
        if (preg_match('/(?:Edg|EdgA|EdgiOS)\/(\d+)/', $ua, $m)) { $out['browser'] = 'Edge'; $out['browser_version'] = $m[1]; }
        elseif (preg_match('/(?:OPR|Opera)\/(\d+)/', $ua, $m)) { $out['browser'] = 'Opera'; $out['browser_version'] = $m[1]; }
        elseif (preg_match('/SamsungBrowser\/(\d+)/', $ua, $m)) { $out['browser'] = 'Samsung Internet'; $out['browser_version'] = $m[1]; }
        elseif (preg_match('/(?:Firefox|FxiOS)\/(\d+)/', $ua, $m)) { $out['browser'] = 'Firefox'; $out['browser_version'] = $m[1]; }
        elseif (preg_match('/(?:Chrome|CriOS)\/(\d+)/', $ua, $m)) { $out['browser'] = 'Chrome'; $out['browser_version'] = $m[1]; }
        elseif (preg_match('/Version\/(\d+)[^ ]* (?:Mobile\/\S+ )?Safari/', $ua, $m)) { $out['browser'] = 'Safari'; $out['browser_version'] = $m[1]; }

        // Device category
        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua) || ($out['os'] === 'Android' && !preg_match('/Mobile/i', $ua))) {
            $out['category'] = 'tablet';
        } elseif (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone/i', $ua)) {
            $out['category'] = 'mobile';
        } elseif (in_array($out['os'], ['Windows', 'macOS', 'Linux', 'ChromeOS'], true)) {
            $out['category'] = 'desktop';
        }
        return $out;
    }

    /** Coarse viewport bucket supplied by the browser. Anything else is "unknown". */
    public static function screenCategory($value): string
    {
        $v = is_string($value) ? $value : '';
        return in_array($v, ['small', 'medium', 'large'], true) ? $v : 'unknown';
    }
}
