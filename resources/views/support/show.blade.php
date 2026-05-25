<x-app-layout>
    <livewire:support.ticket-view :ticket="$ticket" wire:key="ticket-view-{{ $ticket->id }}" />
</x-app-layout>
