<?php
/**
 * Created by PhpStorm.
 * User: dunglinh
 * Date: 6/4/19
 * Time: 20:49
 */

namespace Modules\Agencies\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Agencies\Models\BravoContactObject;

class AgentNotificationToAdmin extends Mailable
{
    use Queueable, SerializesModels;
    public $contactObject;
    public function __construct(BravoContactObject $contactObject)
    {
        $this->contactObject = $contactObject;
    }

    public function build()
    {

        return $this->subject(__('[:site_name] New message',['site_name'=>setting_item('site_title')]))->view('Agencies::emails.notification')->with([
            'contact' => $this->contactObject,
        ]);
    }
}
