<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $ticket->eventTitle }} — {{ $ticket->publicId }}</title>
<style>
    @page { margin: 0; }
    html, body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; }
    .text-layer { position: absolute; top: 0; left: 0; width: 80mm; font-size: 4pt; color: #ffffff; line-height: 1.2; }
    .ticket { position: absolute; top: 0; left: 0; width: 80mm; height: 80mm; }
</style>
</head>
<body>
<div class="text-layer">
    {{ $ticket->eventTitle }} · {{ $ticket->productTitle }} · {{ $ticket->attendeeName }}
    · {{ __('Date & Time') }} : {{ $ticket->eventDate }} {{ $ticket->eventTime }}
    · {{ __('Location') }} : {{ $ticket->venue }}
    · {{ __('Ticket ID') }} : {{ $ticket->publicId }}
</div>
<img class="ticket" src="data:image/png;base64,{{ $ticketImageBase64 }}" alt="{{ $ticket->eventTitle }} — {{ $ticket->publicId }}">
</body>
</html>
