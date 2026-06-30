<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email avisando o responsável do projeto que um telefone caiu/foi bloqueado.
 * Cobre dois casos: failover (trocou para um backup) e sem-backup (projeto sem telefone).
 */
class FailoverAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Project $project,
        public ?string $fromSlug,
        public ?string $toSlug,
        public string $reason,
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = $this->toSlug
            ? "[wp-gorila] Failover no projeto {$this->project->name}: telefone trocado"
            : "[wp-gorila] ALERTA: projeto {$this->project->name} sem telefone disponível";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.failover');
    }
}
