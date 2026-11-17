<?php

namespace App\Services\CalendarPoll;

/**
 * Список участников опроса из вставленного текста: строка — один человек, имя и адрес в любом порядке,
 * через «;», запятую, табуляцию или «Имя <адрес>» (так копируется из почты и Excel).
 * Строка без адреса — в invalid; повтор адреса в списке — в duplicates.
 */
class VoterListParser
{
    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u';

    /**
     * @return array{valid: list<array{name: string, email: string}>, invalid: list<string>, duplicates: list<string>}
     */
    public function parse(string $text): array
    {
        $valid = [];
        $invalid = [];
        $duplicates = [];
        $seen = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! preg_match(self::EMAIL, $line, $m)) {
                $invalid[] = $line;

                continue;
            }
            $email = mb_strtolower(rtrim($m[0], '.'));
            if (isset($seen[$email])) {
                $duplicates[] = $email;

                continue;
            }
            $seen[$email] = true;

            $name = str_replace($m[0], ' ', $line);
            $name = preg_replace('/[<>"«»\[\]();,\t|]+/u', ' ', $name);
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name), " -–—:");
            if ($name === '') {
                $name = $this->nameFromEmail($email);
            }

            $valid[] = ['name' => mb_substr($name, 0, 120), 'email' => $email];
        }

        return ['valid' => $valid, 'invalid' => $invalid, 'duplicates' => $duplicates];
    }

    /** ivan.petrov@… → «Ivan Petrov»: лучше, чем пустое имя в обращении письма. */
    private function nameFromEmail(string $email): string
    {
        $local = (string) strstr($email, '@', true);

        return mb_convert_case(trim((string) preg_replace('/[._\-]+/', ' ', $local)), MB_CASE_TITLE);
    }
}
