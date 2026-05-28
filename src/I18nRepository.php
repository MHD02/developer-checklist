<?php

declare(strict_types=1);

final class I18nRepository
{
    public function __construct(private readonly string $directory)
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    /** @return array<int, array{code: string, name: string}> */
    public function languages(): array
    {
        $files = glob($this->directory . '/*.json') ?: [];
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        $languages = [];

        foreach ($files as $file) {
            $code = basename($file, '.json');
            if (! preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $code)) {
                continue;
            }
            $dictionary = $this->dictionary($code);
            $languages[] = ['code' => $code, 'name' => (string) ($dictionary['languageName'] ?? strtoupper($code))];
        }

        return $languages ?: [['code' => 'en', 'name' => 'English']];
    }

    /** @return array<string, mixed> */
    public function dictionary(string $language): array
    {
        $language = $this->normaliseLanguage($language);
        $fallback = $this->read('en');
        $current = $language === 'en' ? [] : $this->read($language);

        return array_replace_recursive($fallback, $current);
    }

    private function read(string $language): array
    {
        $file = $this->directory . '/' . $language . '.json';
        if (! is_file($file)) {
            return [];
        }
        $decoded = json_decode(file_get_contents($file) ?: '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normaliseLanguage(string $language): string
    {
        return preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $language) ? $language : 'en';
    }
}
