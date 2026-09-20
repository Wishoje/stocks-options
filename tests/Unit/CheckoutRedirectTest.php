<?php

namespace Tests\Unit;

use App\Support\CheckoutRedirect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Checkout;
use Stripe\Checkout\Session;
use Tests\TestCase;

class CheckoutRedirectTest extends TestCase
{
    public function test_inertia_checkout_navigation_becomes_an_external_location_response(): void
    {
        $checkout = new Checkout(null, Session::constructFrom([
            'id' => 'cs_test',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test',
        ]));
        Route::get('/_test/inertia-checkout-redirect', function (Request $request) use ($checkout) {
            return CheckoutRedirect::response($request, $checkout);
        });

        $response = $this
            ->withHeader('X-Inertia', 'true')
            ->get('/_test/inertia-checkout-redirect');

        $response->assertStatus(409);
        $this->assertSame(
            'https://checkout.stripe.com/c/pay/cs_test',
            $response->headers->get('X-Inertia-Location'),
        );
    }

    public function test_document_checkout_navigation_keeps_cashiers_normal_redirect_contract(): void
    {
        $checkout = new Checkout(null, Session::constructFrom([
            'id' => 'cs_test',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test',
        ]));
        $request = Request::create('/checkout');

        $this->assertSame($checkout, CheckoutRedirect::response($request, $checkout));
        $this->assertSame(303, $checkout->toResponse($request)->getStatusCode());
    }
}
