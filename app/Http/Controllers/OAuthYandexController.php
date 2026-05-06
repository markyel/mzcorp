<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\Mail\YandexOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth-flow для Yandex 360 (XOAUTH2 для IMAP/SMTP).
 *
 * Шаги:
 *   GET /oauth/yandex/authorize?mailbox=N
 *     → редирект на oauth.yandex.ru/authorize.
 *   GET /oauth/yandex/callback?code=X&state=Y
 *     → обмен code на токены, сохранение в Mailbox, редирект назад.
 *
 * State защищает от CSRF: храним подписанный JSON {mailbox_id, nonce} в сессии,
 * сверяем при возврате.
 */
class OAuthYandexController extends Controller
{
    public function __construct(private readonly YandexOAuthService $oauth)
    {
    }

    public function authorize(Request $request): RedirectResponse
    {
        $mailboxId = (int) $request->query('mailbox', 0);
        $mailbox = Mailbox::find($mailboxId);

        if (! $mailbox) {
            abort(404, 'Mailbox not found');
        }

        $nonce = Str::random(32);
        $state = base64_encode(json_encode(['mailbox' => $mailbox->id, 'nonce' => $nonce]));

        // Привязываем nonce к сессии админа, чтобы callback мог сверить.
        $request->session()->put("yandex_oauth_nonce.{$mailbox->id}", $nonce);

        $url = $this->oauth->authorizationUrl($state, loginHint: $mailbox->email);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($error = $request->query('error')) {
            return redirect('/dashboard')->with(
                'status',
                'Yandex OAuth: ' . $error . ' — ' . $request->query('error_description', '')
            );
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        $decoded = json_decode((string) base64_decode($state, true), true);
        if (! is_array($decoded) || ! isset($decoded['mailbox'], $decoded['nonce'])) {
            abort(400, 'Invalid OAuth state.');
        }

        $mailbox = Mailbox::find((int) $decoded['mailbox']);
        if (! $mailbox) {
            abort(404, 'Mailbox not found.');
        }

        $expectedNonce = $request->session()->pull("yandex_oauth_nonce.{$mailbox->id}");
        if (! is_string($expectedNonce) || ! hash_equals($expectedNonce, (string) $decoded['nonce'])) {
            abort(403, 'OAuth state mismatch.');
        }

        try {
            $tokens = $this->oauth->exchangeCode($code);
        } catch (\Throwable $e) {
            Log::error('Yandex OAuth exchange failed', [
                'mailbox_id' => $mailbox->id,
                'error' => $e->getMessage(),
            ]);

            return redirect('/dashboard')->with(
                'status',
                'Yandex OAuth: exchange failed — ' . $e->getMessage()
            );
        }

        $mailbox->setOAuthTokens(
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'],
            expiresAt: $tokens['expires_at'],
            scope: $tokens['scope'],
            tokenType: $tokens['token_type'],
        );
        $mailbox->save();

        Log::info('Yandex OAuth tokens saved', [
            'mailbox_id' => $mailbox->id,
            'expires_at' => $tokens['expires_at']->toIso8601String(),
        ]);

        return redirect('/dashboard')->with(
            'status',
            "OAuth-токены для {$mailbox->email} сохранены. Можно запустить mail:test {$mailbox->id}."
        );
    }
}
