<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @font-face { font-family: 'Roboto Condensed'; font-weight: normal; src: url('{{ $fonts['regular'] }}') format('truetype'); }
    @font-face { font-family: 'Roboto Condensed'; font-weight: bold; src: url('{{ $fonts['bold'] }}') format('truetype'); }
    @page { margin: 16mm 0 12mm 0; }
    body { font-family: 'Roboto Condensed', 'DejaVu Sans', sans-serif; color: #1E1433; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    td { padding: 0; vertical-align: middle; }
    .ticket { width: 124mm; margin: 0 auto; padding: 7mm 8mm 5mm 8mm; border: 0.35mm solid #DDD6EA; border-radius: 4mm; }
    .header td { height: 22mm; }
    .event-logo { max-width: 56mm; max-height: 20mm; }
    .organizer { font-size: 17pt; font-weight: bold; color: #422A6A; line-height: 1.1; }
    .divider { width: 0.4mm; background-color: #422A6A; }
    .sponsor { text-align: right; }
    .sponsor-label { font-size: 8pt; color: #6E6585; margin-bottom: 1.5mm; }
    .sponsor-logo { max-width: 42mm; max-height: 14mm; }
    .sponsor-name { font-size: 14pt; font-weight: bold; color: #1E1433; }
    .event-label { white-space: pre; font-size: 7.5pt; font-weight: bold; color: #6E6585; margin-top: 7mm; }
    .event-title { font-size: 24pt; font-weight: bold; color: #422A6A; line-height: 1.05; margin-top: 1mm; }
    .event-title.long { font-size: 17pt; }
    .title-bar { width: 18mm; height: 1.6mm; background-color: #FDB900; border-radius: 0.8mm; margin-top: 2.5mm; }
    .body { margin-top: 7mm; }
    .rows { width: 66mm; }
    .row td { padding: 2.2mm 0; border-bottom: 0.3mm dotted #B9B0CC; }
    .row.last td { border-bottom: none; }
    .row .icon { width: 10mm; }
    .row .icon img { width: 6.5mm; height: 6.5mm; }
    .row-label { white-space: pre; font-size: 7pt; font-weight: bold; color: #6E6585; }
    .row-value { font-size: 14pt; font-weight: bold; color: #1E1433; line-height: 1.15; }
    .qr { text-align: center; vertical-align: top; }
    .qr img { width: 40mm; height: 40mm; }
    .ticket-id { white-space: pre; font-size: 10pt; font-weight: bold; color: #1E1433; margin-top: 1mm; }
    .attendee-name { font-size: 10pt; color: #1E1433; margin-top: 0.8mm; }
    .footer { margin-top: 6mm; border-top: 0.45mm dashed #422A6A; }
    .footer td { padding-top: 5mm; }
    .picha-logo { width: 33mm; }
    .picha-product { font-size: 8.5pt; font-weight: bold; color: #422A6A; margin: 0.3mm 0 0 7mm; }
    .footer .divider { height: 13mm; }
    .footer-right { padding-left: 6mm; }
    .footer-label { font-size: 7pt; color: #6E6585; }
    .site { font-size: 13pt; font-weight: bold; color: #422A6A; }
    .site img { width: 4.7mm; height: 4.7mm; vertical-align: middle; margin-right: 1.5mm; }
    .organizer-footer { width: 124mm; margin: 3mm auto 0 auto; font-size: 8pt; color: #6E6585; text-align: center; }
</style>
</head>
<body>
<div class="ticket">
    <table class="header">
        <tr>
            <td style="width: 60%;">
                @if($eventLogo)
                    <img src="{{ $eventLogo }}" class="event-logo" />
                @elseif($content->organizer_name !== '')
                    <div class="organizer">{{ $content->organizer_name }}</div>
                @endif
            </td>
            @if($sponsorLogo || $content->sponsor_name !== '')
                <td style="width: 4%;"><div class="divider" style="height: 18mm;"></div></td>
                <td class="sponsor">
                    <div class="sponsor-label">{{ __('Sponsor') }}</div>
                    @if($sponsorLogo)
                        <img src="{{ $sponsorLogo }}" class="sponsor-logo" />
                    @else
                        <div class="sponsor-name">{{ $content->sponsor_name }}</div>
                    @endif
                </td>
            @endif
        </tr>
    </table>

    <div class="event-label">{{ $spaced(mb_strtoupper(__('Event'))) }}</div>
    <div class="event-title{{ mb_strlen($content->event_title) > 26 ? ' long' : '' }}">{{ $content->event_title }}</div>
    <div class="title-bar"></div>

    <table class="body">
        <tr>
            <td class="rows" style="vertical-align: top;">
                <table>
                    @foreach($rows as $index => $row)
                        <tr class="row{{ $loop->last ? ' last' : '' }}">
                            <td class="icon"><img src="{{ $icons[$row['icon']] }}" /></td>
                            <td>
                                <div class="row-label">{{ $spaced(mb_strtoupper($row['label'])) }}</div>
                                <div class="row-value">{{ $row['value'] }}</div>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td>
            <td class="qr">
                <img src="data:image/png;base64,{{ $qrCodeBase64 }}" />
                <div class="ticket-id">{{ $spaced($displayId) }}</div>
                @if($content->attendee_name !== '')
                    <div class="attendee-name">{{ $content->attendee_name }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="footer">
        <tr>
            <td style="width: 44%;">
                <img src="{{ $pichaLogo }}" class="picha-logo" />
                <div class="picha-product">Ticket</div>
            </td>
            <td style="width: 2%;"><div class="divider"></div></td>
            <td class="footer-right">
                <div class="footer-label">{{ __('Event ticketing & management') }}</div>
                <div class="site"><img src="{{ $icons['globe'] }}" />ticket.picha.fr</div>
            </td>
        </tr>
    </table>
</div>
@if($footerText)
    <div class="organizer-footer">{{ $footerText }}</div>
@endif
</body>
</html>
