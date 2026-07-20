<?php

namespace App\Livewire\HonestSign;

use App\Models\HonestSignBatch;
use App\Models\HonestSignCode;
use App\Services\HonestSign\HonestSignExcelFiller;
use App\Services\HonestSign\HonestSignPdfParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Раздел «Честный знак» — разбор PDF с кодами маркировки (DataMatrix).
 *
 * Два режима (заказчик выбрал оба):
 *   1. «Заполнить файл поставки»: Excel + пачка PDF → коды разложены по
 *      строкам через MZ-ID → готовый .xlsx на скачивание;
 *   2. «Только разобрать»: PDF → GTIN и готовая строка КИЗ на экране с
 *      кнопкой копирования (вставить в свой файл руками).
 *
 * Результат каждого разбора пишется в журнал (honest_sign_batches/_codes) —
 * поиск «в какую поставку ушёл этот КИЗ» и детект повторной подачи. Сами
 * файлы не хранятся: PDF/Excel удаляются сразу после обработки.
 *
 * Доступ: директорат / РОП / секретарь / админ (роут-гейт role:...).
 */
class Index extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $pdfs = [];

    public $excel = null;

    public string $tab = 'parse';   // parse | journal

    public string $search = '';

    /** Результат последнего разбора (для показа на экране). */
    public array $result = [];

    public ?int $lastBatchId = null;

    /** Путь к готовому файлу в storage/app (временный, до скачивания). */
    public ?string $filledPath = null;

    public ?string $filledName = null;

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['parse', 'journal'], true) ? $tab : 'parse';
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Разобрать загруженные PDF (+ при наличии — заполнить файл поставки).
     */
    public function process(HonestSignPdfParser $parser, HonestSignExcelFiller $filler): void
    {
        $this->validate([
            'pdfs' => 'required|array|min:1',
            'pdfs.*' => 'file|mimes:pdf|max:25600',
            'excel' => 'nullable|file|mimes:xlsx,xls|max:25600',
        ], [], [
            'pdfs' => 'PDF-файлы',
            'excel' => 'файл поставки',
        ]);

        $this->reset(['result', 'filledPath', 'filledName', 'lastBatchId']);

        $allCodes = [];
        $warnings = [];

        foreach ($this->pdfs as $pdf) {
            $name = $pdf->getClientOriginalName();
            $parsed = $parser->parse($pdf->getRealPath());

            foreach ($parsed['warnings'] as $w) {
                $warnings[] = "{$name}: {$w}";
            }
            if ($parsed['codes'] === []) {
                $warnings[] = "{$name}: не найдено ни одного кода. "
                    . 'Вероятно, PDF без текстового слоя (скан) — распознавание картинок пока не поддерживается.';
                continue;
            }
            foreach ($parsed['codes'] as $c) {
                $c['source_file'] = $name;
                $allCodes[] = $c;
            }
        }

        if ($allCodes === []) {
            $this->addError('pdfs', 'Не удалось прочитать ни одного кода маркировки. ' . implode(' ', $warnings));

            return;
        }

        $grouped = $parser->groupByArticle($allCodes);

        // Повторная подача: этот код уже проходил через систему раньше.
        $seen = HonestSignCode::query()
            ->whereIn('code', array_column($allCodes, 'code'))
            ->pluck('code')
            ->all();

        $fillReport = ['filled' => [], 'unmatched' => []];

        if ($this->excel) {
            try {
                $outName = 'ЧЗ_' . $this->excel->getClientOriginalName();
                $outPath = storage_path('app/honest-sign/' . Str::random(12) . '.xlsx');
                if (! is_dir(dirname($outPath))) {
                    mkdir(dirname($outPath), 0775, true);
                }
                $fillReport = $filler->fill($this->excel->getRealPath(), $grouped, $outPath);
                $this->filledPath = $outPath;
                $this->filledName = $outName;
            } catch (\Throwable $e) {
                $this->addError('excel', $e->getMessage());
            }
        }

        $batch = DB::transaction(function () use ($allCodes, $warnings, $fillReport) {
            $batch = HonestSignBatch::create([
                'user_id' => auth()->id(),
                'title' => $this->excel?->getClientOriginalName()
                    ?? ($this->pdfs[0]->getClientOriginalName() ?? null),
                'pdf_count' => count($this->pdfs),
                'codes_count' => count($allCodes),
                'rows_filled' => count($fillReport['filled']),
                'warnings' => $warnings !== [] ? $warnings : null,
            ]);

            foreach ($allCodes as $c) {
                HonestSignCode::create([
                    'honest_sign_batch_id' => $batch->id,
                    'code' => $c['code'],
                    'gtin' => $c['gtin'],
                    'serial' => mb_substr($c['serial'], 0, 255),
                    'article' => $c['article'],
                    'product_name' => $c['name'] ? mb_substr($c['name'], 0, 500) : null,
                    'source_file' => $c['source_file'] ?? null,
                    'page' => $c['page'] ?? null,
                ]);
            }

            return $batch;
        });

        $this->lastBatchId = $batch->id;
        $this->result = [
            'groups' => array_map(
                fn ($art, $d) => [
                    'article' => $art,
                    'gtin' => $d['gtin'],
                    'codes' => $d['codes'],
                    'kiz' => $parser->formatKizCell($d['codes']),
                ],
                array_keys($grouped),
                $grouped,
            ),
            'total' => count($allCodes),
            'pdfs' => count($this->pdfs),
            'warnings' => $warnings,
            'duplicates' => $seen,
            'filled' => $fillReport['filled'],
            'unmatched' => $fillReport['unmatched'],
        ];

        $this->reset(['pdfs', 'excel']);
    }

    /** Отдать заполненный файл поставки и убрать его с диска. */
    public function download()
    {
        if (! $this->filledPath || ! is_file($this->filledPath)) {
            $this->addError('excel', 'Файл больше недоступен — разберите заново.');

            return null;
        }

        return response()->download($this->filledPath, $this->filledName ?: 'supply.xlsx')
            ->deleteFileAfterSend(true);
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator */
    #[Computed]
    public function batches()
    {
        return HonestSignBatch::query()
            ->with('user:id,name')
            ->withCount('codes')
            ->latest('id')
            ->paginate(15);
    }

    /**
     * Поиск по кодам: точное совпадение, часть кода, GTIN или артикул.
     *
     * @return \Illuminate\Support\Collection<int, HonestSignCode>
     */
    #[Computed]
    public function foundCodes()
    {
        $q = trim($this->search);
        if (mb_strlen($q) < 3) {
            return collect();
        }
        $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        return HonestSignCode::query()
            ->with(['batch:id,user_id,title,created_at', 'batch.user:id,name'])
            ->where(fn ($w) => $w
                ->where('code', 'ilike', $needle)
                ->orWhere('gtin', 'ilike', $needle)
                ->orWhere('article', 'ilike', $needle)
                ->orWhere('product_name', 'ilike', $needle))
            ->latest('id')
            ->limit(100)
            ->get();
    }

    public function render()
    {
        return view('livewire.honest-sign.index');
    }
}
