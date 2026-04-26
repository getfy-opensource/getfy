<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccessDeliveryReady
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array{type?:string, link?:string, email?:string, password?:string, product_type?:string}  $access
     */
    public function __construct(
        public Order $order,
        public array $access = [],
    ) {}
}

