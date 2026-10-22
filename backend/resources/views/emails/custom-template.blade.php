{{-- Custom Liquid Template Wrapper --}}
<x-mail::message
    :isFreeEvent="$isFreeEvent ?? false"
    :emailLogo="$emailLogo ?? null"
    :organizer="$organizer ?? null"
>
{!! $renderedBody !!}

@if(isset($renderedCta))
<x-mail::button :url="$renderedCta['url']">
    {{ $renderedCta['label'] }}
</x-mail::button>
@endif

{!! $eventSettings->getGetEmailFooterHtml() !!}

</x-mail::message>
