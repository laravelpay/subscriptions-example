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

            'success_url' => $subscription->callbackUrl(), // this returns the user to callback() method
            'cancel_url' => $subscription->cancelUrl(),
            'webhook_url' => $subscription->webhookUrl(), // this method listens on the webhook() method
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
        // The user gets redirected back from the gateway after completing their payment
        // since we used $subscription->callbackUrl(), 'subscription_token' was injected in the 
        // url query so we can retrieve it, make api call to gateway to check if the payment was completed

        $subscription = Subscription::where('token', $request->get('token'))->first();

        if($subscription) {
            throw new \Exception('Subscription not found');
        }

        $response = Http::get("https://example.app/api/v1/subscriptions/{$subscription->subscription_id}");

        if($response->failed()) {
            throw new \Exception('Failed to retrieve subscription');
        }

        if($response['status'] == 'active') {
            $subscription->activate($response['id'], $response);
        }

        return redirect($subscription->callbackUrl());
    }

    /**
     * Handle the webhook from the gateway.
     */
    public function webhook(Request $request)
    {
        // listen for the webhook and update the subscription status
        $event = $request->get('event');
        $customId = $request->get('custom_id');
        $subscriptionId = $request->get('id');
        $subscriptionData = $request->all();

        if($event == 'SUBSCRIPTION.ACTIVATED') {
            $subscription = Subscription::find($customId);
            $subscription->activate($subscriptionId, $subscriptionData)
        }

        return response()->json(['success'], 200);
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

    /**
     * Cancels a subscription using the gateway's API
     *     
     */
    public function cancelSubscription($subscription): bool
    {
        $clientSecret = $subscription->gateway->config('client_secret');

        $response = Http::withToken($clientSecret)->get('https://example.app/api/v1/subscriptions/'.$subscription->subscription_id.'/cancel');

        if ($response->failed()) {
            return false;
        }

        return true;
    }
}
