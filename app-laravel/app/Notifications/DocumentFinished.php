<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the creator when a document is completed or rejected. */
final class DocumentFinished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Document $document) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rejected = $this->document->status === DocumentStatus::Rejected;

        return (new MailMessage)
            ->subject($rejected ? 'Dokumen ditolak' : 'Dokumen selesai ditandatangani')
            ->line($rejected
                ? "Dokumen \"{$this->document->title}\" ditolak oleh salah satu penanda tangan."
                : "Semua penanda tangan telah menandatangani \"{$this->document->title}\".")
            ->action('Buka dokumen', route('documents.show', $this->document));
    }
}
