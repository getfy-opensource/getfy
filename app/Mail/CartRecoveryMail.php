<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CartRecoveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $htmlBody
    ) {
        $this->subject($this->subjectLine);
    }

    public function build()
    {
        return $this->html($this->htmlBody);
    }
}

