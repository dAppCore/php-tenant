<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Controllers;

use Core\Helpers\PrivacyHelper;
use Core\Mod\Trees\Models\TreePlanting;
use Core\Mod\Trees\Models\TreePlantingStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Handles agent referral tracking for the Trees for Agents programme.
 *
 * When an AI agent refers a user via /ref/{provider}/{model?}, we:
 * 1. Store the referral in session
 * 2. Set a 30-day cookie as backup
 * 3. Redirect to registration with ref=agent parameter
 *
 * On signup, PlantTreeForAgentReferral listener plants a tree for the referrer.
 */
class ReferralController extends \Core\Front\Controller
{
    /**
     * Cookie name for agent referral tracking.
     */
    public const REFERRAL_COOKIE = 'agent_referral';

    /**
     * Session key for agent referral.
     */
    public const REFERRAL_SESSION = 'agent_referral';

    /**
     * Cookie lifetime in minutes (30 days).
     */
    public const COOKIE_LIFETIME = 60 * 24 * 30;

    /**
     * Track an agent referral and redirect to registration.
     *
     * @param  string  $provider  The AI provider (anthropic, openai, etc.)
     * @param  string|null  $model  Optional model identifier (claude-opus, gpt-4, etc.)
     */
    public function track(Request $request, string $provider, ?string $model = null): RedirectResponse
    {
        // Validate provider against allowlist
        if (! TreePlanting::isValidProvider($provider)) {
            // Invalid provider — redirect to pricing without referral
            return redirect()->route('pricing');
        }

        // Normalise provider and model to lowercase
        $provider = strtolower($provider);
        $model = $model ? strtolower($model) : null;

        // Build referral data for session (includes hashed IP for fraud detection)
        $referral = [
            'provider' => $provider,
            'model' => $model,
            'referred_at' => now()->toIso8601String(),
            'ip_hash' => PrivacyHelper::hashIp($request->ip()),
        ];

        // Track the referral visit in stats (raw inbound count)
        TreePlantingStats::incrementReferrals($provider, $model);

        // Store in session (primary) - includes hashed IP
        $request->session()->put(self::REFERRAL_SESSION, $referral);

        // Cookie data - exclude IP for privacy (GDPR compliance)
        // Provider/model is sufficient for referral attribution
        $cookieData = [
            'provider' => $provider,
            'model' => $model,
            'referred_at' => $referral['referred_at'],
        ];

        // Set 30-day cookie (backup for session expiry)
        $cookie = Cookie::make(
            name: self::REFERRAL_COOKIE,
            value: json_encode($cookieData),
            minutes: self::COOKIE_LIFETIME,
            path: '/',
            domain: config('session.domain'),
            secure: config('app.env') === 'production',
            httpOnly: true,
            sameSite: 'lax'
        );

        // Redirect to pricing with ref=agent parameter
        return redirect()
            ->route('pricing', ['ref' => 'agent'])
            ->withCookie($cookie);
    }

    /**
     * Get the agent referral from session or cookie.
     *
     * @return array{provider: string, model: ?string, referred_at: string, ip_hash?: string}|null
     */
    public static function getReferral(Request $request): ?array
    {
        // Try session first
        $referral = $request->session()->get(self::REFERRAL_SESSION);

        if ($referral) {
            return $referral;
        }

        // Fall back to cookie
        $cookie = $request->cookie(self::REFERRAL_COOKIE);

        if ($cookie) {
            try {
                $decoded = json_decode($cookie, true);
                if (is_array($decoded) && isset($decoded['provider'])) {
                    return $decoded;
                }
            } catch (\Throwable) {
                // Cookie invalid — ignore
            }
        }

        return null;
    }

    /**
     * Clear the agent referral from session and cookie.
     */
    public static function clearReferral(Request $request): void
    {
        $request->session()->forget(self::REFERRAL_SESSION);
        Cookie::queue(Cookie::forget(self::REFERRAL_COOKIE));
    }
}
