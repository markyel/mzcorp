<?php

namespace Tests\Unit\Mail;

use App\Livewire\Mail\Composer;
use App\Livewire\QuoteParts\SupportRequests;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Богатый редактор письма — один компонент (x-mail.rich-editor) на композер «Почты» и ответ поддержки
 * QuoteParts; шаблоны писем — общий trait InsertsLetterTemplates. Без базы: библиотека шаблонов — в памяти.
 */
class ComposerRichEditorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.rte_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'database.default' => 'rte_test']);
        Schema::create('letter_templates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->boolean('is_folder')->default(false);
            $t->string('name');
            $t->string('subject')->nullable();
            $t->text('body')->nullable();
            $t->integer('sort_order')->default(0);
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->timestamps();
        });
    }

    public function test_the_mail_composer_draws_the_shared_editor_with_its_templates_menu(): void
    {
        $user = (new \App\Models\User)->forceFill(['id' => 5, 'name' => 'Менеджер']);
        \App\Models\LetterTemplate::query()->insert(['owner_user_id' => 5, 'name' => 'Реквизиты', 'body' => "Наши реквизиты:\nИНН 7700000000", 'is_folder' => false, 'sort_order' => 0]);

        $html = Livewire::actingAs($user)->test(Composer::class)->set('open', true)->set('bodyHtml', '<p>Привет</p>')->html();

        $this->assertStringContainsString('class="rte mail-rte"', $html);
        $this->assertStringContainsString('mailEditor(', $html);
        $this->assertStringContainsString('wire:key="rte-0"', $html);
        $this->assertStringContainsString('📄 Шаблоны', $html);
        $this->assertStringContainsString('Реквизиты', $html);
        $this->assertStringContainsString('.mail-rte .rte-toolbar', $html, 'the editor brings its styles');
    }

    public function test_a_template_goes_into_the_support_reply_as_paragraphs(): void
    {
        \App\Models\LetterTemplate::query()->insert(['owner_user_id' => 9, 'name' => 'Спам', 'body' => "Проверьте папку «Спам».\nСпасибо!", 'is_folder' => false, 'sort_order' => 0]);
        $id = (int) \App\Models\LetterTemplate::query()->value('id');
        $user = (new \App\Models\User)->forceFill(['id' => 9, 'name' => 'Ольга', 'quoteparts_pilot' => true]);

        Livewire::actingAs($user)->test(SupportRequests::class)
            ->set('bodyHtml', '<p>Здравствуйте!</p>')
            ->call('insertTemplateById', $id)
            ->assertSet('bodyHtml', '<p>Здравствуйте!</p><p>Проверьте папку «Спам».</p><p>Спасибо!</p>')
            ->assertSet('bodyText', "Здравствуйте!\nПроверьте папку «Спам».\nСпасибо!")
            ->assertDispatched('mail-editor-set-html');
    }
}
