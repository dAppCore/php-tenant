<?php

declare(strict_types=1);

namespace Core\Tenant\Concerns;

/**
 * Provides SSRF (Server-Side Request Forgery) protection for HTTP requests.
 *
 * This trait validates hostnames and IP addresses to prevent requests to:
 * - Localhost and loopback addresses (127.0.0.0/8, ::1)
 * - Private networks (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)
 * - Link-local addresses (169.254.0.0/16, fe80::/10)
 * - Reserved ranges and special-use addresses
 * - Local domain names (.local, .localhost, .internal)
 *
 * It also protects against DNS rebinding attacks by resolving hostnames
 * and validating all resolved IP addresses at request time.
 */
trait PreventsSSRF
{
    /**
     * Known safe webhook domains that bypass SSRF validation.
     * These are official service endpoints that are inherently safe.
     *
     * @var array<string>
     */
    protected static array $trustedWebhookDomains = [
        'discord.com',
        'discordapp.com',
        'hooks.slack.com',
        'api.telegram.org',
    ];

    /**
     * Validate a URL for SSRF vulnerabilities at request time.
     * Returns the resolved IP to connect to, or null if unsafe.
     *
     * @param  string  $url  The URL to validate
     * @return array{valid: bool, ip: ?string, error: ?string}
     */
    protected function validateUrlForSSRF(string $url): array
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        $scheme = $parsed['scheme'] ?? '';

        if (empty($host)) {
            return [
                'valid' => false,
                'ip' => null,
                'error' => 'URL does not contain a valid hostname',
            ];
        }

        // Only allow HTTPS for webhooks
        if ($scheme !== 'https') {
            return [
                'valid' => false,
                'ip' => null,
                'error' => 'URL must use HTTPS',
            ];
        }

        // Check if it's a trusted webhook domain
        if ($this->isTrustedWebhookDomain($host)) {
            return [
                'valid' => true,
                'ip' => null, // Let HTTP client resolve naturally
                'error' => null,
            ];
        }

        // Check for local hostnames
        if ($this->isLocalHostname($host)) {
            return [
                'valid' => false,
                'ip' => null,
                'error' => 'Requests to localhost or local domains are not allowed',
            ];
        }

        // If host is an IP address, validate directly
        $normalizedIp = $this->normalizeIpAddress($host);
        if ($normalizedIp !== null) {
            if ($this->isPrivateOrLocalhost($normalizedIp)) {
                return [
                    'valid' => false,
                    'ip' => null,
                    'error' => 'Requests to localhost or private networks are not allowed',
                ];
            }

            return [
                'valid' => true,
                'ip' => $normalizedIp,
                'error' => null,
            ];
        }

        // Resolve hostname and validate ALL resolved IPs
        $resolvedIp = $this->resolveAndValidateHost($host);
        if ($resolvedIp === null) {
            return [
                'valid' => false,
                'ip' => null,
                'error' => 'URL hostname resolves to a private or local address, or could not be resolved',
            ];
        }

        return [
            'valid' => true,
            'ip' => $resolvedIp,
            'error' => null,
        ];
    }

    /**
     * Check if a hostname is a trusted webhook service domain.
     */
    protected function isTrustedWebhookDomain(string $host): bool
    {
        $hostLower = strtolower(trim($host));

        foreach (self::$trustedWebhookDomains as $domain) {
            if ($hostLower === $domain || str_ends_with($hostLower, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve hostname to IP addresses and validate none are private/local.
     * Returns a validated IP address to use for the request, or null if invalid.
     */
    protected function resolveAndValidateHost(string $host): ?string
    {
        // If it's already an IP address, validate it directly
        $normalizedIp = $this->normalizeIpAddress($host);
        if ($normalizedIp !== null) {
            return $this->isPrivateOrLocalhost($normalizedIp) ? null : $normalizedIp;
        }

        // Resolve all A records (IPv4)
        $ipv4Records = @dns_get_record($host, DNS_A);
        $ipv6Records = @dns_get_record($host, DNS_AAAA);

        $resolvedIps = [];

        if (is_array($ipv4Records)) {
            foreach ($ipv4Records as $record) {
                if (isset($record['ip'])) {
                    $resolvedIps[] = $record['ip'];
                }
            }
        }

        if (is_array($ipv6Records)) {
            foreach ($ipv6Records as $record) {
                if (isset($record['ipv6'])) {
                    $resolvedIps[] = $record['ipv6'];
                }
            }
        }

        // Fallback to gethostbynamel for IPv4 if dns_get_record failed
        if (empty($resolvedIps)) {
            $fallbackIps = @gethostbynamel($host);
            if (is_array($fallbackIps)) {
                $resolvedIps = $fallbackIps;
            }
        }

        if (empty($resolvedIps)) {
            return null;
        }

        // Validate ALL resolved IPs - if any is private/local, reject
        foreach ($resolvedIps as $ip) {
            if ($this->isPrivateOrLocalhost($ip)) {
                return null;
            }
        }

        // Return the first valid IP for the request
        return $resolvedIps[0];
    }

    /**
     * Normalise an IP address to canonical form.
     * Handles bracketed IPv6, decimal/octal/hex IPv4 encodings.
     * Returns the canonical IP string or null if not an IP address.
     */
    protected function normalizeIpAddress(string $host): ?string
    {
        $host = trim($host);

        // Handle bracketed IPv6 like [::1]
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // Try standard IP validation first
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // Normalise IPv6 to consistent format
            $packed = @inet_pton($host);
            if ($packed !== false) {
                return inet_ntop($packed);
            }

            return $host;
        }

        // Handle non-standard IPv4 encodings (decimal, octal, hex)
        // Examples: 2130706433 (decimal for 127.0.0.1), 0177.0.0.1 (octal), 0x7f.0.0.1 (hex)
        $normalizedIpv4 = $this->parseNonStandardIpv4($host);
        if ($normalizedIpv4 !== null) {
            return $normalizedIpv4;
        }

        return null;
    }

    /**
     * Parse non-standard IPv4 encodings (decimal, octal, hex) to canonical dotted form.
     */
    protected function parseNonStandardIpv4(string $host): ?string
    {
        // Single decimal number (e.g., 2130706433 for 127.0.0.1)
        if (preg_match('/^\d+$/', $host)) {
            $decimal = filter_var($host, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 4294967295],
            ]);
            if ($decimal !== false) {
                return long2ip($decimal);
            }
        }

        // Handle dotted notation with octal (0-prefixed) or hex (0x-prefixed) octets
        // Examples: 0177.0.0.1, 0x7f.0.0.1, 0x7f000001
        if (preg_match('/^(0[xX][0-9a-fA-F]+)$/', $host, $matches)) {
            // Single hex number
            $decimal = @hexdec($matches[1]);
            if ($decimal >= 0 && $decimal <= 4294967295) {
                return long2ip((int) $decimal);
            }
        }

        // Dotted notation with mixed encodings
        $parts = explode('.', $host);
        if (count($parts) >= 1 && count($parts) <= 4) {
            $octets = [];
            foreach ($parts as $part) {
                $value = $this->parseIpOctet($part);
                if ($value === null || $value < 0 || $value > 255) {
                    break;
                }
                $octets[] = $value;
            }

            // Standard 4-part dotted notation
            if (count($octets) === 4) {
                return implode('.', $octets);
            }
        }

        return null;
    }

    /**
     * Parse a single IP octet that may be in decimal, octal, or hex format.
     */
    protected function parseIpOctet(string $part, int $maxValue = 255): ?int
    {
        $part = trim($part);

        if ($part === '') {
            return null;
        }

        // Hex format (0x or 0X prefix)
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $part, $matches)) {
            $value = hexdec($matches[1]);

            return ($value <= $maxValue) ? (int) $value : null;
        }

        // Octal format (0 prefix, but not just "0")
        if (preg_match('/^0([0-7]+)$/', $part, $matches)) {
            $value = octdec($matches[1]);

            return ($value <= $maxValue) ? (int) $value : null;
        }

        // Plain decimal
        if (preg_match('/^\d+$/', $part)) {
            $value = (int) $part;

            return ($value <= $maxValue) ? $value : null;
        }

        return null;
    }

    /**
     * Check if an IP address is localhost or private.
     * Expects a normalised/canonical IP address.
     */
    protected function isPrivateOrLocalhost(string $ip): bool
    {
        // Handle IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Normalise and check for ::1 (localhost) and other reserved ranges
            $packed = @inet_pton($ip);
            if ($packed === false) {
                return true; // Invalid IP, treat as unsafe
            }

            $normalized = inet_ntop($packed);

            // IPv6 localhost
            if ($normalized === '::1') {
                return true;
            }

            // IPv4-mapped IPv6 (::ffff:x.x.x.x) - extract and check IPv4
            if (str_starts_with($normalized, '::ffff:')) {
                $ipv4 = substr($normalized, 7);
                if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $this->isPrivateOrLocalhostIpv4($ipv4);
                }
            }

            // Use filter_var for other IPv6 private/reserved ranges
            return ! filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        // Handle IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isPrivateOrLocalhostIpv4($ip);
        }

        // If not a valid IP at this point, treat as unsafe
        return true;
    }

    /**
     * Check if an IPv4 address is localhost or private.
     */
    protected function isPrivateOrLocalhostIpv4(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        // 127.0.0.0/8 (localhost range) - 127.0.0.0 to 127.255.255.255
        $localhost127Start = ip2long('127.0.0.0');
        $localhost127End = ip2long('127.255.255.255');
        if ($long >= $localhost127Start && $long <= $localhost127End) {
            return true;
        }

        // 0.0.0.0/8 - current network (also localhost-ish)
        if (($long >> 24) === 0) {
            return true;
        }

        // Use filter_var for remaining private/reserved checks
        // This catches: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, link-local, etc.
        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Check if a hostname (not IP) is a local/private domain.
     */
    protected function isLocalHostname(string $host): bool
    {
        $host = strtolower(trim($host));

        // Explicit localhost
        if ($host === 'localhost') {
            return true;
        }

        // .local domains (mDNS)
        if (str_ends_with($host, '.local')) {
            return true;
        }

        // .localhost TLD (RFC 6761)
        if (str_ends_with($host, '.localhost')) {
            return true;
        }

        // .internal (common convention)
        if (str_ends_with($host, '.internal')) {
            return true;
        }

        // .localdomain (common convention)
        if (str_ends_with($host, '.localdomain')) {
            return true;
        }

        // .home.arpa (RFC 8375)
        if (str_ends_with($host, '.home.arpa')) {
            return true;
        }

        return false;
    }
}
