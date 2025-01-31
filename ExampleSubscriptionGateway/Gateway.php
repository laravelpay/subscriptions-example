<?php

namespace App\Gateways\ExampleSubscriptionGateway;

use LaraPay\Framework\Interfaces\SubscriptionGateway;
use Illuminate\Support\Facades\Http;
use LaraPay\Framework\Subscription;
use Illuminate\Http\Request;

class Gateway extends SubscriptionGateway
{
    /**
     * Unique identifier for the gateway.
     */
    protected string $identifier = 'example-subscription-gateway';

    /**
     * Version of the gateway.
     */
    protected string $version = '1.0.0';

    /**
     * The currencies supported by the gateway.
     */
    protected array $currencies = [
        'USD',
        'EUR',
        // etc...
    ];

    /**
     * Define the config fields required for the gateway.
     *
     * These values can be retrieved using $subscription->gateway->config('key')
     */
    public function config(): array
    {
        return [
            'mode' => [
                'label'       => 'Mode (Sandbox/Live)',
                'description' => 'Select sandbox for testing or live for production',
                'type'        => 'select',
                'options'     => ['sandbox' => 'Sandbox', 'live' => 'Live'],
                'rules'       => ['required'],
            ],
            'client_id' => [
                'label'       => 'Client ID',
                'description' => 'Example Client ID for the gateway',
                'type'        => 'text',
                'rules'       => ['required', 'string'],
            ],
            'client_secret' => [
                'label'       => 'Client Secret',
                'description' => 'Example Client Secret for the gateway',
                'type'        => 'text',
                'rules'       => ['required', 'string'],
            ],
            // add more fields as needed
        ];
    }

    /**
     * Main entry point for creating a subscription and redirecting to the gateway.
     *
     *  In this method, start the subscription process by creating a subscription on the gateway.
     */
    public function subscribe($subscription)
    {
        $clientSecret = $subscription->gateway->config('client_secret');

        // for example, we might call an API to create a subscription
        $response = Http::withToken($clientSecret)->post('https://example.app/api/v1/subscriptions/create', [
            'name' => $subscription->name,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'custom_id' => $subscription->id,
            'interval' => [
                'period' => 'day',
                'frequency' => $subscription->frequency, // this method returns the frequency in days
            ],

            'success_url' => $subscription->successUrl(),
            'cancel_url' => $subscription->cancelUrl(),
            'webhook_url' => $subscription->webhookUrl(), // this method listens on the calllback() method
        ]);

        // if something goes wrong, throw an exception
        if ($response->failed()) {
            throw new \Exception('Failed to create subscription');
        }

        // store the subscription id locally for future reference
        $subscription->update([
            'subscription_id' => $response['subscription_id'],
        ]);

        // redirect the user to the gateway's checkout page
        return redirect()->away($response['redirect_url']);
    }

    /**
     * Handle the callback from the gateway.
     */
    public function callback(Request $request)
    {
        // listen for the webhook and update the subscription status
        if($request->has('subscription_id')) {
            $subscription = Subscription::where('subscription_id', $request->subscription_id)->first();

            if ($subscription) {
                $subscription->activate();
            }
        }
    }

    /**
     * Check if a subscription is still ACTIVE on the gateway.
     *
     * This method is called every 12 hours to check the status of the subscription.
     *
     */
    public function checkSubscription($subscription): bool
    {
        $clientSecret = $subscription->gateway->config('client_secret');

        $response = Http::withToken($clientSecret)->get('https://example.app/api/v1/subscriptions/'.$subscription->subscription_id);

        if ($response->failed()) {
            return false;
        }

        return $response['status'] === 'active';
    }
}