<x-app-layout>
    <div class="py-4 px-4">
        <div class="max-w-[1440px] mx-auto">
            @if(session('status'))
                <div class="mb-4 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <livewire:invoices.external-payments />
        </div>
    </div>
</x-app-layout>
