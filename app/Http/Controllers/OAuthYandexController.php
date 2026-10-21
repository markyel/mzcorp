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

    /**
     * Ящик внесистемных поставщиков QuoteParts (orders@): вход через Яндекс здесь, в mzCorp (адрес возврата
     * уже зарегистрирован), токены уходят в QuoteParts по API — у себя ящик не заводим и не синкаем.
     */
    public function authorizeQuoteParts(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasAnyRole(['director', 'admin']), 403);

        $nonce = Str::random(32);
        $request->session()->put('yandex_oauth_nonce.qp_offsystem', $nonce);
        $state = base64_encode(json_encode(['purpose' => 'qp_offsystem', 'nonce' => $nonce]));

        return redirect()->away($this->oauth->authorizationUrl($state, loginHint: (string) config('services.quoteparts.offsystem_mailbox')));
    }

    public function callback(Request $request): RedirectResponse
    {
        $decodedState = json_decode((string) base64_decode((string) $request->query('state', ''), true), true);
        if (is_array($decodedState) && ($decodedState['purpose'] ?? null) === 'qp_offsystem') {
            return $this->quotePartsCallback($request, (string) ($decodedState['nonce'] ?? ''));
        }

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

    /** Вход под ящиком внесистемных: токены — в QuoteParts, назад — на вкладку «Пилот» раздела QuoteParts. */
    private function quotePartsCallback(Request $request, string $nonce): RedirectResponse
    {
        $back = route('quoteparts.index', ['tab' => 'pilot']);
        abort_unless($request->user()?->hasAnyRole(['director', 'admin']), 403);
        $expected = $request->session()->pull('yandex_oauth_nonce.qp_offsystem');
        if (! is_string($expected) || ! hash_equals($expected, $nonce)) {
            abort(403, 'OAuth state mismatch.');
        }
        if ($error = $request->query('error')) {
            return redirect($back)->with('status', 'Яндекс: '.$error.' — '.$request->query('error_description', ''));
        }

        $address = (string) config('services.quoteparts.offsystem_mailbox');
        try {
            $tokens = $this->oauth->exchangeCode((string) $request->query('code', ''));
            if (empty($tokens['refresh_token'])) {
                throw new \RuntimeException('Яндекс не выдал refresh-токен.');
            }
            app(\App\Services\QuoteParts\QuotePartsClient::class)->connectOffsystemMailbox([
                'address' => $address,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in' => max(60, (int) now()->diffInSeconds($tokens['expires_at'])),
            ]);
        } catch (\Throwable $e) {
            Log::error('QuoteParts offsystem mailbox connect failed', ['address' => $address, 'error' => $e->getMessage()]);

            return redirect($back)->with('status', "Ящик {$address} не подключён: ".mb_substr($e->getMessage(), 0, 300));
        }
        Log::info('QuoteParts offsystem mailbox connected', ['address' => $address, 'user_id' => $request->user()->id]);

        return redirect($back)->with('status', "Ящик {$address} подключён к QuoteParts через Яндекс.");
    }
}
