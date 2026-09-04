<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; color: #222; margin: 0; padding: 0; }
    .ticket { border: 3px solid {{ $accentColor }}; border-radius: 8px; margin: 16px; }
    .header { background-color: {{ $accentColor }}; color: #ffffff; padding: 18px 20px; }
    .event-title { font-size: 20px; font-weight: bold; margin: 0; }
    table.layout { width: 100%; border-collapse: collapse; }
    td.details { width: 62%; vertical-align: top; padding: 20px; }
    td.qr { width: 38%; vertical-align: top; padding: 20px; text-align: center; border-left: 1px solid #eee; }
    .row { margin-bottom: 12px; }
    .label { font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
    .value { font-size: 14px; font-weight: 600; color: #222; }
    .attendee-name { font-size: 16px; font-weight: bold; }
    .logo { max-width: 120px; max-height: 60px; margin-bottom: 10px; }
    .ticket-id { font-size: 11px; color: #888; margin-top: 10px; }
    .footer { padding: 12px 20px; border-top: 1px solid #eee; font-size: 10px; color: #999; text-align: center; }
</style>
</head>
<body>
<div class="ticket">
    <div class="header">
        <div class="event-title">{{ $event->getTitle() }}</div>
    </div>
    <table class="layout">
        <tr>
            <td class="details">
                @if($dateDisplayMode !== 'HIDDEN')
                <div class="row">
                    <div class="label">{{ __('Date & Time') }}</div>
                    <div class="value">{{ \Carbon\Carbon::parse($event->getStartDate(), $event->getTimezone())->format('d/m/Y H:i') }}</div>
                </div>
                @endif

                @if($organizer->getName())
                <div class="row">
                    <div class="label">{{ __('Organizer') }}</div>
                    <div class="value">{{ $organizer->getName() }}</div>
                </div>
                @endif

                @if($eventSettings->getLocationDetails())
                <div class="row">
                    <div class="label">{{ __('Location') }}</div>
                    <div class="value">{{ $eventSettings->getAddressString() }}</div>
                </div>
                @endif

                @if($product)
                <div class="row">
                    <div class="label">{{ __('Ticket Type') }}</div>
                    <div class="value">{{ $product->getTitle() }}</div>
                </div>
                @endif

                <div class="row">
                    <div class="label">{{ __('Attendee') }}</div>
                    <div class="attendee-name">{{ $attendee->getFirstName() }} {{ $attendee->getLastName() }}</div>
                    <div class="value">{{ $attendee->getEmail() }}</div>
                </div>
            </td>
            <td class="qr">
                @if($logoUrl)
                <img src="{{ $logoUrl }}" class="logo" />
                @endif
                <img src="data:image/png;base64,{{ $qrCodeBase64 }}" width="160" height="160" />
                <div class="ticket-id">{{ __('Ticket ID') }}<br>{{ $attendee->getPublicId() }}</div>
            </td>
        </tr>
    </table>
    @if($footerText)
    <div class="footer">{{ $footerText }}</div>
    @endif
</div>
</body>
</html>
