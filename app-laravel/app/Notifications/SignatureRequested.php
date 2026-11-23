<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Link only, never the PDF (ADR-0008). */
final class SignatureRequested extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject('Dokumen menunggu tanda tangan Anda')
            ->line("Dokumen \"{$this->document->title}\" menunggu tanda tangan Anda.")
            ->action('Buka dokumen', route('documents.show', $this->document))
            ->line('Jika Anda tidak mengenali dokumen ini, jangan tandatangani dan hubungi admin.');
    }
}
