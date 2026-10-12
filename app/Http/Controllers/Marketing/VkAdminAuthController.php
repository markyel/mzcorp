<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MediaChannel;
use App\Services\Marketing\VkAdminTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Вход администратора сообщества ВК через VK ID (см. VkAdminTokenService):
 * connect уводит на id.vk.ru, callback принимает код и меняет его на ключ.
 * state и code_verifier живут в сессии до возврата.
 */
class VkAdminAuthController extends Controller
{
    private const SESSION_KEY = 'marketing.vkid';

    public function connect(MediaChannel $channel, VkAdminTokenService $tokens): RedirectResponse
    {
        abort_unless($channel->kind === 'vk', 404);
        if (! VkAdminTokenService::configured()) {
            return redirect()->route('marketing.index', ['tab' => 'media'])
                ->with('error', 'Не задан VK_ID_CLIENT_ID — сначала зарегистрируйте приложение VK ID.');
        }

        $auth = $tokens->authorizeUrl(route('marketing.vk.callback'));
        session()->put(self::SESSION_KEY, [
            'channel_id' => $channel->id,
            'state' => $auth['state'],
            'verifier' => $auth['verifier'],
        ]);

        return redirect()->away($auth['url']);
    }

    public function callback(Request $request, VkAdminTokenService $tokens): RedirectResponse
    {
        $pending = session()->pull(self::SESSION_KEY);

        // VK ID отдаёт данные либо отдельными параметрами, либо JSON в payload.
        $data = $request->query();
        if (is_string($request->query('payload'))) {
            $data = array_merge($data, (array) json_decode($request->query('payload'), true));
        }

        $error = (string) ($data['error_description'] ?? $data['error'] ?? '');
        if (! is_array($pending) || $error !== '' || empty($data['code']) || empty($data['device_id'])
            || ! hash_equals((string) $pending['state'], (string) ($data['state'] ?? ''))) {
            return redirect()->route('marketing.index', ['tab' => 'media'])
                ->with('error', 'Вход ВК не завершён'.($error !== '' ? ': '.$error : ' — попробуйте ещё раз.'));
        }

        $channel = MediaChannel::query()->where('kind', 'vk')->findOrFail($pending['channel_id']);
        $res = $tokens->exchange($channel, (string) $data['code'], (string) $data['device_id'],
            (string) $pending['verifier'], (string) $data['state'], route('marketing.vk.callback'));

        return redirect()->route('marketing.index', ['tab' => 'media'])
            ->with($res['ok'] ? 'status' : 'error', 'ВКонтакте: '.$res['message']);
    }
}
