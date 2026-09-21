<?php

namespace App\Livewire\Clients;

use App\Models\ClientDiscount;
use App\Services\Clients\ClientDiscountImportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Скидки контрагентов: загрузка выгрузки из корпоративной базы и просмотр.
 *
 * Загрузка в два шага. Сначала разбор: показываем, что нашли в файле и что
 * изменится, и только потом запись. Скидка — это цена в КП клиенту, и молча
 * применять файл, который прислали «на посмотреть», нельзя.
 */
class Discounts extends Component
{
    use WithFileUploads;

    public $file;

    public string $search = '';

    /** Результат разбора — до записи. */
    public array $preview = [];

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->hasAnyRole(['head_of_sales', 'director', 'admin']), 403);
    }

    /** Разобрать файл и показать, что в нём. */
    public function analyze(ClientDiscountImportService $import): void
    {
        $this->reset('preview', 'notice', 'error');
        $this->validate(['file' => 'required|file|mimes:xlsx,xls|max:10240']);

        try {
            $parsed = $import->parse($this->file->getRealPath(), $this->file->getClientOriginalName());
        } catch (\Throwable $e) {
            $this->error = 'Файл не разобрался: '.$e->getMessage();

            return;
        }

        if ($parsed['rows'] === []) {
            $this->error = 'В файле не нашлось ни одной пригодной строки. '.implode(' · ', array_slice($parsed['errors'], 0, 3));

            return;
        }

        $this->preview = [
            'rows' => $parsed['rows'],
            'stats' => $parsed['stats'],
            'errors' => array_slice($parsed['errors'], 0, 20),
            'sample' => array_slice($parsed['rows'], 0, 8),
            'by_discount' => collect($parsed['rows'])
                ->groupBy(fn ($r) => (string) (float) $r['discount_percent'])
                ->map->count()->sortKeys()->all(),
        ];
    }

    /** Записать разобранное и проставить скидки организациям. */
    public function apply(ClientDiscountImportService $import): void
    {
        if (($this->preview['rows'] ?? []) === []) {
            return;
        }

        $res = $import->apply($this->preview['rows'], Auth::user());
        $this->reset('preview', 'file');
        unset($this->discounts, $this->stats);

        $this->notice = "Загружено {$res['saved']} контрагентов: сопоставлено с организациями {$res['matched']}"
            .($res['changed'] ? ", скидка изменилась у {$res['changed']}" : '')
            .($res['unmatched'] ? ", ещё нет у нас — {$res['unmatched']}" : '').'.';
    }

    public function cancel(): void
    {
        $this->reset('preview', 'file', 'error');
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator */
    #[Computed]
    public function discounts()
    {
        return ClientDiscount::query()
            ->with('organization:id,name')
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('name', 'ilike', $term)
                    ->orWhere('inn', 'like', $term)
                    ->orWhere('group_name', 'ilike', $term));
            })
            ->orderByDesc('discount_percent')
            ->orderBy('name')
            ->paginate(50);
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function stats(): array
    {
        return [
            'total' => ClientDiscount::count(),
            'matched' => ClientDiscount::whereNotNull('organization_id')->count(),
            'by_discount' => ClientDiscount::query()
                ->selectRaw('discount_percent, count(*) n')
                ->groupBy('discount_percent')
                ->orderBy('discount_percent')
                ->pluck('n', 'discount_percent')
                ->all(),
            'last' => ClientDiscount::query()->latest('updated_at')->first(['source_file', 'updated_at']),
        ];
    }

    public function render()
    {
        return view('livewire.clients.discounts');
    }
}
