<?php

namespace App\Enums;

/**
 * Тип аутентификации ящика на IMAP/SMTP.
 *
 * password — классический app-password (Yandex App passwords, Gmail App passwords).
 * oauth    — OAuth 2.0 / XOAUTH2 (Yandex 360 OAuth, Microsoft 365, Google OAuth).
 */
enum MailboxAuthType: string
{
    case Password = 'password';
    case OAuth = 'oauth';
}
