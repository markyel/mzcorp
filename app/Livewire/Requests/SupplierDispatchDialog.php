<?php

namespace App\Livewire\Requests;

use App\Enums\Role;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Services\Supplier\SupplierDispatchService;
use App\Services\Supplier\SupplierMatchService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Диалог рассылки запросов расценки поставщикам (Фаза 3.2). Открывается
 * событием `open-supplier-dispatch`. Менеджер выбирает позиции, видит
 * подобранных поставщиков (по матрице ассортимента), добавляет примечание и
 * отправляет — на каждого поставщика уходит одно письмо со списком его позиций.
 */
class SupplierDispatchDialog extends Component
{
    public int $requestId;
    public bool $open = false;

    /** item_id => bool (выбрана ли позиция для рассылки). */
    public array $selected = [];

    public string $note = '';

    public function mount(RequestModel $request): void
    {
        $this->requestId = $request->id;
    }

    #[On('open-supplier-dispatch')]
    public function show(): void
    {
        $this->note = '';
        $this->selected = [];
        foreach ($this->itemRows() as $row) {
            // По умолчанию выбираем позиции, под которые есть поставщик.
            $this->selected[$row['id']] = $row['supplier_count'] > 0;
        }
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /**
     * Активные позиции заявки с числом подобранных поставщиков.
     *
     * @return array<int, array{id:int, name:string, article:?string, brand:?string, qty:?string, supplier_count:int, suppliers:string}>
     */
    public function itemRows(): array
    {
        $matcher = app(SupplierMatchService::class);
        $items = RequestItem::query()
            ->where('request_id', $this->requestId)
            ->where('is_active', true)
            ->with(['brand:id,name', 'kbCategory:id,name,synonyms', 'catalogItem:id,brand,equipment_category_id', 'catalogItem.equipmentCategory:id,name,synonyms'])
            ->orderBy('position')
            ->get();

        $rows = [];
        foreach ($items as $it) {
            $sups = $matcher->relevantSuppliers($it);
            $rows[] = [
                'id' => $it->id,
                'name' => (string) ($it->parsed_name ?: '—'),
                'article' => $it->parsed_article,
                'brand' => $it->brand?->name ?: $it->parsed_brand,
                'qty' => $it->parsed_qty ? trim($it->parsed_qty . ' ' . ($it->parsed_unit ?: 'шт.')) : null,
                'supplier_count' => $sups->count(),
                'suppliers' => $sups->map(fn ($s) => $s->name ?: $s->email)->take(4)->implode(', '),
            ];
        }

        return $rows;
    }

    public function send(SupplierDispatchService $dispatcher)
    {
        $req = RequestModel::findOrFail($this->requestId);
        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }
        $privileged = $user->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Admin->value]);
        if ($user->hasRole(Role::Secretary->value) || (! $privileged && ! $req->isAccessibleBy($user))) {
            abort(403, 'Доступно назначенному менеджеру, acting или РОПу.');
        }

        $itemIds = array_values(array_keys(array_filter($this->selected)));
        if ($itemIds === []) {
            $this->addError('selected', 'Выберите хотя бы одну позицию.');

            return null;
        }

        $result = $dispatcher->dispatch($req, $itemIds, $this->note, $user);

        $this->open = false;
        $msg = "Отправлено запросов поставщикам: {$result['sent']}.";
        if ($result['failed'] > 0) {
            $msg .= " Ошибок: {$result['failed']}.";
        }
        if ($result['no_supplier'] > 0) {
            $msg .= " Позиций без поставщика: {$result['no_supplier']}.";
        }
        session()->flash('status', $msg);

        return $this->redirect(
            \Illuminate\Support\Facades\Request::header('Referer') ?: route('requests.show', $req),
            navigate: false,
        );
    }

    public function render()
    {
        return view('livewire.requests.supplier-dispatch-dialog', ['rows' => $this->itemRows()]);
    }
}
