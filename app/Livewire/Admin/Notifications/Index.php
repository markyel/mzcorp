<?php

namespace App\Livewire\Admin\Notifications;

use App\Enums\ClientNotificationType;
use App\Models\ClientNotificationTemplate;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    public function toggle(int $id): void
    {
        $this->ensureCanManage();
        $template = ClientNotificationTemplate::findOrFail($id);
        $template->forceFill([
            'is_enabled' => ! $template->is_enabled,
            'updated_by_user_id' => Auth::id(),
        ])->save();
        $msg = $template->is_enabled ? 'включено' : 'выключено';
        session()->flash('status', "«{$template->type->label()}» {$msg}.");
    }

    public function render()
    {
        $this->ensureCanManage();

        // Гарантируем что все типы существуют в БД (если seeder не отработал).
        foreach (ClientNotificationType::cases() as $type) {
            ClientNotificationTemplate::forType($type);
        }

        $templates = ClientNotificationTemplate::orderBy('id')->get();

        return view('livewire.admin.notifications.index', [
            'templates' => $templates,
        ]);
    }

    private function ensureCanManage(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->hasAnyRole(['head_of_sales', 'director', 'admin'])) {
            abort(403);
        }
    }
}
