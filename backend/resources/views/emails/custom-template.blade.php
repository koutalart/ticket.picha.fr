{{-- Custom Liquid Template Wrapper --}}
<x-mail::message\n    :isFreeEvent="$isFreeEvent ?? false"\n    :organizerLogoUrl="$organizerLogoUrl ?? null"\n    :organizer="$organizer ?? null"\n>
{!! $renderedBody !!}

@if(isset($renderedCta))
<x-mail::button :url="$renderedCta['url']">
    {{ $renderedCta['label'] }}
</x-mail::button>
@endif

{!! $eventSettings->getGetEmailFooterHtml() !!}

</x-mail::message>
