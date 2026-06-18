<?php

namespace App\Livewire\Requests;

use App\Enums\Role;
use App\Models\Request as RequestModel;
use App\Services\Supplier\SupplierDispatchService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Диалог рассылки запросов расценки поставщикам (Фаза 3.2). Открывается
 * событием `open-supplier-dispatch`. Подбираем поставщиков по матрице
 * ассортимента под активные позиции заявки; менеджер ГАЛОЧКАМИ выбирает, кому
 * слать (по умолчанию никто — частая категория матчит много поставщиков, блас
 * всем не нужен). Каждому уходит письмо с его позициями.
 */
class SupplierDispatchDialog extends Component
{
    public int $requestId;
    public bool $open = false;

    /** supplier_id => bool (отправлять ли этому поставщику). */
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
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /**
     * Подобранные поставщики под активные позиции заявки (preview).
     *
     * @return array{groups: array<int, array{id:int, name:string, item_count:int, items:string}>, no_supplier: int, total_items: int}
     */
    public function previewData(SupplierDispatchService $dispatcher): array
    {
        $req = RequestModel::findOrFail($this->requestId);
        $itemIds = $req->items()->where('is_active', true)->pluck('id')->all();
        $p = $dispatcher->preview($req, $itemIds);

        $groups = [];
        foreach ($p['groups'] as $g) {
            $groups[] = [
                'id' => $g['supplier']->id,
                'name' => $g['supplier']->name ?: $g['supplier']->email,
                'item_count' => count($g['items']),
                'items' => collect($g['items'])->map(fn ($it) => $it->parsed_name)->filter()->take(4)->implode('; '),
            ];
        }
        // Больше покрытых позиций — выше.
        usort($groups, fn ($a, $b) => $b['item_count'] <=> $a['item_count']);

        return ['groups' => $groups, 'no_supplier' => count($p['no_supplier']), 'total_items' => count($itemIds)];
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

        $supplierIds = array_values(array_map('intval', array_keys(array_filter($this->selected))));
        if ($supplierIds === []) {
            $this->addError('selected', 'Отметьте хотя бы одного поставщика.');

            return null;
        }

        $result = $dispatcher->dispatch($req, $supplierIds, [], $this->note, $user);

        $this->open = false;
        $msg = "Отправлено запросов поставщикам: {$result['sent']}.";
        if ($result['failed'] > 0) {
            $msg .= " Ошибок: {$result['failed']}.";
        }
        session()->flash('status', $msg);

        return $this->redirect(
            \Illuminate\Support\Facades\Request::header('Referer') ?: route('requests.show', $req),
            navigate: false,
        );
    }

    public function render(SupplierDispatchService $dispatcher)
    {
        return view('livewire.requests.supplier-dispatch-dialog', [
            'preview' => $this->open ? $this->previewData($dispatcher) : ['groups' => [], 'no_supplier' => 0, 'total_items' => 0],
        ]);
    }
}
