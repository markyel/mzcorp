<?php

namespace Tests\Unit\Services\Mail;

use App\Models\MailboxFolder;
use App\Services\Mail\ImapFolderSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Чистые части синка папок: фильтр LIST, Message-ID из заголовков, COPYUID,
 * кодирование имён папок (MUTF-7).
 */
class ImapFolderSyncServiceTest extends TestCase
{
    private const SYSTEM = ['INBOX', 'Sent', 'Drafts', 'Outbox', 'Spam', 'Trash', 'Archive', 'Отправленные'];

    private const EXCLUDED = ['MZ', 'MyLift'];

    public function test_custom_folders_from_yandex_list_skips_system_and_service_trees(): void
    {
        $list = [
            'Drafts' => ['delimiter' => '|', 'flags' => ['\\Drafts']],
            'Drafts|template' => ['delimiter' => '|', 'flags' => []],
            'INBOX' => ['delimiter' => '|', 'flags' => []],
            'MZ' => ['delimiter' => '|', 'flags' => []],
            'MZ|Yakubovich' => ['delimiter' => '|', 'flags' => []],
            'Outbox' => ['delimiter' => '|', 'flags' => []],
            'Sent' => ['delimiter' => '|', 'flags' => ['\\Sent']],
            'Spam' => ['delimiter' => '|', 'flags' => ['\\Junk']],
            'Trash' => ['delimiter' => '|', 'flags' => ['\\Trash']],
            '&BB4EMQRKBDUEOgRCBEs-' => ['delimiter' => '|', 'flags' => ['\\HasChildren']],          // Объекты
            '&BB4EMQRKBDUEOgRCBEs-|&BBoEHgQdBBU-' => ['delimiter' => '|', 'flags' => []],         // Объекты|КОНЕ
            '&BB4EMQRKBDUEOgRCBEs-|&BBoEHgQdBBU-|2026' => ['delimiter' => '|', 'flags' => []],
            'Projects' => ['delimiter' => '|', 'flags' => ['\\Noselect']],
            'Projects|Alpha' => ['delimiter' => '|', 'flags' => []],
        ];

        $out = ImapFolderSyncService::customFoldersFromList($list, '|', self::SYSTEM, self::EXCLUDED);

        $this->assertSame([
            '&BB4EMQRKBDUEOgRCBEs-',
            '&BB4EMQRKBDUEOgRCBEs-|&BBoEHgQdBBU-',
            'Projects|Alpha',
            '&BB4EMQRKBDUEOgRCBEs-|&BBoEHgQdBBU-|2026',
        ], array_keys($out));

        $this->assertSame('Объекты', $out['&BB4EMQRKBDUEOgRCBEs-']['name']);
        $this->assertNull($out['&BB4EMQRKBDUEOgRCBEs-']['parent']);
        $this->assertSame('КОНЕ', $out['&BB4EMQRKBDUEOgRCBEs-|&BBoEHgQdBBU-']['name']);
        $this->assertSame('&BB4EMQRKBDUEOgRCBEs-', $out['&BB4EMQRKBDUEOgRCBEs-|&BBoEHgQdBBU-']['parent']);
        $this->assertSame(2, $out['&BB4EMQRKBDUEOgRCBEs-|&BBoEHgQdBBU-|2026']['depth']);
        $this->assertSame('2026', $out['&BB4EMQRKBDUEOgRCBEs-|&BBoEHgQdBBU-|2026']['name']);
    }

    public function test_children_of_special_use_folders_are_not_custom(): void
    {
        $list = [
            '[Gmail]' => ['delimiter' => '/', 'flags' => ['\\Noselect', '\\HasChildren']],
            '[Gmail]/Sent Mail' => ['delimiter' => '/', 'flags' => ['\\Sent']],
            '[Gmail]/Sent Mail/Old' => ['delimiter' => '/', 'flags' => []],
            'Clients' => ['delimiter' => '/', 'flags' => []],
        ];
        $out = ImapFolderSyncService::customFoldersFromList($list, '/', self::SYSTEM, self::EXCLUDED);

        $this->assertSame(['Clients'], array_keys($out));
    }

    public function test_message_id_from_headers_unfolds_and_strips_brackets(): void
    {
        $headers = "From: a@b.c\r\nMessage-ID:\r\n <002b01dd273d\$db88d910\$929a8b30\$@vectalift.com>\r\nSubject: x\r\n";
        $this->assertSame('002b01dd273d$db88d910$929a8b30$@vectalift.com', ImapFolderSyncService::messageIdFromHeaders($headers));

        $this->assertSame('abc@host', ImapFolderSyncService::messageIdFromHeaders("Message-Id: abc@host\n"));
        $this->assertNull(ImapFolderSyncService::messageIdFromHeaders("Subject: no id\n"));
    }

    public function test_copyuid_map_handles_sets_and_ranges(): void
    {
        $map = ImapFolderSyncService::parseCopyUidMap([
            "OK [COPYUID 1788866346 1,5:7 10:13]\r\n",
            "120724 EXPUNGE\r\n",
            "OK UID MOVE Completed.\r\n",
        ]);
        $this->assertSame([1 => 10, 5 => 11, 6 => 12, 7 => 13], $map);

        $this->assertSame([121895 => 1], ImapFolderSyncService::parseCopyUidMap(['OK [COPYUID 1788866346 121895 1]']));
        $this->assertSame([], ImapFolderSyncService::parseCopyUidMap(['OK UID MOVE Completed.']));
        $this->assertSame([], ImapFolderSyncService::parseCopyUidMap(['OK [COPYUID 1 1:3 10]']), 'mismatched sets → empty');
    }

    public function test_folder_name_roundtrip_mutf7(): void
    {
        $seg = MailboxFolder::imapSegmentFromName('Тест синк', '|');
        $this->assertSame('&BCIENQRBBEI- &BEEEOAQ9BDo-', $seg);
        $this->assertSame('Тест синк', MailboxFolder::displayNameFromImapPath('Parent|' . $seg, '|'));

        // Разделитель в имени недопустим — заменяем пробелом.
        $this->assertSame('a b', MailboxFolder::imapSegmentFromName('a|b', '|'));
        $this->assertSame('Projects', MailboxFolder::displayNameFromImapPath('Projects', '|'));
    }
}
