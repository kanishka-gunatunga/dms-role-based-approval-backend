<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendDocument extends Mailable
{
    use Queueable, SerializesModels;

    public $details;

    /**
     * Create a new message instance.
     *
     * @param array $details
     */
    public function __construct($details)
    {
        $this->details = $details;
    }

    /**
     * Build the message.
     *
     * @return $this
     */

    public function build()

    {

        $fileContents = file_get_contents($this->details['attachment']);
        $fileName = basename(parse_url($this->details['attachment'], PHP_URL_PATH)); 
    
        return $this->subject($this->details['subject'])
                    ->view('email_templates.send_document')
                    ->attachData($fileContents, $fileName);

    }

}

