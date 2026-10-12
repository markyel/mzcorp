<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\MessagePersister;
use App\Services\Mail\OutgoingMailMimeBuilder;
use ReflectionMethod;
use Tests\TestCase;

/**
 * References без пробелов между Message-ID (так пишет mlt.pro): webklex
 * склеивал их в один «адрес», и ответ не собирался (12.10.2026, счёт
 * М3-10672 завис в «Ожидает отправки»).
 */
class ReferencesHeaderTest extends TestCase
{
    public function test_ids_without_separators_are_split_by_angle_brackets(): void
    {
        $raw = "From: a@mlt.pro\r\nReferences: <098f@mlt.pro><7f21-79@mzcorp.ru><2664@mail.360.yandex.ru>\r\nSubject: RE: x\r\n";

        $this->assertSame(['098f@mlt.pro', '7f21-79@mzcorp.ru', '2664@mail.360.yandex.ru'], MessagePersister::referencesFromRawHeader($raw));
    }

    public function test_folded_and_spaced_header_is_read_whole(): void
    {
        $raw = "References: <a@x.ru>\r\n <b@y.ru>,\r\n\t<c@z.ru>\r\nIn-Reply-To: <c@z.ru>\r\n";

        $this->assertSame(['a@x.ru', 'b@y.ru', 'c@z.ru'], MessagePersister::referencesFromRawHeader($raw));
    }

    public function test_no_references_header_gives_empty_list(): void
    {
        $this->assertSame([], MessagePersister::referencesFromRawHeader("Subject: x\r\nIn-Reply-To: <c@z.ru>\r\n"));
    }

    public function test_glued_ids_are_dropped_when_building_a_reply(): void
    {
        $m = new ReflectionMethod(OutgoingMailMimeBuilder::class, 'sanitizeMessageIds');
        $builder = (new \ReflectionClass(OutgoingMailMimeBuilder::class))->newInstanceWithoutConstructor();

        $out = $m->invoke($builder, ['098f@mlt.pro7f21-79@mzcorp.ru2664@mail.360.yandex.ru', '<463d@mlt.pro>', 'bad id@x.ru']);

        $this->assertSame(['463d@mlt.pro'], $out);
    }
}
