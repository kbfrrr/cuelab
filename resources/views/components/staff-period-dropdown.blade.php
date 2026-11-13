@props(['label', 'options', 'selected', 'description' => null])

<x-filament::dropdown class="cl-filter-select" placement="bottom-start" shift>
    <x-slot name="trigger">
        <x-filament::button color="gray" icon="heroicon-o-chevron-down" icon-position="after"
            aria-label="{{ $label }}: {{ $options[$selected] ?? 'Choose a period' }}" :aria-describedby="$description">
            {{ $options[$selected] ?? 'Choose a period' }}
        </x-filament::button>
    </x-slot>

    <x-filament::dropdown.list>
        @foreach ($options as $value => $caption)
            @php
                $value = (string) $value;
                $active = $value === $selected;
            @endphp
            <x-filament::dropdown.list.item role="menuitemradio" :aria-checked="$active ? 'true' : 'false'"
                :loading-indicator="false" :wire:click="'$set(\'period\', '.\Illuminate\Support\Js::from($value).')'">
                {{ $caption }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
