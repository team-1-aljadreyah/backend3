<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

final class ClientContext
{
    /**
     * Official Cloudflare IPv4 & IPv6 edge ranges (https://www.cloudflare.com/ips/)
     * plus loopback/private proxies configurable via TRUSTED_PROXY_CIDRS.
     *
     * @var array<int, string>
     */
    private const CLOUDFLARE_CIDRS = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public static function requestId(?Request $request = null): string
    {
        $req = $request ?? (app()->bound('request') ? request() : null);
        if ($req instanceof Request) {
            $existing = $req->attributes->get('p3_request_id');
            if (is_string($existing) && $existing !== '') {
                return $existing;
            }
            $header = trim((string) $req->header('X-Request-Id', ''));
            $id = ($header !== '' && preg_match('/^[a-zA-Z0-9._-]{4,60}$/', $header) === 1)
                ? $header
                : 'req_'.bin2hex(random_bytes(6));
            $req->attributes->set('p3_request_id', $id);

            return $id;
        }

        return 'cli_'.bin2hex(random_bytes(5));
    }

    public static function clientIp(?Request $request = null): ?string
    {
        $req = $request ?? (app()->bound('request') ? request() : null);
        if (! $req instanceof Request) {
            return null;
        }

        $socketIp = trim((string) $req->server('REMOTE_ADDR', ''));
        if ($socketIp === '') {
            return null;
        }

        if (self::isTrustedProxy($socketIp)) {
            $cfIp = trim((string) $req->header('CF-Connecting-IP', ''));
            if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP) !== false) {
                return $cfIp;
            }

            $xff = trim((string) $req->header('X-Forwarded-For', ''));
            if ($xff !== '') {
                $first = trim(explode(',', $xff)[0]);
                if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        return filter_var($socketIp, FILTER_VALIDATE_IP) !== false ? $socketIp : null;
    }

    public static function userAgent(?Request $request = null): ?string
    {
        $req = $request ?? (app()->bound('request') ? request() : null);
        if (! $req instanceof Request) {
            return null;
        }

        $ua = trim((string) $req->header('User-Agent', ''));
        if ($ua === '') {
            return null;
        }

        return mb_substr($ua, 0, 300, 'UTF-8');
    }

    public static function sessionId(?Request $request = null): ?string
    {
        $req = $request ?? (app()->bound('request') ? request() : null);
        if (! $req instanceof Request) {
            return null;
        }

        $user = $req->user();
        $token = $user?->currentAccessToken();
        $sid = $token?->session_id ?? null;

        return is_string($sid) && $sid !== '' ? $sid : null;
    }

    private static function isTrustedProxy(string $ip): bool
    {
        $customRaw = trim((string) env('TRUSTED_PROXY_CIDRS', ''));
        $custom = $customRaw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $customRaw))))
            : [];

        $trusted = array_merge(self::CLOUDFLARE_CIDRS, $custom);

        return IpUtils::checkIp($ip, $trusted);
    }
}
