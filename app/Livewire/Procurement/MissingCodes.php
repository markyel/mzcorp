<?php

namespace App\Livewire\Procurement;

use App\Enums\Role;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * «Не найдено в каталоге»: топ повторяющихся OEM-кодов из заявок, по которым
 * автоматика распознала артикул производителя (match_path=brand_article), но
 * в каталоге совпадения нет. Это пробелы базы кодов-синонимов и кандидаты на
 * расширение ассортимента: 842 таких заявки выигрываются на 11,7% против 40%
 * у полностью распознанных (анализ 2026-07-05). Доступ — как у «Снабжения».
 */
class MissingCodes extends Component
{
    use WithPagination;

    public const PERIODS = [30, 60, 90, 0]; // 0 = всё время

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'days', except: 60)]
    public int $periodDays = 60;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasAnyRole([
            Role::Procurement->value, Role::Manager->value,
            Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
        ]), 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingPeriodDays(): void
    {
        $this->resetPage();
    }

    /**
     * Агрегат по нормализованному OEM-коду.
     *
     * @return array{sql: string, bindings: array<int, mixed>}
     */
    private function baseSql(): array
    {
        $noise = "('off_topic','spam','duplicate','parser_no_content','supplier_reply')";
        $days = in_array($this->periodDays, self::PERIODS, true) ? $this->periodDays : 60;

        $sql = "
            select upper(trim(ri.parsed_article)) as code,
              (array_agg(ri.parsed_name order by ri.id desc))[1] as sample_name,
              (array_agg(coalesce(mb.name, nullif(trim(ri.parsed_brand), '')) order by ri.id desc)
                 filter (where coalesce(mb.name, nullif(trim(ri.parsed_brand), '')) is not null))[1] as brand,
              count(distinct ri.request_id) as reqs,
              count(*) as items,
              max(r.created_at)::date as last_seen,
              count(distinct r.id) filter (where r.status = 'closed_lost') as lost,
              count(distinct r.id) filter (where r.status = 'closed_won') as won,
              (array_agg(distinct r.internal_code))[1:6] as codes
            from request_items ri
            join requests r on r.id = ri.request_id and r.merged_into_id is null
              and not (r.status = 'closed_lost' and coalesce(r.closed_lost_reason, '') in {$noise})
            left join manufacturer_brands mb on mb.id = ri.manufacturer_brand_id
            where ri.is_active
              and ri.catalog_item_id is null
              and ri.match_path = 'brand_article'
              and coalesce(trim(ri.parsed_article), '') != ''
        ";
        $bindings = [];
        if ($days > 0) {
            $sql .= ' and r.created_at >= ?';
            $bindings[] = now()->subDays($days);
        }
        if (trim($this->search) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->search)).'%';
            $sql .= ' and (ri.parsed_article ilike ? or ri.parsed_name ilike ? or coalesce(mb.name, ri.parsed_brand) ilike ?)';
            array_push($bindings, $like, $like, $like);
        }
        $sql .= ' group by 1 order by count(distinct ri.request_id) desc, count(*) desc, 1';

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /** @return array<int, object> */
    private function allRows(): array
    {
        ['sql' => $sql, 'bindings' => $bindings] = $this->baseSql();

        return DB::select($sql, $bindings);
    }

    #[Computed]
    public function codes(): LengthAwarePaginator
    {
        $rows = $this->allRows();
        $perPage = 50;
        $page = Paginator::resolveCurrentPage();
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        // Обогащение страницы: товар может УЖЕ быть в каталоге — код в названии
        // или в алиасах, но не в артикулах позиции (автопривязка его не видит).
        // Ищем «мягко»: разделители (точки/дефисы/пробелы) заменяем на wildcard —
        // клиент пишет «IDD32.001.P», в названии каталога «IDD32.001P».
        foreach ($slice as $row) {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], (string) $row->code);
            $loose = '%'.preg_replace('/[^\p{L}\p{N}\\\\%_]+/u', '%', $escaped).'%';
            $row->in_catalog_now = DB::selectOne(
                'select exists(select 1 from catalog_items where is_active
                    and (articles_search ilike ? or name ilike ? or name_en ilike ?)) as e',
                [$loose, $loose, $loose],
            )->e;
            $row->request_codes = array_values(array_filter(
                array_map('trim', explode(',', trim((string) $row->codes, '{}"'))),
            ));
        }

        return new LengthAwarePaginator($slice, count($rows), $perPage, $page, ['path' => Paginator::resolveCurrentPath()]);
    }

    /** Выгрузка всего списка в xlsx (текущие фильтры). */
    public function exportExcel()
    {
        $rows = $this->allRows();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('OEM не в каталоге');

        $headers = ['OEM-код', 'Пример названия из заявки', 'Марка', 'Заявок', 'Позиций', 'Выиграно', 'Проиграно', 'Последний запрос', 'Заявки'];
        foreach ($headers as $col => $h) {
            $cell = $sheet->getCell([$col + 1, 1]);
            $cell->setValue($h);
            $cell->getStyle()->getFont()->setBold(true);
        }
        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray([
                (string) $row->code,
                (string) $row->sample_name,
                (string) $row->brand,
                (int) $row->reqs,
                (int) $row->items,
                (int) $row->won,
                (int) $row->lost,
                (string) $row->last_seen,
                trim(str_replace('"', '', (string) $row->codes), '{}'),
            ], null, 'A'.$r);
            $r++;
        }
        foreach (['A' => 24, 'B' => 55, 'C' => 26, 'D' => 9, 'E' => 9, 'F' => 10, 'G' => 11, 'H' => 15, 'I' => 55] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->setAutoFilter('A1:I'.max(2, $r - 1));
        $sheet->freezePane('A2');

        $filename = 'OEM_не_в_каталоге_'.($this->periodDays > 0 ? $this->periodDays.'дн' : 'всё').'.xlsx';
        $path = tempnam(sys_get_temp_dir(), 'oem_export');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function render()
    {
        return view('livewire.procurement.missing-codes');
    }
}
