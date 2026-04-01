<div
    @if (in_array($this->getRecord()->status, ['pending', 'processing']))
        wire:poll.10s
    @endif
>
    <x-filament-panels::page>
        {{ $this->content }}
    </x-filament-panels::page>
</div>
