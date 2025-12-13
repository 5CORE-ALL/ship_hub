<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MultipleSkusOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public $orderNumber;
    public $skus;

    /**
     * Create a new message instance.
     *
     * @param string $orderNumber
     * @param array $skus
     */
    public function __construct($orderNumber, $skus)
    {
        $this->orderNumber = $orderNumber;
        $this->skus = $skus;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->from('software10@5core.com', '5Core Management')
                    ->subject("Order #{$this->orderNumber} - Multiple SKUs")
                    ->markdown('emails.multiple_skus');
    }
}
