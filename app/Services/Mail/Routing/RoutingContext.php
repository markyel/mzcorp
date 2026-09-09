<?php

namespace App\Services\Mail\Routing;

use App\Models\EmailMessage;
use App\Models\Request;

/**
 * Состояние одного прохода маршрутизатора по письму, общее для обработчиков
 * цепочки (RoutingPipeline). Обработчики читают письмо и, по мере продвижения,
 * заполняют общие факты (привязанная заявка и т.п.), чтобы следующие могли на
 * них опираться — без скрытых зависимостей через локальные переменные одного
 * 800-строчного метода.
 */
final class RoutingContext
{
    /** Заявка, к которой письмо привязано линкером / роутером цитат (если уже известна). */
    public ?Request $linkedRequest = null;

    public function __construct(public readonly EmailMessage $message)
    {
    }
}
