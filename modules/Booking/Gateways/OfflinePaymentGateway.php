<?php
namespace Modules\Booking\Gateways;

use Illuminate\Http\Request;
use Modules\Booking\Events\BookingCreatedEvent;

class OfflinePaymentGateway extends BaseGateway
{
    public $name = 'Offline Payment';
    public $is_offline =  true;

    public function process(Request $request, $booking, $service)
    {
        $service->beforePaymentProcess($booking, $this);
        // Simple change status to processing
        $booking->markAsProcessing($this, $service);
        $booking->sendNewBookingEmails();

        event(new BookingCreatedEvent($booking));

        $service->afterPaymentProcess($booking, $this);
        return response()->json([
            'url' => $booking->getDetailUrl()
        ])->send();
    }

    public function getOptionsConfigs()
    {
        return [
            [
                'type'  => 'checkbox',
                'id'    => 'enable',
                'label' => __('Enable Offline Payment?')
            ],
            [
                'type'  => 'input',
                'id'    => 'name',
                'label' => __('Custom Name'),
                'std'   => __("Offline Payment"),
                'multi_lang' => "1"
            ],
            [
                'type'  => 'upload',
                'id'    => 'logo_id',
                'label' => __('Custom Logo'),
            ],
            [
                'type'  => 'editor',
                'id'    => 'html',
                'label' => __('Custom HTML Description'),
                'multi_lang' => "1"
            ],
        ];
    }
}
