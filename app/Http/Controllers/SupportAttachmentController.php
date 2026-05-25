<?php

namespace App\Http\Controllers;

use App\Models\SupportTicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportAttachmentController extends Controller
{
    public function download(SupportTicketAttachment $attachment, Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Скачать может: автор тикета или admin.
        $ticket = $attachment->ticket;
        $allowed = $user->id === $ticket->user_id || $user->hasRole('admin');
        abort_unless($allowed, 403);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->file_path), 404);

        return $disk->download($attachment->file_path, $attachment->original_name);
    }
}
