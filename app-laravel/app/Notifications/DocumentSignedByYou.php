<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Document;
use App\Models\DocumentSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Receipt to the signer after every signature: mitigation for the known limitation that a TOTP
 * code is not bound to a specific document (docs/signing-contract.md).
 */
final class DocumentSignedByYou extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Document $document, public readonly DocumentSigner $step) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $at = $this->step->signed_at?->timezone(config('app.timezone'))->format('d-m-Y H:i');

        return (new MailMessage)
            ->subject('Anda telah menandatangani dokumen')
            ->line("Anda menandatangani \"{$this->document->title}\" pada {$at}.")
            ->action('Lihat dokumen', route('documents.show', $this->document))
            ->line('Jika ini bukan Anda, segera hubungi admin untuk mencabut sertifikat Anda.');
    }
}
