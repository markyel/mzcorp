<?php

namespace Tests\Unit\QuoteParts;

use App\Models\CatalogItem;
use App\Models\PricePosition;
use App\Models\QuotePartsOffer;
use App\Services\Procurement\PriceExportService;
use Tests\TestCase;

/** Выгрузка «Актуализации цен»: свой список у каждого и сопроводительный файл «Покупаем здесь» (без базы). */
class PriceExportCompanionTest extends TestCase
{
    private function offer(int $id, int $cid, string $sku, string $supplier, float $price, array $over = []): QuotePartsOffer
    {
        $o = (new QuotePartsOffer)->forceFill(array_merge([
            'id' => $id, 'catalog_item_id' => $cid, 'sku' => $sku, 'supplier_key' => $supplier, 'supplier_name' => $supplier.' Ltd',
            'price' => $price, 'currency' => 'USD', 'kind' => 'original', 'status' => 'active', 'superseded' => false,
        ], $over));
        $o->setRelation('catalogItem', new CatalogItem(['sku' => $sku, 'name' => 'Позиция '.$sku]));

        return $o;
    }

    public function test_only_the_offer_chosen_to_buy_goes_to_the_companion_file(): void
    {
        $offers = collect([
            $this->offer(1, 10, 'M00647', 'sup:68', 12.5),
            $this->offer(2, 10, 'M00647', 'org:21', 11.0),
            $this->offer(3, 20, 'M21461', 'org:21', 425.0, ['export_comment' => 'срок 30 дн.']),
            $this->offer(4, 30, 'M22627', 'sup:68', 3.0),
        ]);
        $choices = collect([
            10 => (object) ['source' => 'quoteparts', 'offer_ref' => '1'],
            20 => (object) ['source' => 'quoteparts', 'offer_ref' => '3'],
            // Выбрано письмом, не в QuoteParts — в этот файл не идёт.
            30 => (object) ['source' => 'email', 'offer_ref' => null],
        ]);

        $rows = PriceExportService::companionRows($offers, $choices, ['org:21' => 'OSS Китай']);

        $this->assertSame(['M00647', 'M21461'], array_column($rows, 'sku'));
        $this->assertSame(['sku' => 'M00647', 'name' => 'Позиция M00647', 'price' => 12.5, 'currency' => 'USD', 'supplier' => 'sup:68 Ltd', 'comment' => null], $rows[0]);
        $this->assertSame('OSS Китай', $rows[1]['supplier'], 'название поставщика — как в 1С');
        $this->assertSame('срок 30 дн.', $rows[1]['comment'], 'комментарий — поправленный снабжением');
        $this->assertSame(['Артикул', 'Название', 'Цена', 'Валюта', 'Поставщик', 'Комментарий'], PriceExportService::COMPANION_HEADER);
    }

    public function test_a_chosen_offer_no_longer_live_is_left_out(): void
    {
        // Выбранное предложение отмечено «Игнорировать» или устарело — в списке действующих его нет.
        $rows = PriceExportService::companionRows(collect([$this->offer(2, 10, 'M00647', 'org:21', 11.0)]),
            collect([10 => (object) ['source' => 'quoteparts', 'offer_ref' => '1']]), []);

        $this->assertSame([], $rows);
    }

    public function test_the_companion_file_is_written(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        config(['procurement.price_export.disk' => 'local']);
        $this->app->instance(\App\Services\Procurement\SupplierChoiceService::class, new class extends \App\Services\Procurement\SupplierChoiceService
        {
            public function __construct() {}

            public function forItems(array $catalogItemIds): \Illuminate\Support\Collection
            {
                return collect([10 => (object) ['source' => 'quoteparts', 'offer_ref' => '1']]);
            }
        });
        $service = new class extends PriceExportService
        {
            public function names1c(array $keys): array
            {
                return ['sup:68' => 'Тестовый поставщик'];
            }
        };

        $path = $service->writeCompanion(collect([$this->offer(1, 10, 'M00647', 'sup:68', 12.5)]), null);

        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($path);
        $this->assertStringContainsString('Покупаем здесь', $path);
        $tmp = tempnam(sys_get_temp_dir(), 'pc').'.xlsx';
        file_put_contents($tmp, \Illuminate\Support\Facades\Storage::disk('local')->get($path));
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);
        $this->assertSame(['Артикул', 'Название', 'Цена', 'Валюта', 'Поставщик', 'Комментарий'], $sheet->rangeToArray('A1:F1')[0]);
        $this->assertSame(['M00647', 'Позиция M00647', 12.5, 'USD', 'Тестовый поставщик'], array_slice($sheet->rangeToArray('A2:F2', null, false, false)[0], 0, 5));
    }

    public function test_each_user_exports_only_their_own_list(): void
    {
        $mine = (new PricePosition)->forceFill(['sku' => 'M1', 'in_export_at' => now(), 'in_export_by_user_id' => 9]);
        $this->assertTrue($mine->inExportOf(9));
        $this->assertFalse($mine->inExportOf(23), 'чужой список');
        $this->assertFalse($mine->inExportOf(null));
        $this->assertFalse((new PricePosition)->forceFill(['sku' => 'M2', 'in_export_at' => null, 'in_export_by_user_id' => 9])->inExportOf(9), 'не в выгрузке');
    }
}
