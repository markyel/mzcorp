<?php

namespace Tests\Unit\Marketing;

use App\Models\MediaChannel;
use App\Services\Marketing\MediaPublisherService;
use App\Services\Marketing\VkAdminTokenService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Ключ администратора сообщества ВК через VK ID: вход (PKCE), обмен кода,
 * продление одноразовым refresh-токеном, фото на стену этим ключом.
 */
class VkAdminTokenTest extends TestCase
{
    private MediaChannel $vk;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.vk_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'vk_test',
            'cache.default' => 'array',
            'services.vk_id.client_id' => '54000001',
            'services.vk_id.base' => 'https://id.test',
        ]);
        Schema::create('media_channels', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('kind');
            $t->boolean('is_active')->default(true);
            $t->boolean('auto_publish')->default(false);
            $t->text('last_error')->nullable();
            $t->unsignedBigInteger('mirror_of_channel_id')->nullable();
            $t->text('encrypted_secrets')->nullable();
            $t->timestamps();
        });
        $this->vk = MediaChannel::create(['name' => 'ВКонтакте', 'kind' => 'vk']);
        $this->vk->writeSecrets(['access_token' => 'group-tok', 'owner_id' => '-232442976']);
        $this->vk->save();
    }

    public function test_authorize_url_uses_pkce_s256(): void
    {
        $auth = app(VkAdminTokenService::class)->authorizeUrl('https://mzcorp.test/cb');
        parse_str((string) parse_url($auth['url'], PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://id.test/authorize?', $auth['url']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $auth['verifier'], true)), '+/', '-_'), '='), $q['code_challenge']);
        $this->assertSame($auth['state'], $q['state']);
        $this->assertSame('wall photos groups', $q['scope']);
        $this->assertGreaterThanOrEqual(32, strlen($auth['state']));
    }

    public function test_code_exchange_stores_admin_token_next_to_group_token(): void
    {
        Http::fake(['https://id.test/oauth2/auth' => Http::response([
            'access_token' => 'adm-1', 'refresh_token' => 'ref-1', 'expires_in' => 3600, 'user_id' => 77, 'scope' => 'wall photos groups',
        ])]);

        $res = app(VkAdminTokenService::class)->exchange($this->vk, 'code-x', 'dev-1', 'verifier', 'state', 'https://mzcorp.test/cb');

        $this->assertTrue($res['ok'], $res['message']);
        $ch = $this->vk->fresh();
        $this->assertSame('adm-1', $ch->secret('admin_access_token'));
        $this->assertSame('group-tok', $ch->secret('access_token'));
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'authorization_code' && $r['device_id'] === 'dev-1' && $r['code_verifier'] === 'verifier');
    }

    public function test_expiring_token_is_refreshed_and_refresh_token_rotated(): void
    {
        $this->vk->writeSecrets($this->vk->secrets() + [
            'admin_access_token' => 'adm-old', 'admin_refresh_token' => 'ref-old', 'admin_device_id' => 'dev-1',
            'admin_expires_at' => (string) (time() + 60),
        ]);
        $this->vk->save();
        Http::fake(['https://id.test/oauth2/auth' => Http::response(['access_token' => 'adm-new', 'refresh_token' => 'ref-new', 'expires_in' => 3600])]);

        $token = app(VkAdminTokenService::class)->accessToken($this->vk);

        $this->assertSame('adm-new', $token);
        $this->assertSame('ref-new', $this->vk->fresh()->secret('admin_refresh_token'));
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'ref-old' && $r['device_id'] === 'dev-1');
    }

    public function test_rejected_refresh_gives_no_token_and_tells_the_channel(): void
    {
        $this->vk->writeSecrets($this->vk->secrets() + [
            'admin_access_token' => 'adm-old', 'admin_refresh_token' => 'ref-old', 'admin_device_id' => 'dev-1', 'admin_expires_at' => '1',
        ]);
        $this->vk->save();
        Http::fake(['https://id.test/oauth2/auth' => Http::response(['error' => 'invalid_grant', 'error_description' => 'refresh token expired'], 400)]);

        $this->assertNull(app(VkAdminTokenService::class)->accessToken($this->vk));
        $this->assertStringContainsString('войдите администратором заново', (string) $this->vk->fresh()->last_error);
    }

    public function test_photos_go_to_the_wall_with_admin_token(): void
    {
        $this->vk->writeSecrets($this->vk->secrets() + [
            'admin_access_token' => 'adm-1', 'admin_refresh_token' => 'ref-1', 'admin_device_id' => 'dev-1',
            'admin_expires_at' => (string) (time() + 3600),
        ]);
        $this->vk->save();
        Http::fake([
            'https://mzcorp.test/*' => Http::response('JPEG', 200, ['Content-Type' => 'image/jpeg']),
            'https://api.vk.com/method/photos.getWallUploadServer' => Http::response(['response' => ['upload_url' => 'https://pu.vk.test/up']]),
            'https://pu.vk.test/*' => Http::response(['server' => 1, 'photo' => '[{"p":1}]', 'hash' => 'h']),
            'https://api.vk.com/method/photos.saveWallPhoto' => Http::response(['response' => [['owner_id' => -232442976, 'id' => 456]]]),
        ]);

        $m = new ReflectionMethod(MediaPublisherService::class, 'uploadVkPhotos');
        $out = $m->invoke(app(MediaPublisherService::class), $this->vk, '-232442976', ['https://mzcorp.test/cover.jpg']);

        $this->assertSame(['photo-232442976_456'], $out);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'photos.saveWallPhoto') && $r['group_id'] === '232442976' && $r['access_token'] === 'adm-1');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'getMessagesUploadServer'));
    }
}
