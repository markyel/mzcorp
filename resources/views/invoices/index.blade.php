<x-app-layout>
    <div class="py-4 px-4">
        <div class="max-w-[1440px] mx-auto">
            @if(session('status'))
                <div class="mb-4 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                    {{ session('status') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-800 text-sm">
                    {{ session('error') }}
                </div>
            @endif

            <livewire:invoices.index />
        </div>
    </div>
</x-app-layout>
