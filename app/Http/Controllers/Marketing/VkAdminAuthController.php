<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MediaChannel;
use App\Services\Marketing\VkAdminTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Вход администратора сообщества ВК через VK ID (см. VkAdminTokenService):
 * connect уводит на id.vk.ru, callback принимает код и меняет его на ключ.
 *
 * state и code_verifier живут в кэше по самому state, а не в сессии: сессии
 * в БД без блокировки, и фоновые опросы страницы (медиаплан, сигнал почты)
 * перезаписывали сессию старой версией — 13.10.2026 три входа подряд
 * вернулись «не завершён», хотя VK ID прислал и код, и state.
 */
class VkAdminAuthController extends Controller
{
    private const STATE_TTL = 900;

    public function connect(MediaChannel $channel, VkAdminTokenService $tokens): RedirectResponse
    {
        abort_unless($channel->kind === 'vk', 404);
        if (! VkAdminTokenService::configured()) {
            return redirect()->route('marketing.index', ['tab' => 'media'])
                ->with('error', 'Не задан VK_ID_CLIENT_ID — сначала зарегистрируйте приложение VK ID.');
        }

        $auth = $tokens->authorizeUrl(route('marketing.vk.callback'));
        Cache::put(self::stateKey($auth['state']), [
            'channel_id' => $channel->id,
            'verifier' => $auth['verifier'],
            'user_id' => auth()->id(),
        ], self::STATE_TTL);

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request, VkAdminTokenService $tokens): RedirectResponse
    {
        // VK ID отдаёт данные либо отдельными параметрами, либо JSON в payload.
        $data = $request->query();
        if (is_string($request->query('payload'))) {
            $data = array_merge($data, (array) json_decode($request->query('payload'), true));
        }

        $state = (string) ($data['state'] ?? '');
        $pending = $state !== '' ? Cache::pull(self::stateKey($state)) : null;
        $error = (string) ($data['error_description'] ?? $data['error'] ?? '');
        $why = match (true) {
            $error !== '' => 'vk_error',
            ! is_array($pending) => 'unknown_or_expired_state',
            (int) ($pending['user_id'] ?? 0) !== (int) auth()->id() => 'other_user',
            empty($data['code']) || empty($data['device_id']) => 'no_code',
            default => null,
        };
        if ($why !== null) {
            Log::warning('VkAdminAuth: callback rejected', ['reason' => $why, 'vk_error' => $error, 'keys' => array_keys($data)]);

            return redirect()->route('marketing.index', ['tab' => 'media'])
                ->with('error', 'Вход ВК не завершён'.($error !== '' ? ': '.$error : ' — попробуйте ещё раз.'));
        }

        $channel = MediaChannel::query()->where('kind', 'vk')->findOrFail($pending['channel_id']);
        $res = $tokens->exchange($channel, (string) $data['code'], (string) $data['device_id'],
            (string) $pending['verifier'], (string) $data['state'], route('marketing.vk.callback'));

        return redirect()->route('marketing.index', ['tab' => 'media'])
            ->with($res['ok'] ? 'status' : 'error', 'ВКонтакте: '.$res['message']);
    }

    private static function stateKey(string $state): string
    {
        return 'vkid:state:'.hash('sha256', $state);
    }
}
