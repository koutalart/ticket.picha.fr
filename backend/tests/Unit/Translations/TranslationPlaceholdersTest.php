<?php

declare(strict_types=1);

namespace Tests\Unit\Translations;

use Tests\TestCase;

class TranslationPlaceholdersTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([A-Za-z_]+)/', $text, $matches);
        $names = array_unique($matches[1]);
        sort($names);

        return $names;
    }

    public function test_translations_keep_the_same_placeholders_as_the_source(): void
    {
        $broken = [];

        foreach (glob(lang_path('*.json')) as $file) {
            $locale = basename($file, '.json');
            $translations = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            foreach ($translations as $source => $translation) {
                if ($translation === '' || $this->placeholders($source) === []) {
                    continue;
                }

                if ($this->placeholders($source) !== $this->placeholders($translation)) {
                    $broken[] = sprintf('[%s] "%s" => "%s"', $locale, $source, $translation);
                }
            }
        }

        self::assertSame([], $broken, "Translations with renamed or missing placeholders:\n".implode("\n", $broken));
    }
}
