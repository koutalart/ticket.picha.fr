<?php

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\InvalidTicketCardDesignException;
use HiEvents\Services\Domain\Ticket\TicketCardDesignService;
use Illuminate\Console\Command;

class EventTicketCardCommand extends Command
{
    protected $signature = 'event:ticket-card
        {event : Event ID}
        {--background= : Local path of the card picture (its white box receives the QR and the guest name)}
        {--font-bold= : Local path of the bold TTF/OTF font (guest name, dress code)}
        {--font-regular= : Local path of the regular TTF/OTF font}
        {--font-light= : Local path of the light TTF/OTF font (last line)}
        {--frame= : White box as x,y,width,height in pixels (detected from the picture when omitted)}
        {--guests= : Number of people on each invitation (0 hides the line)}
        {--dress-code= : Dress code text, empty to hide the line}
        {--footer= : Last line, empty to hide it}
        {--default-footer : Use the default last line again}
        {--qr-color= : QR colour (#rrggbb)}
        {--text-color= : Name colour (#rrggbb)}
        {--accent-color= : Dress code colour (#rrggbb)}
        {--muted-color= : Last line colour (#rrggbb)}
        {--remove : Go back to the regular ticket}';

    protected $description = 'Show, set or remove the invitation card used for the PDF and the online ticket of an event (the Zebra label does not change)';

    public function __construct(
        private readonly TicketCardDesignService $designService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $eventId = (int) $this->argument('event');

        try {
            if ($this->option('remove')) {
                $this->designService->remove($eventId);
                $this->info(__('Event :id: invitation card removed.', ['id' => $eventId]));

                return self::SUCCESS;
            }

            $changes = $this->changes();
            $design = $changes === []
                ? $this->designService->current($eventId)
                : $this->designService->store($eventId, $changes);
        } catch (InvalidTicketCardDesignException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($design === null) {
            $this->info(__('Event :id has no invitation card.', ['id' => $eventId]));

            return self::SUCCESS;
        }

        $this->line((string) json_encode($design, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidTicketCardDesignException
     */
    private function changes(): array
    {
        $changes = [];

        if ($this->option('background') !== null) {
            $changes['background'] = (string) $this->option('background');
        }

        foreach (['bold', 'regular', 'light'] as $weight) {
            if ($this->option('font-'.$weight) !== null) {
                $changes['fonts'][$weight] = (string) $this->option('font-'.$weight);
            }
        }

        if ($this->option('frame') !== null) {
            $parts = array_map('trim', explode(',', (string) $this->option('frame')));
            if (count($parts) !== 4 || array_filter($parts, fn (string $part) => ! ctype_digit($part)) !== []) {
                throw new InvalidTicketCardDesignException(__('--frame expects x,y,width,height in pixels.'));
            }
            $changes['frame'] = array_combine(['x', 'y', 'width', 'height'], array_map('intval', $parts));
        }

        if ($this->option('guests') !== null) {
            $changes['guests'] = max(0, (int) $this->option('guests'));
        }

        if ($this->option('dress-code') !== null) {
            $changes['dress_code'] = trim((string) $this->option('dress-code'));
        }

        if ($this->option('default-footer')) {
            $changes['footer'] = null;
        } elseif ($this->option('footer') !== null) {
            $changes['footer'] = trim((string) $this->option('footer'));
        }

        foreach (['qr', 'text', 'accent', 'muted'] as $key) {
            if ($this->option($key.'-color') !== null) {
                $changes['colors'][$key] = (string) $this->option($key.'-color');
            }
        }

        return $changes;
    }
}
