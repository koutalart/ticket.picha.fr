<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ORGANIZER_ID = 4;

    private const OLD_SENTENCE = 'Le lien est toujours https://innocent976.yt, jamais un lien ticket.picha.fr.';

    private const NEW_SENTENCE = "Le lien est toujours sur innocent976.yt : le lien personnel du client s'il en a un (https://innocent976.yt/?qr=…), sinon https://innocent976.yt. Jamais un lien ticket.picha.fr.";

    public function up(): void
    {
        $this->replaceSentence(self::OLD_SENTENCE, self::NEW_SENTENCE);
    }

    public function down(): void
    {
        $this->replaceSentence(self::NEW_SENTENCE, self::OLD_SENTENCE);
    }

    private function replaceSentence(string $from, string $to): void
    {
        DB::table('organizer_sms_settings')
            ->where('organizer_id', self::ORGANIZER_ID)
            ->where('assistant_instructions', 'like', '%'.$from.'%')
            ->update(['assistant_instructions' => DB::raw('replace(assistant_instructions, '.DB::getPdo()->quote($from).', '.DB::getPdo()->quote($to).')')]);
    }
};
