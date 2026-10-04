<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.email_logo_link_url')">
            @if(isset($isFreeEvent) && $isFreeEvent && isset($organizerLogoUrl) && $organizerLogoUrl)
                <img src="{{ $organizerLogoUrl }}" class="logo" alt="{{ $organizer->getName() ?? config('app.name') }}"
                     style="max-width: 300px;">
            @elseif($appLogo = config('app.email_logo_url'))
                <img src="{{ $appLogo }}" class="logo" alt="{{ config('app.name') }}"
                     style="max-width: 300px;">
            @else
                <img src="{{ config('app.frontend_url') }}/logos/picha-email-logo.png" class="logo" alt="{{ config('app.name') }}"
                     style="max-width: 300px;">
            @endif
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            @if($appEmailFooter = config('app.email_footer_text'))
                {{ $appEmailFooter }}
            @else
                © {{ date('Y') }} {{ config('app.name') }} | {{ (isset($isFreeEvent) && $isFreeEvent) ? 'Propulsé par PICHA AI' : 'Powered by PICHA AI' }}
            @endif
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
