<?php

namespace App\Enums;

/**
 * Направление письма.
 *
 * inbound  — входящее (получено в Inbox любого ящика).
 * outbound — исходящее (отправлено через MyLift или замечено в Sent
 *            при разборе исходящих писем менеджеров — см. OutgoingMailObserver
 *            из Foundation §1, Phase 1.9).
 */
enum MailDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
